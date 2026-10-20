<?php

declare(strict_types=1);

namespace App\Cockpit;

use App\Models\ConvexProbeRun;
use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Saved evidence only. Never construct a broker client, refresh a snapshot,
 * acquire a trading lock, or invoke a worker from this stakeholder surface.
 */
final class ConvexTelemetryData
{
    private const ACTIVE = ['entry_pending', 'held', 'exit_pending', 'ambiguous'];

    private array $warnings = [];

    public function __construct(private readonly array $cfg) {}

    public function payload(): array
    {
        $now = Carbon::now();
        $tickers = array_values(array_filter((array) data_get($this->cfg, 'convex.tickers', []),
            fn ($s) => is_string($s) && preg_match('/^[A-Z0-9.]{1,12}$/', $s)));
        $ingest = $this->events('convex_ingest');
        $broker = $this->events('mcp_calls');
        $probe = $this->events('convex_probe');
        $runs = $this->runs($now);
        $signals = $this->signals($tickers, $ingest);

        return [
            'generated_at' => $now->toIso8601ZuluString(),
            'session_date' => $now->copy()->setTimezone('America/New_York')->toDateString(),
            'posture' => [
                'ingest_enabled' => (bool) data_get($this->cfg, 'convex.enabled', false),
                'entry_enabled' => (bool) data_get($this->cfg, 'convex.auto_entry_enabled', false),
                'kill_switch' => (bool) ($this->cfg['kill_switch'] ?? true),
                'entry_window' => '09:30–15:45 New York · weekdays; broker rails still apply',
                'caps' => [
                    'per_play' => $this->number(data_get($this->cfg, 'options.per_play_max_debit')),
                    'per_day' => $this->number(data_get($this->cfg, 'options.per_day_max_debit')),
                    'per_underlying' => $this->number(data_get($this->cfg, 'options.per_underlying_max_debit')),
                ],
            ],
            'auth' => $this->auth(),
            'broker' => $this->broker($broker, $now),
            'signals' => $signals,
            'runs' => $runs['rows'],
            'summary' => $runs['summary'] + [
                'configured_tickers' => count($tickers),
                'fresh_tickers' => count(array_filter($signals, fn ($s) => $s['fresh'])),
                'last_ingest_at' => $ingest === [] ? null : $ingest[0]['ts'],
            ],
            'timeline' => $this->timeline($probe, $runs['rows']),
            'warnings' => array_values(array_unique($this->warnings)),
            'evidence_note' => 'Saved worker evidence, not a live broker reconciliation. No broker requests are made by this page. A fresh signal is not approval to trade.',
        ];
    }

