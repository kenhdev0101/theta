<?php

declare(strict_types=1);

namespace App\Robinhood\Options;

/**
 * Dedicated pre-trade gate chain for single-leg Level 2 options. Reuses the
 * equity rail ideas (fail closed on unverified inputs, full gating ledger,
 * named reasons) without pretending options are equities: contract
 * tradability, quote freshness, spread, per-contract ask, and option-level
 * permission are first-class gates here.
 *
 * Context contract (broker/account truth, assembled by the caller):
 *   account            pinned-account capability row (account_number,
 *                      option_level, agentic_allowed) or null = unverified
 *   capability_tools   list of advertised MCP tool names, or null
 *   contract           resolver contract block (tradability, state, ...)
 *   quote              resolver quote block (bid, ask, spread_bps, stale)
 *   session_open       bool|null
 *   settled_cash       float|null
 *   buying_power       float|null
 *   options_spent_today_debit  float|null
 *   underlying_open_debit      map SYMBOL -> open option debit
 *   open_option_positions      list (open positions)
 *   open_option_orders         list of broker option orders (open states)
 *   existing_ref       persisted ref row summary or null
 *   review             review_option_order response or null
 */
final class OptionRailPipeline
{
    public const BLOCK_CAPABILITY = 'BLOCK_CAPABILITY';

    public const BLOCK_PERMISSION = 'BLOCK_PERMISSION';

    public const BLOCK_MARKET_CLOSED = 'BLOCK_MARKET_CLOSED';

    public const BLOCK_STALE_QUOTE = 'BLOCK_STALE_QUOTE';

    public const BLOCK_WIDE_SPREAD = 'BLOCK_WIDE_SPREAD';

    public const BLOCK_PRICE = 'BLOCK_PRICE';

    public const BLOCK_CAP = 'BLOCK_CAP';

    public const BLOCK_DUPLICATE = 'BLOCK_DUPLICATE';

    public const BLOCK_REVIEW_ALERT = 'BLOCK_REVIEW_ALERT';

    public const BLOCK_KILL_SWITCH = 'BLOCK_KILL_SWITCH';

    public const BLOCK_UNKNOWN = 'BLOCK_UNKNOWN';

    private const ALLOWED_OPTION_LEVELS = ['option_level_2', 'option_level_3'];

    private const OPEN_ORDER_STATES = ['queued', 'unconfirmed', 'confirmed', 'placed', 'pending', 'partially_filled', 'open', 'new', 'accepted'];

    private const BLOCKING_REVIEW_PATTERNS = [
        'buying power', 'insufficient', 'pattern day', 'good faith', 'free riding',
        'halt', 'restricted', 'not tradable', 'unavailable', 'permission', 'level',
    ];

    /**
     * @param  bool  $includeReview  append the broker-review gate
     * @param  bool  $includeCaps     append the capital/allocation gate. The
     *                                decision layer evaluates candidate quality
     *                                with this OFF (capital must not gate
     *                                evaluation); the execution layer always
     *                                runs it ON before placing.
     */
    public function evaluate(OptionOrderIntent $intent, array $cfg, array $context, bool $includeReview = true, bool $includeCaps = true): OptionRailVerdict
    {
        $results = [
            $this->killSwitchGate($cfg),
            $this->capabilityGate($intent, $cfg, $context),
            $this->permissionGate($intent, $cfg, $context),
            $this->strategyGate($intent),
            $this->sessionGate($intent, $context),
            $this->quoteFreshnessGate($cfg, $context),
            $this->spreadGate($intent, $cfg, $context),
            $this->marketabilityGate($intent, $context),
        ];

        if ($includeCaps) {
            $results[] = $this->capsGate($intent, $cfg, $context);
        }

        $results[] = $this->duplicateGate($intent, $context);

        if ($includeReview) {
            $results[] = $this->reviewGate($context);
        }

        return new OptionRailVerdict($intent, $results);
    }

    private function killSwitchGate(array $cfg): OptionGateResult
    {
        // Deliberate fail-closed default: a MISSING kill_switch key reads as
        // engaged. Unknown is never "disarmed" — matches the equity gate.
        if ((bool) ($cfg['kill_switch'] ?? true)) {
            return OptionGateResult::block('kill_switch', self::BLOCK_KILL_SWITCH, 'kill_switch_engaged');
        }

        return OptionGateResult::allow('kill_switch');
    }

