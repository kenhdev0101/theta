<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use App\Models\ConvexProbeRun;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use App\Robinhood\Thesis\ThesisSpec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/** Machine-owned convex entry and stewardship, with one durable run per ADMIT row. */
final class ConvexAutoLoop
{
    private readonly ConvexProbeLifecycle $lifecycle;

    public function __construct(RobinhoodMcpClient $client, private readonly array $cfg, JsonlRecorder $recorder)
    {
        $this->lifecycle = new ConvexProbeLifecycle($client, $cfg, $recorder);
    }

    /** @return list<array<string,string>> */
    public function run(): array
    {
        $release = ConvexLoopLock::acquire();
        if ($release === null) {
            return [['event' => 'busy']];
        }
        try {
            $path = (string) data_get($this->cfg, 'convex.matrix_path', storage_path('harness/convex_feature_matrix.csv'));
            $latest = (new ConvexMatrixSnapshot($path))->latest();
            $events = [];
            $active = ConvexProbeRun::query()
                ->whereIn('status', ['entry_pending', 'held', 'exit_pending', 'ambiguous'])->orderBy('id')->get();
            foreach ($active as $run) {
                try {
                    $newExpiry = false;
                    $matching = $latest[$this->runKey($run)] ?? null;
                    if ($matching !== null && $run->thesis_expired_at === null
                        && data_get($run->spec, 'legs.0.invalidation.thesis_stop') === 'external_event:thesis_expired'
                        && is_string(data_get($run->spec, 'matrix_source.row_sha256'))
                        && $matching['snapshot']->greaterThan($run->entry_submitted_at ?? $run->created_at)) {
                        $failures = ConvexMatrixSnapshot::structuralFailures($matching['row']);
                        if ($failures !== []) {
                            $run = $this->lifecycle->expire(
                                $run, 'matrix-'.substr($matching['hash'], 0, 32),
                                'convex_matrix_transition', implode('|', $failures),
                            );
                            $newExpiry = true;
                            $events[] = ['event' => 'thesis_expired', 'thesis_id' => $run->thesis_id];
                        }
                    }
                    $tickSeconds = $run->status === 'held' ? 30 : 20;
                    if (! $newExpiry && ! Cache::add('theta:convex-auto-tick:'.$run->id, 1, $tickSeconds)) {
                        continue;
                    }
                    $updated = $this->lifecycle->tick($run);
                    $events[] = ['event' => 'stewarded', 'thesis_id' => $updated->thesis_id, 'status' => $updated->status];
                } catch (Throwable $e) {
                    $events[] = ['event' => 'stewardship_blocked', 'thesis_id' => $run->thesis_id, 'reason' => $e->getMessage()];
                }
            }

            if (! (bool) data_get($this->cfg, 'convex.auto_entry_enabled', false)
                || (bool) data_get($this->cfg, 'kill_switch', true) || ! $this->entryHours()) {
                return $events;
            }
            $tickers = array_map('strtoupper', (array) data_get($this->cfg, 'convex.tickers', []));
            foreach ($latest as $item) {
                $row = $item['row'];
                $ticker = (string) $row['ticker'];
                $dte = (int) $row['dte'];
                if (! in_array($ticker, $tickers, true) || ($row['bucket'] ?? '') !== (string) data_get($this->cfg, 'convex.bucket', 'index')
                    || ($row['verdict'] ?? '') !== 'ADMIT' || ($row['failed_signals'] ?? '') !== ''
                    || ($row['pending_signals'] ?? '') !== '' || ! is_numeric($row['far_delta'] ?? null)) {
                    continue;
                }
                $target = abs((float) $row['far_delta']);
                if ($target <= 0 || $target >= 1 || $dte < 1 || $this->enteredToday($ticker, $dte)) {
                    continue;
                }
                $thesisId = 'convex-auto-'.substr($item['hash'], 0, 32);
                $run = ConvexProbeRun::query()->where('thesis_id', $thesisId)->first();
                if ($run === null) {
                    try {
                        $run = $this->lifecycle->stage($this->spec($thesisId, $ticker, $dte, $target, $item['hash']));
                        $events[] = ['event' => 'staged', 'thesis_id' => $run->thesis_id];
                    } catch (Throwable $e) {
                        $events[] = ['event' => 'admission_blocked', 'thesis_id' => $thesisId, 'reason' => $e->getMessage()];

                        continue;
                    }
                }
                if ($run->status !== 'staged') {
                    continue;
                }
                // A blocked quote or wallet can improve without a new matrix row.
                // Throttle broker preflight to once a minute per immutable row.
                if (! Cache::add('theta:convex-auto-attempt:'.$thesisId, 1, 60)) {
                    continue;
                }
                try {
                    $updated = $this->lifecycle->enter($run);
                    $events[] = ['event' => 'entry_checked', 'thesis_id' => $updated->thesis_id, 'status' => $updated->status];
                } catch (Throwable $e) {
                    $events[] = ['event' => 'entry_blocked', 'thesis_id' => $thesisId, 'reason' => $e->getMessage()];
                }
            }

            return $events;
        } finally {
            $release();
        }
    }

