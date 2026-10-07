<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Models\ConvexProbeRun;
use App\Robinhood\Convex\ConvexProbeLifecycle;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use App\Robinhood\Thesis\ThesisSpec;
use Illuminate\Console\Command;

final class ConvexProbeCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:convex-probe
        {action : stage|enter|tick|exit|expire|status}
        {--spec= : JSON thesis spec file for stage}
        {--thesis= : Thesis ID for enter, tick, exit, expire, or status}
        {--option-id= : Frozen broker option ID for expire}
        {--event-id= : Immutable external event ID for expire}
        {--source= : External producer of thesis_expired}
        {--reason= : Evidence summary for thesis_expired}';

    protected $description = 'Stage and supervise one thesis-spec convexity option probe through broker-confirmed closure';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['stage', 'enter', 'tick', 'exit', 'expire', 'status'], true)) {
            $this->error('action must be stage|enter|tick|exit|expire|status');

            return self::INVALID;
        }
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);
        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);
        $lifecycle = new ConvexProbeLifecycle($client, $cfg, $recorder);

        try {
            if ($action === 'stage') {
                $path = (string) $this->option('spec');
                if ($path === '' || ! is_file($path)) {
                    $this->error('stage requires --spec=<existing JSON file>');

                    return self::INVALID;
                }
                $run = $lifecycle->stage(ThesisSpec::fromJson((string) file_get_contents($path)));
                $this->show($run);

                return self::SUCCESS;
            }

            $thesisId = (string) $this->option('thesis');
            if ($thesisId === '' && in_array($action, ['enter', 'exit', 'expire'], true)) {
                $this->error($action.' requires --thesis=<id>');

                return self::INVALID;
            }
            if ($action === 'status') {
                $runs = $thesisId !== ''
                    ? ConvexProbeRun::query()->where('thesis_id', $thesisId)->get()
                    : ConvexProbeRun::query()->latest('id')->limit(20)->get();
                foreach ($runs as $run) {
                    $this->show($run);
                }

                return self::SUCCESS;
            }
            if ($action === 'expire') {
                $run = ConvexProbeRun::query()->where('thesis_id', $thesisId)->first();
                if (! $run instanceof ConvexProbeRun) {
                    $this->error('unknown thesis_id');

                    return self::INVALID;
                }
                if ($run->status !== 'staged' && (string) $this->option('option-id') !== (string) $run->option_id) {
                    $this->error('thesis_expired option_id does not match frozen broker contract');

                    return self::INVALID;
                }
                $recorded = $lifecycle->expire(
                    $run, (string) $this->option('event-id'),
                    (string) $this->option('source'), (string) $this->option('reason'),
                );
                if ($recorded->status === 'closed') {
                    $this->show($recorded);

                    return self::SUCCESS;
                }
                if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
                    $this->show($recorded);
                    $this->error('thesis_expired persisted; broker OAuth grant unavailable for immediate liquidation');

                    return self::FAILURE;
                }
                $updated = $lifecycle->tick($recorded, forceExit: true);
                $this->show($updated);
                if ($updated->status !== 'closed' && ($updated->status !== 'exit_pending' || $updated->block_reason !== null)) {
                    $this->error('thesis_expired persisted but immediate liquidation remains blocked: '.($updated->block_reason ?? $updated->status));

                    return self::FAILURE;
                }

                return self::SUCCESS;
            }
            if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
                $this->error('broker OAuth grant unavailable');

                return self::FAILURE;
            }

            $runs = $action === 'tick' && $thesisId === ''
                ? ConvexProbeRun::query()->whereIn('status', ['entry_pending', 'held', 'exit_pending', 'ambiguous'])->get()
                : ConvexProbeRun::query()->where('thesis_id', $thesisId)->get();
            if ($runs->isEmpty() && $thesisId !== '') {
                $this->error('unknown thesis_id');

                return self::INVALID;
            }
            $failed = false;
            foreach ($runs as $run) {
                try {
                    $updated = match ($action) {
                        'enter' => $lifecycle->enter($run),
                        'exit' => $lifecycle->tick($run, forceExit: true),
                        default => $lifecycle->tick($run),
                    };
                    $this->show($updated);
                } catch (\Throwable $e) {
                    $this->error($run->thesis_id.': '.$e->getMessage());
                    $failed = true;
                }
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('convex probe mechanical blocker: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function show(ConvexProbeRun $run): void
    {
        $this->line(json_encode([
            'thesis_id' => $run->thesis_id, 'leg_id' => $run->leg_id,
            'status' => $run->status, 'option_id' => $run->option_id,
            'entry_client_ref_id' => $run->entry_client_ref_id,
            'entry_order_id' => $run->entry_order_id, 'entry_filled_qty' => $run->entry_filled_qty,
            'entry_average_price' => $run->entry_average_price,
            'exit_client_ref_id' => $run->exit_client_ref_id,
            'exit_order_id' => $run->exit_order_id, 'exit_filled_qty' => $run->exit_filled_qty,
            'exit_average_price' => $run->exit_average_price,
            'gross_pnl_before_fees' => $run->status === 'closed' && $run->entry_average_price !== null && $run->exit_average_price !== null
                ? round(((float) $run->exit_average_price - (float) $run->entry_average_price) * (float) $run->exit_filled_qty * 100, 2)
                : null,
            'exit_due_at' => $run->exit_due_at?->toIso8601String(),
            'last_reconciled_at' => $run->last_reconciled_at?->toIso8601String(),
            'thesis_expired_at' => $run->thesis_expired_at?->toIso8601String(),
            'thesis_expiry_event_id' => $run->thesis_expiry_event_id,
            'thesis_expiry_source' => $run->thesis_expiry_source,
            'exit_reason' => $run->exit_reason,
            'stewardship_label' => $run->stewardshipLabel(),
            'block_reason' => $run->block_reason,
        ], JSON_UNESCAPED_SLASHES));
    }
}