    private function signals(array $tickers, array $ingest): array
    {
        $path = (string) data_get($this->cfg, 'convex.matrix_path', storage_path('harness/convex_feature_matrix.csv'));
        $latest = [];
        if (is_file($path)) {
            $h = @fopen($path, 'rb');
            if ($h !== false) {
                // Nonblocking shared lock: UI readers must not stall ingestion.
                if (flock($h, LOCK_SH | LOCK_NB)) {
                    try {
                        $header = fgetcsv($h, escape: '');
                        $required = ['ticker', 'dte', 'snapshot_ts', 'verdict', 'failed_signals', 'pending_signals', 'far_delta'];
                        if (! is_array($header) || array_diff($required, $header) !== []) {
                            $this->warnings[] = 'The saved signal matrix has an unreadable header.';
                        } else {
                            $size = fstat($h)['size'];
                            if ($size > 1048576) {
                                fseek($h, -1048576, SEEK_END);
                                fgets($h);
                                $this->warnings[] = 'Signal history is limited to the most recent 1 MiB.';
                            }
                            while (($cells = fgetcsv($h, escape: '')) !== false) {
                                if (count($cells) !== count($header)) {
                                    continue;
                                }
                                $r = array_combine($header, $cells);
                                $stamp = $this->timestamp($r['snapshot_ts']);
                                if (! in_array($r['ticker'], $tickers, true) || ! ctype_digit($r['dte'])
                                    || $stamp === null || $stamp->isFuture()
                                    || ! in_array($r['verdict'], ['ADMIT', 'REJECT', 'PENDING'], true)
                                    || (isset($r['bucket']) && $r['bucket'] !== (string) data_get($this->cfg, 'convex.bucket', 'index'))) {
                                    continue;
                                }
                                $key = $r['ticker'].':'.$r['dte'];
                                if (isset($latest[$key]) && $latest[$key]['stamp']->greaterThan($stamp)) {
                                    continue;
                                }
                                $age = max(0, (int) $stamp->diffInSeconds(now(), false));
                                $delta = $this->number($r['far_delta']);
                                $latest[$key] = [
                                    'stamp' => $stamp, 'symbol' => $r['ticker'], 'dte' => (int) $r['dte'],
                                    'as_of' => $stamp->toIso8601ZuluString(), 'age_seconds' => $age,
                                    'fresh' => $age <= 900, 'verdict' => $r['verdict'],
                                    'delta' => $delta,
                                    'admissible' => $age <= 900 && $r['verdict'] === 'ADMIT'
                                        && $r['failed_signals'] === '' && $r['pending_signals'] === ''
                                        && $delta !== null && abs($delta) > 0 && abs($delta) < 1,
                                    'reasons' => $this->signalReasons($r['failed_signals'], $r['pending_signals']),
                                ];
                            }
                        }
                    } finally {
                        flock($h, LOCK_UN);
                    }
                } else {
                    $this->warnings[] = 'The signal matrix is being written; retry on the next refresh.';
                }
                fclose($h);
            } else {
                $this->warnings[] = 'The saved signal matrix is not readable.';
            }
        } else {
            $this->warnings[] = 'No saved signal matrix is available.';
        }

        $result = [];
        foreach ($tickers as $ticker) {
            $rows = array_values(array_filter($latest, fn ($r) => $r['symbol'] === $ticker));
            usort($rows, fn ($a, $b) => $b['stamp']->getTimestamp() <=> $a['stamp']->getTimestamp());
            $rows = array_slice($rows, 0, max(1, min(8, (int) data_get($this->cfg, 'convex.tenors_per_ticker', 2))));
            foreach ($rows as &$row) {
                unset($row['stamp']);
            }
            unset($row);
            $last = collect($ingest)->firstWhere('ticker', $ticker);
            $result[] = [
                'symbol' => $ticker, 'rows' => $rows,
                'fresh' => count(array_filter($rows, fn ($r) => $r['fresh'])) > 0,
                'last_attempt_at' => $last['ts'] ?? null,
                'last_attempt' => $last === null ? 'No recorded attempt' : (($last['event'] ?? '') === 'feature_row'
                    ? 'Signal observation recorded' : $this->failure($last['detail'] ?? '', $last['reason'] ?? '')),
            ];
        }

        return $result;
    }