    private function capabilityGate(OptionOrderIntent $intent, array $cfg, array $context): OptionGateResult
    {
        $options = (array) ($cfg['options'] ?? []);

        if (! (bool) ($options['enabled'] ?? false)) {
            return OptionGateResult::block('capability', self::BLOCK_CAPABILITY, 'options_disabled_in_config');
        }

        $tools = $context['capability_tools'] ?? null;
        if (is_array($tools)) {
            $missing = array_values(array_diff((array) ($options['required_tools'] ?? []), $tools));
            if ($missing !== []) {
                return OptionGateResult::block('capability', self::BLOCK_CAPABILITY, 'broker_tools_missing', ['missing' => $missing]);
            }
        }

        $contract = $context['contract'] ?? null;
        if (! is_array($contract)) {
            return OptionGateResult::block('capability', self::BLOCK_CAPABILITY, 'contract_unresolved');
        }
        if (($contract['tradability'] ?? null) !== 'tradable') {
            return OptionGateResult::block('capability', self::BLOCK_CAPABILITY, 'contract_untradable', [
                'tradability' => $contract['tradability'] ?? null,
            ]);
        }
        if (($contract['state'] ?? null) !== 'active') {
            return OptionGateResult::block('capability', self::BLOCK_CAPABILITY, 'contract_not_active', [
                'state' => $contract['state'] ?? null,
            ]);
        }
        if (($intent->optionId ?? '') === '' && ($contract['option_id'] ?? '') === '') {
            return OptionGateResult::block('capability', self::BLOCK_CAPABILITY, 'option_id_missing');
        }

        return OptionGateResult::allow('capability');
    }

    private function permissionGate(OptionOrderIntent $intent, array $cfg, array $context): OptionGateResult
    {
        $account = $context['account'] ?? null;
        if (! is_array($account)) {
            return OptionGateResult::block('permission', self::BLOCK_PERMISSION, 'account_capability_unverified');
        }

        $pinned = (string) ($cfg['agentic_account'] ?? '');
        if ($pinned === '' || (string) ($account['account_number'] ?? '') !== $pinned) {
            return OptionGateResult::block('permission', self::BLOCK_PERMISSION, 'account_pin_mismatch', [
                'expected' => $pinned,
            ]);
        }

        if (($account['agentic_allowed'] ?? null) !== true) {
            return OptionGateResult::block('permission', self::BLOCK_PERMISSION, 'account_not_agentic_allowed');
        }

        if (! in_array($account['option_level'] ?? '', self::ALLOWED_OPTION_LEVELS, true)) {
            return OptionGateResult::block('permission', self::BLOCK_PERMISSION, 'option_level_insufficient', [
                'option_level' => $account['option_level'] ?? null,
            ]);
        }

        return OptionGateResult::allow('permission', ['option_level' => $account['option_level']]);
    }

    private function strategyGate(OptionOrderIntent $intent): OptionGateResult
    {
        if (! in_array($intent->positionEffect, ['open', 'close'], true)) {
            return OptionGateResult::block('strategy', self::BLOCK_PERMISSION, 'position_effect_invalid');
        }

        // Level 2 single-leg long-only: open = buy (long call/put);
        // close = sell (sell-to-close). Anything else is out of scope.
        if ($intent->positionEffect === 'open' && $intent->side !== 'buy') {
            return OptionGateResult::block('strategy', self::BLOCK_PERMISSION, 'strategy_not_allowed_short_open');
        }
        if ($intent->positionEffect === 'close' && $intent->side !== 'sell') {
            return OptionGateResult::block('strategy', self::BLOCK_PERMISSION, 'strategy_not_allowed_buy_close');
        }
        if (! in_array($intent->optionType, ['call', 'put'], true)) {
            return OptionGateResult::block('strategy', self::BLOCK_PERMISSION, 'option_type_invalid');
        }
        if ($intent->quantity < 1) {
            return OptionGateResult::block('strategy', self::BLOCK_PERMISSION, 'quantity_invalid');
        }

        return OptionGateResult::allow('strategy', [
            'side' => $intent->side,
            'position_effect' => $intent->positionEffect,
        ]);
    }

