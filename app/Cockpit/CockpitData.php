<?php

declare(strict_types=1);

namespace App\Cockpit;

use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The cockpit view-model assembler.
 *
 * Pulls the broker-truth snapshot (cached), the local decision record
 * (journal + JSONL), and the config/credential surface, then decodes every
 * enum through the Lexicon so the JSON handed to the UI is already in plain
 * English with tones and tooltips. The UI renders; it never interprets.
 */
final class CockpitData
{
    public function __construct(
        private readonly array $cfg,
        private readonly CockpitSnapshot $snapshot,
        private readonly TelemetryReader $telemetry,
    ) {}

    public static function build(?array $cfg = null): self
    {
        $cfg = $cfg ?? (array) config('harness');

        return new self($cfg, CockpitSnapshot::fromConfig($cfg), TelemetryReader::fromConfig($cfg));
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        $snapshot = $this->snapshot->cached();

        return [
            'generated_at' => now()->toIso8601ZuluString(),
            'status_bar' => $this->statusBar($snapshot),
            'account' => $this->account($snapshot),
            'scorecard' => $this->telemetry->scorecardToday(),
            'caps' => $this->caps($snapshot),
            'positions' => $this->positions($snapshot),
            'open_orders' => $this->openOrders($snapshot),
            'decisions' => $this->decisions(),
            'why_not_trading' => $this->whyNotTrading(),
            'journal' => $this->journal(),
            'reconciliation' => $this->reconciliation($snapshot),
            'system_events' => $this->systemEvents(),
            'mcp_health' => $this->telemetry->mcpHealth(),
            'snapshot_errors' => $snapshot['errors'] ?? [],
        ];
    }

    // ── Status bar ───────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function statusBar(array $snapshot): array
    {
        $killEngaged = (bool) ($this->cfg['kill_switch'] ?? true);
        $optionsEnabled = (bool) ($this->cfg['options']['enabled'] ?? false);

        return [
            'account' => (string) ($this->cfg['agentic_account'] ?? '—'),
            'mode' => Lexicon::mode($this->cfg['mode'] ?? null),
            'kill_switch' => Lexicon::killSwitch($killEngaged),
            'options_lane' => $optionsEnabled
                ? $this->chip('Enabled', 'Single-leg long options are armed in config.', Lexicon::TONE_GOOD)
                : $this->chip('Disabled', 'The options lane is off in config.', Lexicon::TONE_NEUTRAL),
            'market' => $this->marketChip($snapshot['market'] ?? []),
            'oauth' => $this->oauthChip(),
            'broker_credits' => $this->creditsChip(),
            'data_plane' => $this->dataPlaneChip(),
            'snapshot' => $this->snapshotChip($snapshot),
        ];
    }

    /**
     * Derive the US equity session from the ET wall-clock at request time, so
     * the indicator is always live regardless of snapshot age. Holidays are
     * not modeled (same caveat as the T+1 settled-cash estimate). The broker's
     * schedule text rides along as the tooltip.
     *
     * @return array<string,mixed>
     */
    private function marketChip(array $market): array
    {
        $now = now()->setTimezone('America/New_York');
        $minutes = $now->hour * 60 + $now->minute;
        $sched = is_string($market['schedule'] ?? null) ? ('Broker schedule — '.$market['schedule']) : '';

        [$label, $desc, $tone] = match (true) {
            $now->isWeekend() => ['Closed · weekend', 'US equity market is closed for the weekend.', Lexicon::TONE_NEUTRAL],
            $minutes >= 570 && $minutes < 960 => ['Open', 'Regular session, 9:30–16:00 ET. Holidays are not modeled.', Lexicon::TONE_GOOD],
            $minutes >= 540 && $minutes < 570 => ['Pre-market', 'Pre-market, 9:00–9:30 ET. Options route in regular hours only.', Lexicon::TONE_WARN],
            $minutes >= 960 && $minutes < 1080 => ['After-hours', 'After-hours, 16:00–18:00 ET. Options route in regular hours only.', Lexicon::TONE_WARN],
            default => ['Closed', 'Outside 9:00–18:00 ET. Holidays are not modeled.', Lexicon::TONE_NEUTRAL],
        };

        return $this->chip($label, trim($desc.' '.$sched), $tone, ['et_time' => $now->format('H:i').' ET']);
    }