    private function runKey(ConvexProbeRun $run): string
    {
        $dte = $run->expiration_date !== null
            ? (int) Carbon::now('America/New_York')->startOfDay()->diffInDays(
                Carbon::parse($run->expiration_date->toDateString(), 'America/New_York')->startOfDay(), false,
            )
            : (int) data_get($run->spec, 'legs.0.dte.min', 0);

        return ConvexMatrixSnapshot::key($run->underlying_symbol, $dte);
    }

    private function enteredToday(string $ticker, int $dte): bool
    {
        $runs = ConvexProbeRun::query()->where('underlying_symbol', $ticker)
            ->where('thesis_id', 'like', 'convex-auto-%')
            ->whereNotNull('entry_submitted_at')
            ->whereDate('created_at', Carbon::now('America/New_York')->toDateString())->get();
        foreach ($runs as $run) {
            if ((int) data_get($run->spec, 'legs.0.dte.min', 0) === $dte) {
                return true;
            }
        }

        return false;
    }

    private function entryHours(): bool
    {
        $now = Carbon::now('America/New_York');

        return $now->isWeekday() && $now->format('H:i') >= '09:30' && $now->format('H:i') < '15:45';
    }

    private function spec(string $thesisId, string $ticker, int $dte, float $target, string $hash): ThesisSpec
    {
        $hold = (int) data_get($this->cfg, 'convex.auto_max_hold_minutes', 5);
        if ($hold < 1 || $hold > 30) {
            throw new RuntimeException('convex auto hold must be 1–30 minutes');
        }

        return ThesisSpec::fromArray([
            'spec_version' => '1', 'thesis_id' => $thesisId, 'owner' => 'theta', 'agent_id' => 'theta/convex-auto',
            'thesis' => 'Machine admitted convex call probe from sealed matrix row '.$hash,
            'matrix_source' => ['row_sha256' => $hash],
            'legs' => [[
                'leg_id' => 'near-delta-call', 'role' => 'probe', 'asset_class' => 'option', 'symbol' => $ticker,
                'direction' => 'long', 'structure' => 'single_leg_long_call',
                'dte' => ['min' => $dte, 'max' => $dte],
                'moneyness' => ['mode' => 'delta', 'target' => $target],
                'sizing' => ['mode' => 'balance_fraction', 'fraction' => 1.0],
                'entry' => ['type' => 'marketable_limit'],
                'invalidation' => [
                    'thesis_stop' => 'external_event:thesis_expired',
                    'time_stop' => Carbon::now('America/New_York')->addDay()->toDateString(),
                ],
                'fast_exit' => ['max_hold_minutes' => $hold, 'profit_target_mult' => 1.30, 'hard_stop_pct' => 30],
            ]],
        ]);
    }
}