    private function sessionGate(OptionOrderIntent $intent, array $context): OptionGateResult
    {
        $sessionOpen = $context['session_open'] ?? null;
        if ($sessionOpen !== true) {
            return OptionGateResult::block(
                'session',
                self::BLOCK_MARKET_CLOSED,
                $sessionOpen === false ? 'session_closed' : 'session_state_unverified',
            );
        }

        if ($intent->marketHours !== 'regular_hours') {
            return OptionGateResult::block('session', self::BLOCK_MARKET_CLOSED, 'non_regular_hours_not_supported_v0', [
                'market_hours' => $intent->marketHours,
            ]);
        }

        return OptionGateResult::allow('session');
    }

    private function quoteFreshnessGate(array $cfg, array $context): OptionGateResult
    {
        $quote = $context['quote'] ?? null;
        if (! is_array($quote)) {
            return OptionGateResult::block('quote_freshness', self::BLOCK_STALE_QUOTE, 'quote_unverified');
        }

        if (($quote['bid'] ?? null) === null || ($quote['ask'] ?? null) === null) {
            return OptionGateResult::block('quote_freshness', self::BLOCK_STALE_QUOTE, 'bid_or_ask_missing');
        }

        $maxAge = (int) (($cfg['options']['min_quote_fresh_seconds'] ?? 10));
        $age = $quote['age_seconds'] ?? null;
        if ($age === null || ($quote['stale'] ?? true) === true || (float) $age > $maxAge) {
            return OptionGateResult::block('quote_freshness', self::BLOCK_STALE_QUOTE, 'quote_stale', [
                'age_seconds' => $age,
                'gate_seconds' => $maxAge,
            ]);
        }

        return OptionGateResult::allow('quote_freshness', ['age_seconds' => $age]);
    }

    private function spreadGate(OptionOrderIntent $intent, array $cfg, array $context): OptionGateResult
    {
        $quote = $context['quote'] ?? null;
        if (! is_array($quote) || ($quote['spread_bps'] ?? null) === null) {
            if ($intent->side === 'sell' && $intent->positionEffect === 'close') {
                return OptionGateResult::allow('spread', [
                    'spread_bps' => $quote['spread_bps'] ?? null,
                    'liquidation_bypass' => true,
                ]);
            }

            return OptionGateResult::block('spread', self::BLOCK_WIDE_SPREAD, 'spread_unverified');
        }

        $options = (array) ($cfg['options'] ?? []);
        $maxSpreadBps = (float) ($options['max_spread_bps'] ?? 1500);
        if ((float) $quote['spread_bps'] > $maxSpreadBps) {
            if ($intent->side === 'sell' && $intent->positionEffect === 'close') {
                return OptionGateResult::allow('spread', [
                    'spread_bps' => $quote['spread_bps'],
                    'max_spread_bps' => $maxSpreadBps,
                    'liquidation_bypass' => true,
                ]);
            }

            return OptionGateResult::block('spread', self::BLOCK_WIDE_SPREAD, 'spread_above_max', [
                'spread_bps' => $quote['spread_bps'],
                'max_spread_bps' => $maxSpreadBps,
            ]);
        }

        $maxAsk = (float) ($options['max_contract_ask'] ?? 5.0);
        if (($quote['ask'] ?? null) !== null && (float) $quote['ask'] > $maxAsk) {
            if ($intent->side === 'sell' && $intent->positionEffect === 'close') {
                return OptionGateResult::allow('spread', [
                    'ask' => $quote['ask'],
                    'max_contract_ask' => $maxAsk,
                    'spread_bps' => $quote['spread_bps'],
                    'liquidation_bypass' => true,
                ]);
            }

            return OptionGateResult::block('spread', self::BLOCK_WIDE_SPREAD, 'ask_above_max', [
                'ask' => $quote['ask'],
                'max_contract_ask' => $maxAsk,
            ]);
        }

        return OptionGateResult::allow('spread', ['spread_bps' => $quote['spread_bps']]);
    }