    /** @return array<string,mixed> */
    private function oauthChip(): array
    {
        try {
            $grant = OAuthTokenStore::fromConfig($this->cfg)->get();
        } catch (Throwable) {
            $grant = null;
        }

        if (! is_array($grant) || empty($grant['access_token'])) {
            $hasEnv = ! empty($this->cfg['oauth_token']);

            return $hasEnv
                ? $this->chip('Env token', 'Using the HARNESS_OAUTH_TOKEN bootstrap fallback (no stored grant).', Lexicon::TONE_WARN)
                : $this->chip('No grant', 'No OAuth grant is stored — the broker is unreachable.', Lexicon::TONE_BAD);
        }

        $expiresAt = $this->grantExpiry($grant);
        if ($expiresAt === null) {
            return $this->chip('Valid', 'OAuth grant present; expiry not recorded.', Lexicon::TONE_GOOD);
        }

        $secondsLeft = now()->diffInSeconds($expiresAt, false);
        if ($secondsLeft <= 0) {
            return $this->chip('Expired', 'Access token expired '.$this->humanAgo($expiresAt).'. Refresh needed.', Lexicon::TONE_BAD, [
                'expires_at' => $expiresAt->toIso8601ZuluString(),
                'refresh_token' => ! empty($grant['refresh_token']),
            ]);
        }

        $tone = $secondsLeft < 3600 ? Lexicon::TONE_WARN : Lexicon::TONE_GOOD;

        return $this->chip(
            'Valid · '.$this->humanDuration($secondsLeft).' left',
            'Access token valid until '.$expiresAt->toDayDateTimeString().'.',
            $tone,
            [
                'expires_at' => $expiresAt->toIso8601ZuluString(),
                'refresh_token' => ! empty($grant['refresh_token']),
            ],
        );
    }

    /** @return array<string,mixed> */
    private function creditsChip(): array
    {
        $credits = (array) ($this->cfg['broker_credits'] ?? []);
        $max = (int) ($credits['max_per_minute'] ?? 45);
        $cacheKey = (string) ($credits['cache_key'] ?? 'theta:robinhood:mcp:credits');

        $used = 0;
        try {
            $used = (int) Cache::get($cacheKey.':'.now()->format('YmdHi'), 0);
        } catch (Throwable) {
            // best-effort — credit ledger may be on a cache store this process can't reach.
        }

        $pct = $max > 0 ? ($used / $max) * 100 : 0;
        $tone = $pct >= 90 ? Lexicon::TONE_BAD : ($pct >= 70 ? Lexicon::TONE_WARN : Lexicon::TONE_GOOD);

        return $this->chip(
            $used.' / '.$max,
            'Broker API credits used this minute. The cockpit reads from a cached snapshot so it does not spend the trading path\'s budget.',
            $tone,
            ['used' => $used, 'max' => $max, 'pct' => round($pct, 1)],
        );
    }

    /** @return array<string,mixed> */
    private function dataPlaneChip(): array
    {
        $dp = $this->telemetry->dataPlane();
        if ($dp === null) {
            return $this->chip('No check', 'No market-data-plane check has run today.', Lexicon::TONE_NEUTRAL);
        }

        $age = $this->ageOf($dp['ts'] ?? null);
        $staleSeconds = (int) ($this->cfg['alpaca']['staleness_seconds'] ?? 10);

        if (! $dp['ok']) {
            return $this->chip('Error', 'Last data-plane check failed: '.($dp['error'] ?? 'unknown').'.', Lexicon::TONE_BAD, ['age' => $age]);
        }

        $tone = $age !== null && $age > max(60, $staleSeconds * 6) ? Lexicon::TONE_WARN : Lexicon::TONE_GOOD;

        return $this->chip(
            'Fresh',
            'Last data-plane smoke test OK'.($dp['symbol'] ? ' on '.$dp['symbol'] : '').'.',
            $tone,
            ['age' => $age, 'spread_bps' => $dp['spread_bps'] ?? null],
        );
    }

