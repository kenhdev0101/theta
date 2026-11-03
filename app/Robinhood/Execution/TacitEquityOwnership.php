<?php

declare(strict_types=1);

namespace App\Robinhood\Execution;

use App\Models\HarnessOrderRef;
use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Basket\BasketWeights;
use App\Robinhood\Rails\GateResult;
use App\Robinhood\Rails\OrderIntent;

/**
 * Broker equity positions are netted by symbol. Reserve the enabled tacit
 * universe before its first fill, and retain historical/ambiguous ownership
 * even when the basket is disabled or its weights change. Releasing a name
 * requires an explicit ownership migration; a missing snapshot is no release.
 */
final class TacitEquityOwnership
{
    public const STRATEGY = 'theta_basket_etf';

    public function evaluate(OrderIntent $intent, array $cfg): GateResult
    {
        if ($intent->assetClass !== 'equity') {
            return GateResult::block('equity_ownership', 'equity_executor_asset_mismatch');
        }
        if ($intent->strategyLabel === self::STRATEGY) {
            return $intent->side === 'buy'
                ? GateResult::allow('equity_ownership')
                : GateResult::block('equity_ownership', 'tacit_v1_buy_only');
        }

        $symbol = strtoupper($intent->symbol);
        $claimed = RobinhoodTradeJournal::query()
            ->where('account_number', (string) ($cfg['agentic_account'] ?? ''))
            ->where('asset_class', 'equity')->where('strategy_label', self::STRATEGY)
            ->where('symbol', $symbol)->where('side', 'buy')
            ->where(fn ($q) => $q->whereIn('status', ['submitted', 'open', 'partially_filled', 'filled'])
                ->orWhere('filled_quantity', '>', 0)->orWhereNotNull('broker_order_id'))
            ->exists();

        // Refs precede the broker call and survive a lost acknowledgement.
        // This harness/ref store is pinned to one broker account.
        $claimed = $claimed || HarnessOrderRef::query()->where('symbol', $symbol)
            ->where('order_payload->asset_class', 'equity')
            ->where('order_payload->strategy_label', self::STRATEGY)
            ->whereIn('status', ['submitting', 'placed', 'failed'])->exists();

        if (! $claimed && (bool) data_get($cfg, 'basket.enabled', false)) {
            try {
                $claimed = in_array($symbol, BasketWeights::fromCsv((string) data_get($cfg, 'basket.weights_path', ''))->tickers(), true);
            } catch (\Throwable) {
                return GateResult::block('equity_ownership', 'tacit_universe_unverified');
            }
        }

        return $claimed
            ? GateResult::block('equity_ownership', 'tacit_symbol_reserved', ['symbol' => $symbol, 'owner' => self::STRATEGY])
            : GateResult::allow('equity_ownership');
    }
}