    private function marketabilityGate(OptionOrderIntent $intent, array $context): OptionGateResult
    {
        if ($intent->orderType !== 'limit') {
            return OptionGateResult::allow('marketability', ['order_type' => $intent->orderType]);
        }

        if ($intent->limitPrice === null) {
            return OptionGateResult::block('marketability', self::BLOCK_PRICE, 'limit_price_missing');
        }

        $quote = $context['quote'] ?? null;
        if (! is_array($quote)) {
            return OptionGateResult::block('marketability', self::BLOCK_PRICE, 'quote_unverified');
        }

        $limit = (float) $intent->limitPrice;
        $bid = isset($quote['bid']) ? (float) $quote['bid'] : null;
        $ask = isset($quote['ask']) ? (float) $quote['ask'] : null;

        if ($intent->side === 'buy') {
            if ($ask === null || $ask <= 0.0) {
                return OptionGateResult::block('marketability', self::BLOCK_PRICE, 'ask_unverified');
            }
            if ($limit + 0.000001 < $ask) {
                return OptionGateResult::block('marketability', self::BLOCK_PRICE, 'buy_limit_below_live_ask', [
                    'limit_price' => $limit,
                    'ask' => $ask,
                ]);
            }
        }

        if ($intent->side === 'sell') {
            if ($bid === null || $bid <= 0.0) {
                return OptionGateResult::block('marketability', self::BLOCK_PRICE, 'bid_unverified');
            }
            if ($limit - 0.000001 > $bid) {
                return OptionGateResult::block('marketability', self::BLOCK_PRICE, 'sell_limit_above_live_bid', [
                    'limit_price' => $limit,
                    'bid' => $bid,
                ]);
            }
        }

        return OptionGateResult::allow('marketability', [
            'limit_price' => $limit,
            'bid' => $bid,
            'ask' => $ask,
        ]);
    }