    /** @return array<string,mixed> */
    private function snapshotChip(array $snapshot): array
    {
        $age = $this->ageOf($snapshot['captured_at'] ?? null);
        $ok = (bool) ($snapshot['ok'] ?? false);
        $cache = (string) ($snapshot['cache'] ?? 'unknown');

        if ($snapshot['captured_at'] === null) {
            return $this->chip('Pending', 'No broker snapshot yet — the snapshot job has not run.', Lexicon::TONE_WARN, ['cache' => $cache]);
        }

        $tone = ! $ok ? Lexicon::TONE_WARN : ($age !== null && $age > 180 ? Lexicon::TONE_WARN : Lexicon::TONE_GOOD);
        $label = $age !== null ? $this->humanDuration((int) $age).' ago' : 'captured';

        return $this->chip(
            $label,
            $ok ? 'Broker truth captured cleanly.' : 'Snapshot captured with some failed reads — see errors.',
            $tone,
            ['cache' => $cache, 'age' => $age, 'ok' => $ok],
        );
    }

    // ── Account truth ────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function account(array $snapshot): array
    {
        $cash = $snapshot['cash'] ?? null;
        $positionsValue = $snapshot['derived']['positions_market_value'] ?? null;
        $accountValue = ($cash !== null && $positionsValue !== null) ? round($cash + $positionsValue, 2) : null;

        $settled = $snapshot['settled'] ?? [];
        $unrealized = $this->sumUnrealized($snapshot);

        return [
            'account_value' => ['value' => $accountValue, 'verified' => $accountValue !== null],
            'cash' => ['value' => $cash, 'verified' => $cash !== null],
            'buying_power' => ['value' => $snapshot['buying_power'] ?? null, 'verified' => ($snapshot['buying_power'] ?? null) !== null],
            'positions_value' => ['value' => $positionsValue, 'verified' => $positionsValue !== null],
            'settled_cash' => [
                'value' => $settled['settled_cash'] ?? null,
                'verified' => ($settled['settled_cash'] ?? null) !== null,
                'unsettled' => $settled['unsettled_sell_proceeds'] ?? 0.0,
                'source' => $settled['assumptions']['source'] ?? 'estimated (T+1)',
                'unsettled_rows' => $settled['assumptions']['unsettled_rows'] ?? [],
            ],
            'open_positions' => [
                'option' => $snapshot['derived']['open_option_positions'] ?? null,
                'equity' => $snapshot['derived']['open_equity_positions'] ?? null,
            ],
            'pnl' => [
                'realized_today' => $this->telemetry->realizedPnlToday(),
                'unrealized_open' => $unrealized,
            ],
        ];
    }

    private function sumUnrealized(array $snapshot): ?float
    {
        $total = 0.0;
        $any = false;
        foreach (['option', 'equity'] as $class) {
            foreach ($snapshot['positions'][$class] ?? [] as $p) {
                if (($p['unrealized_pnl'] ?? null) !== null) {
                    $total += (float) $p['unrealized_pnl'];
                    $any = true;
                }
            }
        }

        return $any ? round($total, 2) : null;
    }

    // ── Caps usage ───────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function caps(array $snapshot): array
    {
        $options = (array) ($this->cfg['options'] ?? []);
        $enteredToday = $snapshot['derived']['entered_today_debit'] ?? null;
        $openOption = $snapshot['derived']['open_option_positions'] ?? null;

        return [
            $this->capBar('Gross entry debit today', $enteredToday, (float) ($options['daily_gross_entry_debit'] ?? 0), '$', 'Filled buy-to-open option debit booked today (estimate). Authoritative cap accounting lives in the rail pipeline.'),
            $this->capBar('Per-day debit cap', $enteredToday, (float) ($options['per_day_max_debit'] ?? 0), '$', 'Per-day options debit ceiling.'),
            $this->capBar('Open option positions', $openOption, (float) ($options['max_open_positions'] ?? 0), '', 'Concurrent open option positions vs the configured maximum.'),
        ];
    }

    /** @return array<string,mixed> */
    private function capBar(string $label, ?float $used, float $limit, string $unit, string $note): array
    {
        $pct = ($limit > 0 && $used !== null) ? min(100.0, ($used / $limit) * 100) : 0.0;
        $tone = match (true) {
            $used === null => Lexicon::TONE_NEUTRAL,
            $pct >= 100 => Lexicon::TONE_INFO,
            $pct >= 80 => Lexicon::TONE_WARN,
            default => Lexicon::TONE_GOOD,
        };

        return [
            'label' => $label,
            'used' => $used,
            'limit' => $limit,
            'unit' => $unit,
            'pct' => round($pct, 1),
            'tone' => $tone,
            'note' => $note,
        ];
    }