    private function runs(Carbon $now): array
    {
        $empty = ['rows' => [], 'summary' => [
            'active_runs' => null, 'held_contracts' => null, 'open_premium_at_cost' => null,
            'gross_realized_today' => null, 'pnl_pending_runs' => null,
            'closed_filled_today' => null, 'submissions_today' => null, 'last_stewardship_at' => null,
        ]];
        if (empty($this->cfg['agentic_account'])) {
            $this->warnings[] = 'Account pin is unavailable; run evidence is hidden.';

            return $empty;
        }
        try {
            $base = ConvexProbeRun::query()->where('account_number', $this->cfg['agentic_account']);
            $columns = ['id', 'thesis_id', 'status', 'underlying_symbol', 'expiration_date', 'strike_price',
                'entry_submitted_at', 'entry_limit_price', 'entry_filled_qty', 'entry_average_price',
                'entry_cancel_requested_at', 'entry_cancel_reason', 'exit_submitted_at',
                'exit_filled_qty', 'exit_average_price', 'opened_at', 'closed_at', 'last_reconciled_at',
                'block_reason', 'thesis_expired_at', 'exit_reason', 'created_at', 'updated_at'];
            $active = (clone $base)->whereIn('status', self::ACTIVE)->get($columns);
            $recent = (clone $base)->orderByDesc('id')->limit(40)->get($columns);
            $submitted = (clone $base)->where('entry_submitted_at', '>=', $now->copy()->subDay())->get($columns);
            $selected = $active->merge($recent)->merge($submitted)->unique('id')->sortByDesc('id');
            $start = $now->copy()->setTimezone('America/New_York')->startOfDay()->utc();
            $end = $start->copy()->setTimezone('America/New_York')->addDay()->utc();
            $closed = (clone $base)->where('status', 'closed')->where('closed_at', '>=', $start)
                ->where('closed_at', '<', $end)->where('entry_filled_qty', '>', 0)->get($columns);
            $gross = 0.0;
            $pending = 0;
            foreach ($closed as $r) {
                $pnl = $this->grossPnl($r);
                if ($pnl === null) {
                    $pending++;
                } else {
                    $gross += $pnl;
                }
            }
            $quantity = 0.0;
            $cost = 0.0;
            $costKnown = true;
            foreach ($active as $r) {
                $remaining = max(0, (float) $r->entry_filled_qty - (float) $r->exit_filled_qty);
                $quantity += $remaining;
                if ($remaining > 0 && ! is_numeric($r->entry_average_price)) {
                    $costKnown = false;
                }
                $cost += $remaining * (float) $r->entry_average_price * 100;
            }
            $last = (clone $base)->max('last_reconciled_at');

            return [
                'rows' => $selected->map(fn ($r) => [
                    'id' => $r->id, 'thesis_id' => $r->thesis_id,
                    'symbol' => $r->underlying_symbol, 'status' => $r->status,
                    'expiration' => $r->expiration_date?->toDateString(),
                    'strike' => $this->number($r->strike_price),
                    'entry_limit' => $this->number($r->entry_limit_price),
                    'entry_filled' => (float) $r->entry_filled_qty,
                    'exit_filled' => (float) $r->exit_filled_qty,
                    'entry_average' => $this->number($r->entry_average_price),
                    'exit_average' => $this->number($r->exit_average_price),
                    'gross_pnl' => $this->grossPnl($r),
                    'blocker' => $this->reason($r->block_reason),
                    'cancel_reason' => $this->reason($r->entry_cancel_reason),
                    'exit_reason' => $this->reason($r->exit_reason),
                    'created_at' => $r->created_at?->toIso8601ZuluString(),
                    'updated_at' => $r->updated_at?->toIso8601ZuluString(),
                    'submitted_at' => $r->entry_submitted_at?->toIso8601ZuluString(),
                    'cancel_requested_at' => $r->entry_cancel_requested_at?->toIso8601ZuluString(),
                    'opened_at' => $r->opened_at?->toIso8601ZuluString(),
                    'expired_at' => $r->thesis_expired_at?->toIso8601ZuluString(),
                    'exit_submitted_at' => $r->exit_submitted_at?->toIso8601ZuluString(),
                    'closed_at' => $r->closed_at?->toIso8601ZuluString(),
                    'reconciled_at' => $r->last_reconciled_at?->toIso8601ZuluString(),
                ])->values()->all(),
                'summary' => [
                    'active_runs' => $active->count(), 'held_contracts' => $quantity,
                    'open_premium_at_cost' => $costKnown ? round($cost, 2) : null,
                    'gross_realized_today' => $pending === 0 ? round($gross, 2) : null,
                    'pnl_pending_runs' => $pending, 'closed_filled_today' => $closed->count(),
                    'submissions_today' => (clone $base)->where('entry_submitted_at', '>=', $start)
                        ->where('entry_submitted_at', '<', $end)->count(),
                    'last_stewardship_at' => $last === null ? null : Carbon::parse($last)->toIso8601ZuluString(),
                ],
            ];
        } catch (Throwable) {
            $this->warnings[] = 'Run storage is unavailable; exposure and P&L are unknown.';

            return $empty;
        }
    }

