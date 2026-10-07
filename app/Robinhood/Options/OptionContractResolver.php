<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use Illuminate\Support\Carbon;

/**
 * Deterministic contract resolver. Maps an explicit contract spec
 * (underlying, type, exact expiration, exact strike) to exactly one
 * Robinhood option instrument UUID plus its live quote, or a named blocker.
 *
 * Selection rules beyond 'exact' (delta target, moneyness) have hooks here
 * and are deterministic over broker-supplied Greeks when implemented — the
 * model never chooses the contract. Option IDs are never synthesized or
 * inferred from OCC-style symbols.
 */
final class OptionContractResolver
{
    public const VERDICT_RESOLVED = 'RESOLVED';

    public const VERDICT_BLOCKED = 'BLOCKED';

    public function __construct(
        private readonly RobinhoodMcpClient $client,
        private readonly array $cfg,
    ) {}

    public function resolveExact(
        string $underlyingSymbol,
        string $optionType,
        string $expirationDate,
        float $strikePrice,
    ): array {
        $blockers = [];

        $instruments = PortfolioReader::listFrom(
            $this->client->getOptionInstruments([
                'chain_symbol' => strtoupper($underlyingSymbol),
                'expiration_dates' => $expirationDate,
                'strike_price' => number_format($strikePrice, 4, '.', ''),
                'type' => $optionType,
                'state' => 'active',
            ]),
            ['instruments'],
        );

        if (count($instruments) === 0) {
            return $this->blocked(['no_matching_contract']);
        }
        if (count($instruments) > 1) {
            return $this->blocked(['ambiguous_match: '.count($instruments).' contracts for exact spec']);
        }

        $instrument = $instruments[0];
        $contract = [
            'option_id' => (string) ($instrument['id'] ?? ''),
            'chain_id' => $instrument['chain_id'] ?? null,
            'chain_symbol' => $instrument['chain_symbol'] ?? strtoupper($underlyingSymbol),
            'underlying_type' => $instrument['underlying_type'] ?? 'equity',
            'expiration_date' => $instrument['expiration_date'] ?? $expirationDate,
            'strike_price' => isset($instrument['strike_price']) ? (float) $instrument['strike_price'] : $strikePrice,
            'type' => $instrument['type'] ?? $optionType,
            'state' => $instrument['state'] ?? null,
            'tradability' => $instrument['tradability'] ?? null,
            'sellout_datetime' => $instrument['sellout_datetime'] ?? null,
        ];

        if ($contract['option_id'] === '') {
            return $this->blocked(['instrument_missing_id']);
        }
        if ($contract['tradability'] !== 'tradable') {
            $blockers[] = 'contract_untradable';
        }
        if ($contract['state'] !== 'active') {
            $blockers[] = 'contract_not_active';
        }

        $quote = $this->quoteFor($contract['option_id']);
        if ($quote === null) {
            $blockers[] = 'quote_unavailable';
        } else {
            if ($quote['bid'] === null || $quote['ask'] === null) {
                $blockers[] = 'bid_or_ask_missing';
            }
            if ($quote['stale']) {
                $blockers[] = 'quote_stale';
            }
            if ($quote['spread_bps'] !== null && $quote['spread_bps'] > (float) ($this->options()['max_spread_bps'] ?? 1500)) {
                $blockers[] = 'spread_above_max';
            }
            if ($quote['ask'] !== null && $quote['ask'] > (float) ($this->options()['max_contract_ask'] ?? 5.0)) {
                $blockers[] = 'ask_above_max';
            }
        }

        return [
            'verdict' => $blockers === [] ? self::VERDICT_RESOLVED : self::VERDICT_BLOCKED,
            'blockers' => $blockers,
            'contract' => $contract,
            'quote' => $quote,
        ];
    }

    /**
     * Hook for deterministic delta-target selection over broker-supplied
     * Greeks (quote payloads carry delta). Not implemented in the Level 2
     * v0 pass — explicit exact-contract selection is the only live rule.
     */
    public function resolveByDeltaTarget(string $underlyingSymbol, string $optionType, string $expirationDate, float $deltaTarget): array
    {
        return $this->blocked(['selection_rule_not_implemented: delta_target (deterministic hook reserved; exact selection is the v0 rule)']);
    }

    /** Hook for deterministic moneyness selection. Not implemented in v0. */
    public function resolveByMoneyness(string $underlyingSymbol, string $optionType, string $expirationDate, float $moneynessPct): array
    {
        return $this->blocked(['selection_rule_not_implemented: moneyness (deterministic hook reserved; exact selection is the v0 rule)']);
    }

    private function quoteFor(string $optionId): ?array
    {
        $results = PortfolioReader::listFrom($this->client->getOptionQuotes([$optionId]), ['results']);
        $quote = null;
        foreach ($results as $result) {
            $candidate = is_array($result) ? ($result['quote'] ?? null) : null;
            if (is_array($candidate) && (string) ($candidate['instrument_id'] ?? '') === $optionId) {
                $quote = $candidate;
                break;
            }
        }
        if (! is_array($quote)) {
            return null;
        }

        $bid = $this->positiveOrNull($quote['bid_price'] ?? null);
        $ask = $this->positiveOrNull($quote['ask_price'] ?? null);
        $mid = ($bid !== null && $ask !== null) ? round(($bid + $ask) / 2, 4) : null;
        $spread = ($bid !== null && $ask !== null) ? round($ask - $bid, 4) : null;
        $spreadBps = ($spread !== null && $mid !== null && $mid > 0) ? round($spread / $mid * 10000, 2) : null;

        $updatedAt = $quote['updated_at'] ?? null;
        $ageSeconds = null;
        $stale = true;
        if ($updatedAt !== null) {
            $ageSeconds = round(abs(Carbon::parse((string) $updatedAt)->diffInMilliseconds(now())) / 1000, 3);
            $stale = $ageSeconds > (int) ($this->options()['min_quote_fresh_seconds'] ?? 10);
        }

        return [
            'bid' => $bid,
            'ask' => $ask,
            'mid' => $mid,
            'mark' => $this->positiveOrNull($quote['mark_price'] ?? null),
            'spread' => $spread,
            'spread_bps' => $spreadBps,
            'bid_size' => $quote['bid_size'] ?? null,
            'ask_size' => $quote['ask_size'] ?? null,
            'delta' => isset($quote['delta']) ? (float) $quote['delta'] : null,
            'implied_volatility' => isset($quote['implied_volatility']) ? (float) $quote['implied_volatility'] : null,
            'open_interest' => $quote['open_interest'] ?? null,
            'volume' => $quote['volume'] ?? null,
            'updated_at' => $updatedAt,
            'age_seconds' => $ageSeconds,
            'stale' => $stale,
            'staleness_gate_seconds' => (int) ($this->options()['min_quote_fresh_seconds'] ?? 10),
        ];
    }

    private function options(): array
    {
        return (array) ($this->cfg['options'] ?? []);
    }

    private function blocked(array $blockers): array
    {
        return [
            'verdict' => self::VERDICT_BLOCKED,
            'blockers' => $blockers,
            'contract' => null,
            'quote' => null,
        ];
    }

    private function positiveOrNull(mixed $value): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        $value = (float) $value;

        // Broker guide: drop bid/ask when zero.
        return $value > 0 ? $value : null;
    }
}
