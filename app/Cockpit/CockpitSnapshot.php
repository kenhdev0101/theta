<?php

declare(strict_types=1);

namespace App\Cockpit;

use App\Robinhood\Execution\SettledCashEstimator;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Reconciliation\BrokerTruthReconciler;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Read-only broker-truth snapshot for the cockpit.
 *
 * The cockpit must never compete with the live execution path for the broker
 * credit budget. So broker reads happen at one bounded cadence — the
 * scheduled theta:cockpit-snapshot command warms the cache once a minute, and
 * every web request reads the cached snapshot. A cold/stale cache triggers at
 * most one lock-guarded inline refresh, so N dashboard tabs never fan out into
 * N broker reads.
 *
 * Every section read is guarded: a single failing broker call is captured in
 * `errors` and the rest of the snapshot still renders. Missing data reads as
 * null/unverified, never as a confident zero.
 */
final class CockpitSnapshot
{
    public const CACHE_KEY = 'theta:cockpit:snapshot';

    private const LOCK_KEY = 'theta:cockpit:snapshot:lock';

    public function __construct(private readonly array $cfg) {}

    public static function fromConfig(?array $cfg = null): self
    {
        return new self($cfg ?? (array) config('harness'));
    }

    /**
     * Return a snapshot, preferring a warm cache. Falls back to a single
     * lock-guarded live capture; if another worker holds the lock, returns the
     * last cached snapshot (flagged stale) rather than piling on broker calls.
     */
    public function cached(int $maxAgeSeconds = 75): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $this->ageSeconds($cached) <= $maxAgeSeconds) {
            return array_merge($cached, ['cache' => 'warm']);
        }

        $lock = Cache::lock(self::LOCK_KEY, 20);
        if ($lock->get()) {
            try {
                $snapshot = $this->capture();
                $this->store($snapshot);

                return array_merge($snapshot, ['cache' => 'refreshed']);
            } finally {
                $lock->release();
            }
        }

        if (is_array($cached)) {
            return array_merge($cached, ['cache' => 'stale']);
        }

        return array_merge($this->empty('refresh_in_progress'), ['cache' => 'miss']);
    }

    /** Persist a captured snapshot for web readers (15-minute safety TTL). */
    public function store(array $snapshot): void
    {
        Cache::put(self::CACHE_KEY, $snapshot, now()->addMinutes(15));
    }

    /**
     * Live broker reads. Section-guarded: one failure is recorded, never fatal.
     */
    public function capture(): array
    {
        $account = (string) ($this->cfg['agentic_account'] ?? '');
        $errors = [];

        $client = RobinhoodMcpClient::fromConfig($this->cfg, JsonlRecorder::fromConfig($this->cfg));

        $portfolio = $this->guard('portfolio', $errors, fn () => $client->getPortfolio(), []);
        $capability = $this->guard('capability', $errors, fn () => $client->getAccountCapability(), null);
        $marketHours = $this->guard('market_hours', $errors, fn () => $client->getMarketHours(), []);
        $equityPositions = PortfolioReader::listFrom($this->guard('equity_positions', $errors, fn () => $client->getEquityPositions(), []));
        $optionPositions = PortfolioReader::listFrom($this->guard('option_positions', $errors, fn () => $client->getOptionPositions(), []));
        $equityOrders = PortfolioReader::listFrom($this->guard('equity_orders', $errors, fn () => $client->getEquityOrders(), []));
        $optionOrders = PortfolioReader::listFrom($this->guard('option_orders', $errors, fn () => $client->getOptionOrders(), []));

        $cash = PortfolioReader::cash($portfolio);
        $buyingPower = PortfolioReader::buyingPower($portfolio);

        $settled = (new SettledCashEstimator)->fromBrokerTruth($portfolio, $equityOrders, optionOrders: $optionOrders);

        $reconciler = new BrokerTruthReconciler;
        $journalRows = []; // reconciliation context is owned by the reconcile job; cockpit shows broker-side ledger.
        $equityRecon = $reconciler->reconcile($equityOrders, $journalRows);
        $optionRecon = $reconciler->reconcileOptions($optionOrders, $journalRows);

        return [
            'captured_at' => now()->toIso8601ZuluString(),
            'ok' => $errors === [],
            'errors' => $errors,
            'account' => $account,
            'capability' => $capability,
            'market' => $this->marketSession($marketHours),
            'cash' => $cash,
            'buying_power' => $buyingPower,
            'settled' => $settled,
            'positions' => [
                'equity' => array_map(fn ($p) => $this->normalizePosition($p, 'equity'), $equityPositions),
                'option' => array_map(fn ($p) => $this->normalizePosition($p, 'option'), $optionPositions),
            ],
            'orders' => [
                'equity' => array_map(fn ($o) => $this->normalizeOrder($o, 'equity', $reconciler), $equityOrders),
                'option' => array_map(fn ($o) => $this->normalizeOrder($o, 'option', $reconciler), $optionOrders),
            ],
            'reconciliation' => [
                'equity' => $equityRecon['summary'] ?? [],
                'option' => $optionRecon['summary'] ?? [],
                'conflicts' => $this->conflicts($equityRecon, $optionRecon),
            ],
            'derived' => [
                'open_option_positions' => $this->openPositionCount($optionPositions),
                'open_equity_positions' => $this->openPositionCount($equityPositions),
                'entered_today_debit' => $this->enteredTodayDebit($optionOrders),
                'positions_market_value' => $this->portfolioMarketValue($equityPositions, $optionPositions),
            ],
        ];
    }

    private function empty(string $reason): array
    {
        return [
            'captured_at' => null,
            'ok' => false,
            'errors' => ['snapshot' => $reason],
            'account' => (string) ($this->cfg['agentic_account'] ?? ''),
            'capability' => null,
            'market' => $this->marketSession([]),
            'cash' => null,
            'buying_power' => null,
            'settled' => ['settled_cash' => null, 'unsettled_sell_proceeds' => 0.0, 'assumptions' => []],
            'positions' => ['equity' => [], 'option' => []],
            'orders' => ['equity' => [], 'option' => []],
            'reconciliation' => ['equity' => [], 'option' => [], 'conflicts' => []],
            'derived' => [
                'open_option_positions' => null,
                'open_equity_positions' => null,
                'entered_today_debit' => null,
                'positions_market_value' => null,
            ],
        ];
    }

    /** @param  array<string,string>  $errors */
    private function guard(string $section, array &$errors, callable $read, mixed $default): mixed
    {
        try {
            $value = $read();

            return $value === null ? $default : $value;
        } catch (Throwable $e) {
            $errors[$section] = $e->getMessage();

            return $default;
        }
    }

    private function ageSeconds(array $snapshot): float
    {
        $capturedAt = $snapshot['captured_at'] ?? null;
        if (! is_string($capturedAt) || $capturedAt === '') {
            return PHP_FLOAT_MAX;
        }

        try {
            // Carbon 3 diffs are signed; abs() gives true elapsed seconds (a
            // max(0, …) here would read every cached snapshot as 0s-fresh and
            // suppress the inline refresh fallback).
            return abs(now()->floatDiffInSeconds(Carbon::parse($capturedAt)));
        } catch (Throwable) {
            return PHP_FLOAT_MAX;
        }
    }

    /**
     * The broker market-hours resource is a static schedule description (a
     * `text` blob), not a live clock — so the session open/closed call is
     * derived deterministically from the ET wall-clock in CockpitData. Here we
     * just carry the schedule text through for the operator's reference.
     */
    private function marketSession(array $marketHours): array
    {
        $payload = PortfolioReader::unwrap($marketHours);

        return [
            'schedule' => $this->firstString($payload, ['text', 'schedule', 'description']),
        ];
    }

    private function normalizePosition(array $p, string $assetClass): array
    {
        $quantity = $this->num($p, ['quantity', 'qty', 'position', 'shares']);
        $avgPrice = $this->num($p, ['average_price', 'average_open_price', 'avg_price', 'average_cost']);
        $marketValue = $this->numOrNull($p, ['market_value', 'equity', 'value']);
        $currentPrice = $this->numOrNull($p, ['current_price', 'last_price', 'price', 'mark_price']);

        $multiplier = $assetClass === 'option'
            ? ($this->num($p, ['trade_value_multiplier', 'multiplier', 'contract_multiplier']) ?: 100.0)
            : 1.0;

        $cost = $quantity !== null && $avgPrice !== null ? round($quantity * $avgPrice * $multiplier, 2) : null;
        if ($marketValue === null && $quantity !== null && $currentPrice !== null) {
            $marketValue = round($quantity * $currentPrice * $multiplier, 2);
        }
        $unrealized = $marketValue !== null && $cost !== null ? round($marketValue - $cost, 2) : null;

        return [
            'symbol' => strtoupper((string) ($p['chain_symbol'] ?? $p['symbol'] ?? $p['underlying_symbol'] ?? '')),
            'option_type' => $p['type'] ?? $p['option_type'] ?? null,
            'strike' => $this->numOrNull($p, ['strike_price', 'strike']),
            'expiration' => $this->firstString($p, ['expiration_date', 'expiration']),
            'quantity' => $quantity,
            'average_price' => $avgPrice,
            'current_price' => $currentPrice,
            'cost_basis' => $cost,
            'market_value' => $marketValue,
            'unrealized_pnl' => $unrealized,
        ];
    }

    private function normalizeOrder(array $o, string $assetClass, BrokerTruthReconciler $reconciler): array
    {
        $leg = $this->firstLeg($o);
        $classification = $assetClass === 'option' ? $reconciler->classifyOption($o) : $reconciler->classify($o);

        return [
            'symbol' => strtoupper((string) ($o['chain_symbol'] ?? $o['symbol'] ?? '')),
            'asset_class' => $assetClass,
            'side' => $leg['side'] ?? $o['side'] ?? null,
            'position_effect' => $leg['position_effect'] ?? $o['position_effect'] ?? null,
            'option_type' => $leg['option_type'] ?? $o['type'] ?? null,
            'quantity' => $this->numOrNull($o, ['quantity', 'processed_quantity', 'cumulative_quantity']),
            'limit_price' => $this->numOrNull($o, ['price', 'limit_price', 'premium']),
            'state' => $o['state'] ?? $o['status'] ?? null,
            'classification' => $classification,
            'created_at' => $this->firstString($o, ['created_at', 'updated_at', 'last_transaction_at']),
            'broker_order_id' => (string) ($o['id'] ?? $o['order_id'] ?? '') ?: null,
        ];
    }

    private function conflicts(array $equityRecon, array $optionRecon): array
    {
        $conflicts = [];
        foreach ([$equityRecon['ledger'] ?? [], $optionRecon['ledger'] ?? []] as $ledger) {
            foreach ($ledger as $row) {
                if (($row['classification'] ?? '') === BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED) {
                    $conflicts[] = $row;
                }
            }
        }

        return $conflicts;
    }

    private function openPositionCount(array $positions): int
    {
        $count = 0;
        foreach ($positions as $p) {
            $qty = $this->num($p, ['quantity', 'qty', 'position', 'shares']);
            if ($qty !== null && abs($qty) > 0.0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Best-effort gross entry debit placed today: filled buy-to-open option
     * orders whose last transaction is today. Clearly an estimate — the
     * authoritative cap accounting lives in the rail pipeline.
     */
    private function enteredTodayDebit(array $optionOrders): float
    {
        $today = now()->startOfDay();
        $total = 0.0;

        foreach ($optionOrders as $o) {
            $leg = $this->firstLeg($o);
            $side = strtolower((string) ($leg['side'] ?? $o['side'] ?? ''));
            $effect = strtolower((string) ($leg['position_effect'] ?? $o['position_effect'] ?? ''));
            if ($side !== 'buy' || ($effect !== '' && $effect !== 'open')) {
                continue;
            }

            $state = strtolower((string) ($o['state'] ?? $o['status'] ?? ''));
            if (! in_array($state, ['filled', 'partially_filled'], true)) {
                continue;
            }

            $tradedAt = $o['last_transaction_at'] ?? $o['updated_at'] ?? $o['created_at'] ?? null;
            if ($tradedAt !== null) {
                try {
                    if (Carbon::parse((string) $tradedAt)->startOfDay()->lessThan($today)) {
                        continue;
                    }
                } catch (Throwable) {
                    // unknown date — count it (fail toward showing spend)
                }
            }

            $premium = $this->num($o, ['processed_premium', 'premium']);
            if ($premium !== null && $premium > 0.0) {
                $total += $premium;

                continue;
            }

            $qty = $this->num($o, ['processed_quantity', 'cumulative_quantity', 'quantity']);
            $price = $this->num($o, ['average_price', 'price']);
            if ($qty !== null && $price !== null) {
                $total += $qty * $price * 100.0;
            }
        }

        return round($total, 2);
    }

    private function portfolioMarketValue(array $equityPositions, array $optionPositions): ?float
    {
        // Flat account: positions value is definitively $0 (verified), not
        // unverifiable. Only return null when positions exist but can't be marked.
        if ($equityPositions === [] && $optionPositions === []) {
            return 0.0;
        }

        $total = 0.0;
        $any = false;

        foreach ([['equity', $equityPositions], ['option', $optionPositions]] as [$class, $positions]) {
            foreach ($positions as $p) {
                $norm = $this->normalizePosition($p, $class);
                if ($norm['market_value'] !== null) {
                    $total += $norm['market_value'];
                    $any = true;
                }
            }
        }

        return $any ? round($total, 2) : null;
    }

    private function firstLeg(array $row): array
    {
        $legs = $row['legs'] ?? null;
        $leg = is_array($legs) ? ($legs[0] ?? null) : null;

        return is_array($leg) ? $leg : [];
    }

    private function num(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }

        return null;
    }

    private function numOrNull(array $row, array $keys): ?float
    {
        return $this->num($row, $keys);
    }

    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }
}