    private function grossPnl(ConvexProbeRun $r): ?float
    {
        if ($r->status !== 'closed' || (float) $r->entry_filled_qty <= 0
            || (float) $r->exit_filled_qty !== (float) $r->entry_filled_qty
            || ! is_numeric($r->entry_average_price) || ! is_numeric($r->exit_average_price)) {
            return null;
        }

        return round(((float) $r->exit_average_price - (float) $r->entry_average_price) * (float) $r->entry_filled_qty * 100, 2);
    }

    /** Tail bounded files; never return raw event payloads to a browser. */
    private function events(string $stream): array
    {
        $dir = (string) data_get($this->cfg, 'telemetry.dir', storage_path('harness'));
        $events = [];
        foreach ([now()->utc()->subDay()->toDateString(), now()->utc()->toDateString()] as $date) {
            $path = $dir.'/'.$stream.'-'.$date.'.jsonl';
            if (! is_file($path)) {
                continue;
            }
            $h = @fopen($path, 'rb');
            if ($h === false) {
                $this->warnings[] = 'Some saved telemetry is unreadable.';

                continue;
            }
            try {
                if (fstat($h)['size'] > 524288) {
                    fseek($h, -524288, SEEK_END);
                    fgets($h);
                    $this->warnings[] = 'Event history is bounded; counts describe retained observations.';
                }
                while (($line = fgets($h)) !== false) {
                    $r = json_decode($line, true);
                    $stamp = is_array($r) ? $this->timestamp($r['ts'] ?? null) : null;
                    if ($stamp === null || $stamp->isFuture() || $stamp->lt(now()->subDay())) {
                        continue;
                    }
                    $r['ts'] = $stamp->toIso8601ZuluString('microsecond');
                    $events[] = $r;
                }
            } finally {
                fclose($h);
            }
        }
        usort($events, fn ($a, $b) => strcmp($b['ts'], $a['ts']));

        return $events;
    }

    private function broker(array $events, Carbon $now): array
    {
        $recent = array_values(array_filter($events, fn ($r) => $this->timestamp($r['ts'])->gte($now->copy()->subMinutes(15))));
        $failures = [];
        foreach ($recent as $r) {
            if (($r['ok'] ?? null) === false) {
                $label = $this->failure($r['error'] ?? '');
                $failures[$label] = ($failures[$label] ?? 0) + 1;
            }
        }
        arsort($failures);
        $used = null;
        try {
            $key = (string) data_get($this->cfg, 'broker_credits.cache_key', 'theta:robinhood:mcp:credits');
            $value = Cache::get($key.':'.$now->format('YmdHi'), 0);
            $used = is_numeric($value) && (float) $value >= 0 ? (int) $value : null;
        } catch (Throwable) {
            $this->warnings[] = 'The broker request ledger is unavailable.';
        }
        $success = collect($events)->first(fn ($r) => ($r['ok'] ?? null) === true);

        return [
            'used_this_minute' => $used,
            'limit_per_minute' => (int) data_get($this->cfg, 'broker_credits.max_per_minute', 45),
            'reserved_for_stewardship' => (int) data_get($this->cfg, 'broker_credits.reserve_for_stewardship', 0),
            'limiter_enabled' => (bool) data_get($this->cfg, 'broker_credits.enabled', true),
            'observed_calls_15m' => count($recent), 'failed_calls_15m' => array_sum($failures),
            'failure_reasons' => $failures, 'last_success_at' => $success['ts'] ?? null,
        ];
    }

    private function auth(): array
    {
        try {
            $g = OAuthTokenStore::fromConfig($this->cfg)->get();
            if (! is_array($g) || empty($g['access_token'])) {
                return ['state' => 'No stored grant', 'expires_at' => null];
            }
            $expires = $this->timestamp($g['expires_at'] ?? null);

            return [
                'state' => $expires === null ? 'Expiry unknown' : ($expires->isPast() ? 'Expired' : 'Grant unexpired'),
                'expires_at' => $expires?->toIso8601ZuluString(),
            ];
        } catch (Throwable) {
            return ['state' => 'Grant unreadable', 'expires_at' => null];
        }
    }