    private function capsGate(OptionOrderIntent $intent, array $cfg, array $context): OptionGateResult
    {
        if ($intent->side !== 'buy') {
            return OptionGateResult::allow('caps', ['side' => $intent->side]);
        }

        $debit = $intent->estimatedDebit();
        if ($debit === null) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'debit_unbounded_limit_price_required');
        }

        $options = (array) ($cfg['options'] ?? []);

        $perPlay = (float) ($options['per_play_max_debit'] ?? 0.0);
        if ($perPlay <= 0) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'per_play_cap_unconfigured');
        }
        if ($debit > $perPlay) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'per_play_cap_exceeded', [
                'debit' => $debit,
                'per_play_max_debit' => $perPlay,
            ]);
        }

        // Every cap is mandatory for opens: an unconfigured cap blocks
        // (fail closed), it never silently skips.
        $perDay = (float) ($options['per_day_max_debit'] ?? 0.0);
        if ($perDay <= 0) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'per_day_cap_unconfigured');
        }
        $openDebit = $context['options_open_debit'] ?? null;
        if ($openDebit === null) {
            $underlyingDebitsForTotal = $context['underlying_open_debit'] ?? null;
            $openDebit = is_array($underlyingDebitsForTotal) ? array_sum($underlyingDebitsForTotal) : null;
        }
        if ($openDebit === null) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'options_open_debit_unverified');
        }
        if ((float) $openDebit + $debit > $perDay) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'per_day_cap_exceeded', [
                'open_debit' => $openDebit,
                'debit' => $debit,
                'per_day_max_debit' => $perDay,
            ]);
        }

        $dailyGross = (float) ($options['daily_gross_entry_debit'] ?? 0.0);
        if ($dailyGross <= 0) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'daily_gross_entry_debit_unconfigured');
        }
        $spentToday = $context['options_spent_today_debit'] ?? null;
        if ($spentToday === null) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'options_spent_today_unverified');
        }
        if ((float) $spentToday + $debit > $dailyGross) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'daily_gross_entry_debit_exceeded', [
                'spent_today_debit' => $spentToday,
                'debit' => $debit,
                'daily_gross_entry_debit' => $dailyGross,
            ]);
        }

        $perUnderlying = (float) ($options['per_underlying_max_debit'] ?? 0.0);
        if ($perUnderlying <= 0) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'per_underlying_cap_unconfigured');
        }
        $underlyingDebits = $context['underlying_open_debit'] ?? null;
        if (! is_array($underlyingDebits)) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'underlying_open_debit_unverified');
        }
        $open = (float) ($underlyingDebits[strtoupper($intent->underlyingSymbol)] ?? 0.0);
        if ($open + $debit > $perUnderlying) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'per_underlying_cap_exceeded', [
                'underlying_open_debit' => $open,
                'debit' => $debit,
                'per_underlying_max_debit' => $perUnderlying,
            ]);
        }

        if ($intent->isOpening()) {
            $maxOpen = (int) ($options['max_open_positions'] ?? 0);
            if ($maxOpen <= 0) {
                return OptionGateResult::block('caps', self::BLOCK_CAP, 'max_open_positions_unconfigured');
            }
            $openPositions = $context['open_option_positions'] ?? null;
            if (! is_array($openPositions)) {
                return OptionGateResult::block('caps', self::BLOCK_CAP, 'open_positions_unverified');
            }
            if (count($openPositions) >= $maxOpen) {
                return OptionGateResult::block('caps', self::BLOCK_CAP, 'max_open_positions_reached', [
                    'open' => count($openPositions),
                    'max' => $maxOpen,
                ]);
            }
        }

        $settledCash = $context['settled_cash'] ?? null;
        if ($settledCash === null) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'settled_cash_unverified');
        }
        if ($debit > (float) $settledCash) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'settled_cash_insufficient', [
                'settled_cash' => $settledCash,
                'debit' => $debit,
            ]);
        }

        $buyingPower = $context['buying_power'] ?? null;
        if ($buyingPower === null) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'buying_power_unverified');
        }
        if ($debit > (float) $buyingPower) {
            return OptionGateResult::block('caps', self::BLOCK_CAP, 'buying_power_insufficient', [
                'buying_power' => $buyingPower,
                'debit' => $debit,
            ]);
        }

        return OptionGateResult::allow('caps', [
            'debit' => $debit,
            'settled_cash' => $settledCash,
            'buying_power' => $buyingPower,
        ]);
    }

    private function duplicateGate(OptionOrderIntent $intent, array $context): OptionGateResult
    {
        $existingRef = $context['existing_ref'] ?? null;
        if (is_array($existingRef)) {
            $status = $existingRef['status'] ?? null;
            if ($status === 'placed') {
                return OptionGateResult::block('duplicate', self::BLOCK_DUPLICATE, 'ref_already_placed', [
                    'client_ref_id' => $existingRef['client_ref_id'] ?? null,
                ]);
            }
            if ($status !== null && $status !== 'reserved') {
                return OptionGateResult::block('duplicate', self::BLOCK_DUPLICATE, 'ref_attempted_requires_reconcile', [
                    'ref_status' => $status,
                ]);
            }
        }

        $optionId = $intent->optionId ?? '';
        foreach ((array) ($context['open_option_orders'] ?? []) as $order) {
            $state = strtolower((string) ($order['state'] ?? $order['status'] ?? ''));
            if (! in_array($state, self::OPEN_ORDER_STATES, true)) {
                continue;
            }

            foreach ((array) ($order['legs'] ?? []) as $leg) {
                if ($optionId !== '' && (string) ($leg['option_id'] ?? '') === $optionId) {
                    return OptionGateResult::block('duplicate', self::BLOCK_DUPLICATE, 'duplicate_open_order_same_option', [
                        'option_id' => $optionId,
                        'existing_order_id' => $order['id'] ?? null,
                        'state' => $state,
                    ]);
                }
            }
        }

        return OptionGateResult::allow('duplicate');
    }

    private function reviewGate(array $context): OptionGateResult
    {
        $review = $context['review'] ?? null;
        if (! is_array($review)) {
            return OptionGateResult::block('review', self::BLOCK_REVIEW_ALERT, 'review_missing');
        }

        if (($review['ok'] ?? null) === false || ($review['is_error'] ?? null) === true) {
            return OptionGateResult::block('review', self::BLOCK_REVIEW_ALERT, 'review_rejected');
        }

        foreach ((array) ($review['alerts'] ?? []) as $alert) {
            $severity = is_array($alert) ? strtolower((string) ($alert['severity'] ?? '')) : '';
            $text = strtolower(is_array($alert) ? (string) ($alert['message'] ?? json_encode($alert)) : (string) $alert);

            if (in_array($severity, ['error', 'blocker', 'critical'], true)) {
                return OptionGateResult::block('review', self::BLOCK_REVIEW_ALERT, 'review_alert_blocking', ['alert' => $alert]);
            }
            foreach (self::BLOCKING_REVIEW_PATTERNS as $pattern) {
                if (str_contains($text, $pattern)) {
                    return OptionGateResult::block('review', self::BLOCK_REVIEW_ALERT, 'review_alert_blocking', ['alert' => $alert]);
                }
            }
        }

        return OptionGateResult::allow('review', ['alerts_seen' => count((array) ($review['alerts'] ?? []))]);
    }
}