    // ── Positions / orders ───────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function positions(array $snapshot): array
    {
        $decode = function (array $p, string $class): array {
            $type = $p['option_type'] ?? null;

            return [
                'symbol' => $p['symbol'],
                'asset_class' => $class,
                'contract' => $this->contractLabel($p['symbol'], $type, $p['strike'] ?? null, $p['expiration'] ?? null, $class),
                'option_type' => $type ? Lexicon::optionType($type) : null,
                'quantity' => $p['quantity'],
                'average_price' => $p['average_price'],
                'current_price' => $p['current_price'],
                'market_value' => $p['market_value'],
                'unrealized_pnl' => $p['unrealized_pnl'],
            ];
        };

        return [
            'option' => array_map(fn ($p) => $decode($p, 'option'), $snapshot['positions']['option'] ?? []),
            'equity' => array_map(fn ($p) => $decode($p, 'equity'), $snapshot['positions']['equity'] ?? []),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function openOrders(array $snapshot): array
    {
        $orders = [];
        foreach (['option', 'equity'] as $class) {
            foreach ($snapshot['orders'][$class] ?? [] as $o) {
                if (($o['classification'] ?? null) !== 'LIVE_POSTED_NOT_FILLED') {
                    continue;
                }
                $orders[] = [
                    'symbol' => $o['symbol'],
                    'contract' => $this->contractLabel($o['symbol'], $o['option_type'] ?? null, null, null, $class),
                    'asset_class' => $class,
                    'side' => $o['side'] ? Lexicon::side($o['side']) : null,
                    'position_effect' => $o['position_effect'] ? Lexicon::positionEffect($o['position_effect']) : null,
                    'quantity' => $o['quantity'],
                    'limit_price' => $o['limit_price'],
                    'classification' => Lexicon::classification($o['classification']),
                    'age' => $this->ageOf($o['created_at'] ?? null),
                    'broker_order_id' => $o['broker_order_id'],
                ];
            }
        }

        return $orders;
    }

    // ── Decisions / why-not / journal ────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function decisions(): array
    {
        return array_map(fn (array $row) => $this->decodeDecision($row), $this->telemetry->decisionsToday());
    }

    /** @return list<array<string,mixed>> */
    private function journal(): array
    {
        return array_map(fn (array $row) => $this->decodeDecision($row), $this->telemetry->recentJournal());
    }

    /** @return array<string,mixed> */
    private function decodeDecision(array $row): array
    {
        return [
            'id' => $row['id'],
            'time' => $row['updated_at'],
            'age' => $this->ageOf($row['updated_at'] ?? null),
            'symbol' => $row['underlying_symbol'] ?: $row['symbol'],
            'asset_class' => $row['asset_class'],
            'contract' => $this->contractLabel(
                $row['underlying_symbol'] ?: $row['symbol'],
                $row['option_type'] ?? null,
                $row['strike_price'] ?? null,
                $row['expiration_date'] ?? null,
                $row['asset_class'],
            ),
            'status' => Lexicon::status($row['status']),
            'intent' => $row['intent'] ? Lexicon::intent($row['intent']) : null,
            'side' => $row['side'] ? Lexicon::side($row['side']) : null,
            'reason' => $row['block_reason'] ? Lexicon::blockReason($row['block_reason']) : null,
            'structure' => array_values(array_filter([
                $row['structure_class'] ? ['axis' => 'Structure', 'term' => Lexicon::structureClass($row['structure_class'])] : null,
                $row['relative_strength_state'] ? ['axis' => 'Rel. strength', 'term' => Lexicon::relativeStrength($row['relative_strength_state'])] : null,
                $row['gap_classification'] ? ['axis' => 'Gap', 'term' => Lexicon::gap($row['gap_classification'])] : null,
                $row['trend_geometry_state'] ? ['axis' => 'Geometry', 'term' => Lexicon::geometry($row['trend_geometry_state'])] : null,
                $row['liquidity_tier'] ? ['axis' => 'Liquidity', 'term' => Lexicon::liquidity($row['liquidity_tier'])] : null,
            ])),
            'limit_price' => $row['limit_price'],
            'fill_price' => $row['average_fill_price'],
            'est_cost' => $row['review_estimated_cost'],
            'realized_pnl' => $row['realized_pnl'],
            'unrealized_pnl' => $row['unrealized_pnl'],
            'thesis' => $row['thesis'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function whyNotTrading(): array
    {
        $groups = [];
        foreach ($this->telemetry->blockReasonsToday() as $raw) {
            $prefix = trim(strstr($raw, ':', true) ?: $raw);
            $detail = trim((string) substr(strstr($raw, ':') ?: '', 1));

            $groups[$prefix] ??= ['count' => 0, 'details' => []];
            $groups[$prefix]['count']++;
            if ($detail !== '') {
                $groups[$prefix]['details'][$detail] = ($groups[$prefix]['details'][$detail] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($groups as $prefix => $group) {
            arsort($group['details']);
            $details = [];
            foreach (array_slice($group['details'], 0, 3, true) as $detailRaw => $count) {
                $details[] = ['label' => Lexicon::blockReason($detailRaw)['label'], 'count' => $count];
            }

            $out[] = [
                'reason' => Lexicon::blockReason($prefix),
                'count' => $group['count'],
                'details' => $details,
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    // ── Reconciliation / events ──────────────────────────────────────────

    /** @return array<string,mixed> */
    private function reconciliation(array $snapshot): array
    {
        $merge = [];
        foreach (['equity', 'option'] as $class) {
            foreach ((array) ($snapshot['reconciliation'][$class] ?? []) as $classification => $count) {
                $merge[$classification] = ($merge[$classification] ?? 0) + $count;
            }
        }

        $rows = [];
        foreach ($merge as $classification => $count) {
            $rows[] = ['classification' => Lexicon::classification($classification), 'count' => $count];
        }
        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'rows' => $rows,
            'conflicts' => count($snapshot['reconciliation']['conflicts'] ?? []),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function systemEvents(): array
    {
        return array_map(function (array $e): array {
            return [
                'time' => $e['ts'] ?? null,
                'age' => $this->ageOf($e['ts'] ?? null),
                'stream' => $e['stream'] ?? null,
                'event' => Lexicon::event($e['event'] ?? null),
                'symbol' => $e['symbol'] ?? ($e['contract'] ?? null),
                'classification' => isset($e['classification']) ? Lexicon::classification((string) $e['classification']) : null,
                'detail' => $e['error'] ?? null,
            ];
        }, $this->telemetry->systemEvents());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function contractLabel(?string $symbol, ?string $type, ?float $strike, ?string $expiration, string $assetClass): string
    {
        $symbol = strtoupper((string) $symbol);
        if ($assetClass !== 'option' || ($type === null && $strike === null)) {
            return $symbol;
        }

        $parts = [$symbol];
        if ($strike !== null) {
            $parts[] = '$'.rtrim(rtrim(number_format($strike, 2, '.', ''), '0'), '.');
        }
        if ($type !== null) {
            $parts[] = strtoupper(substr($type, 0, 1)); // C / P
        }
        if ($expiration !== null) {
            $parts[] = $expiration;
        }

        return implode(' ', $parts);
    }

    /** @return array<string,mixed> */
    private function chip(string $label, string $description, string $tone, array $extra = []): array
    {
        return array_merge(['label' => $label, 'description' => $description, 'tone' => $tone], $extra);
    }

    private function grantExpiry(array $grant): ?Carbon
    {
        try {
            if (! empty($grant['expires_at'])) {
                return Carbon::parse((string) $grant['expires_at']);
            }
            $base = $grant['obtained_at'] ?? $grant['stored_at'] ?? null;
            if ($base !== null && isset($grant['expires_in']) && is_numeric($grant['expires_in'])) {
                return Carbon::parse((string) $base)->addSeconds((int) $grant['expires_in']);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function ageOf(?string $ts): ?int
    {
        if (! is_string($ts) || $ts === '') {
            return null;
        }
        try {
            return (int) max(0, now()->diffInSeconds(Carbon::parse($ts)));
        } catch (Throwable) {
            return null;
        }
    }

    private function humanDuration(int|float $seconds): string
    {
        $seconds = (int) $seconds;
        if ($seconds < 60) {
            return $seconds.'s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
        }

        return intdiv($seconds, 86400).'d '.intdiv($seconds % 86400, 3600).'h';
    }

    private function humanAgo(Carbon $when): string
    {
        return $this->humanDuration((int) abs(now()->diffInSeconds($when))).' ago';
    }
}