    private function timeline(array $events, array $runs): array
    {
        $known = array_column($runs, 'symbol', 'thesis_id');
        $labels = [
            'entry_submitted' => 'Entry submitted', 'entry_cancel_requested' => 'Entry cancellation requested',
            'entry_terminal_without_fill' => 'Entry closed without a fill', 'thesis_expired' => 'Thesis expired',
            'exit_submitted' => 'Exit submitted', 'closed_broker_confirmed' => 'Closure confirmed by broker',
        ];
        $out = [];
        foreach ($events as $e) {
            if (! isset($labels[$e['event'] ?? ''], $known[$e['thesis_id'] ?? ''])) {
                continue;
            }
            $out[] = [
                'at' => $e['ts'], 'symbol' => $known[$e['thesis_id']], 'label' => $labels[$e['event']],
                'reason' => $this->reason($e['entry_cancel_reason'] ?? $e['exit_reason'] ?? null),
            ];
            if (count($out) >= 25) {
                break;
            }
        }

        return $out;
    }

    private function signalReasons(string $failed, string $pending): array
    {
        $labels = [
            'conventional_smirk' => 'Surface shape', 'fringe_not_rich' => 'Wing richness',
            'quiet_surface_proxy' => 'Surface stability', 'executable_wing' => 'Execution cost / liquidity',
            'no_catalyst' => 'Catalyst coverage',
        ];
        $out = [];
        foreach (['Failed' => $failed, 'Pending' => $pending] as $state => $codes) {
            foreach (array_filter(explode('|', $codes)) as $code) {
                $out[] = $state.': '.($labels[$code] ?? 'Unrecognized gate');
            }
        }

        return $out;
    }

    /** Raw errors, order payloads, and unrecognized free text never leave the server. */
    private function reason(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $labels = [
            'entry_not_filled' => 'Entry closed without a fill',
            'resting_bid_outside_thesis_risk' => 'Bid moved outside the thesis risk budget',
            'entry_cancel_requested_await_broker_confirmation' => 'Cancellation requested; awaiting broker confirmation',
            'unresolved_probe_entry_blocks_new_entry' => 'Waiting for another probe entry to resolve',
            'unresolved_broker_option_entry_blocks_new_entry' => 'Waiting for an open broker entry to resolve',
            'settled_cash_or_buying_power_unverified' => 'Settled cash or buying power unavailable',
            'one_contract_not_fundable' => 'Available capital cannot fund one contract',
            'no_fresh_tradable_delta_contract_within_premium_cap' => 'No fresh target-delta contract within available premium',
            'convex_exit_spread_consumes_thesis_risk' => 'Quoted exit cost exceeds the thesis risk budget',
            'thesis_expired_before_entry' => 'Thesis expired before entry',
            'thesis_expired' => 'Thesis expired', 'max_hold_minutes' => 'Maximum hold time reached',
            'profit_target' => 'Profit target reached', 'hard_stop' => 'Hard stop reached',
            'entry_timeout' => 'Resting entry timed out',
        ];
        foreach ($labels as $code => $label) {
            if ($value === $code || str_starts_with($value, $code.':') || str_starts_with($value, $code.';')) {
                return $label;
            }
        }

        return 'Recorded execution blocker; engineering detail withheld';
    }

    private function failure(mixed $detail, mixed $reason = ''): string
    {
        $s = strtolower((is_string($detail) ? $detail : '').' '.(is_string($reason) ? $reason : ''));

        return match (true) {
            str_contains($s, 'credit') => 'Broker request budget exhausted',
            str_contains($s, '401'), str_contains($s, 'unauthorized') => 'Broker authentication rejected',
            str_contains($s, '429') => 'Broker rate limit',
            str_contains($s, 'timeout'), str_contains($s, 'timed out') => 'Broker request timed out',
            str_contains($s, 'spot_unresolved') => 'Underlying quote unavailable',
            default => 'Read or validation failed',
        };
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            return null;
        }
        try {
            $parsed = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();

            return $errors !== false && ($errors['warning_count'] || $errors['error_count']) ? null : Carbon::instance($parsed)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
