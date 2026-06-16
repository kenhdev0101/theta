<?php

declare(strict_types=1);

namespace App\Cockpit;

/**
 * The cockpit's anti-ambiguity layer.
 *
 * Every enum the harness writes to the journal, the JSONL telemetry streams,
 * or the broker-truth reconciler is decoded here into a single shape the UI
 * can render directly:
 *
 *   ['value' => raw, 'label' => human, 'description' => tooltip,
 *    'tone' => good|bad|warn|active|info|neutral, 'known' => bool]
 *
 * The operator must never have to read a raw enum and go hunt the code path
 * that produced it. An undocumented value still decodes (humanized verbatim,
 * tone "info", known=false) so the cockpit degrades to honest, not broken.
 *
 * Vocabulary sources are cited per block so this file stays in lockstep with
 * the producers: OptionRailPipeline / equity Rails\Gates (block reasons),
 * BrokerTruthReconciler (classifications), the journal status/intent columns,
 * and the tools/equity-structure/* structure-engine taxonomy.
 */
final class Lexicon
{
    public const TONE_GOOD = 'good';

    public const TONE_BAD = 'bad';

    public const TONE_WARN = 'warn';

    public const TONE_ACTIVE = 'active';

    public const TONE_INFO = 'info';

    public const TONE_NEUTRAL = 'neutral';

    // ── Journal lifecycle status ─────────────────────────────────────────
    private const STATUS = [
        'candidate' => ['Candidate', 'Surfaced by the scanner. No risk check, no broker contact yet.', self::TONE_NEUTRAL],
        'watchlisted' => ['Watchlisted', 'Held for monitoring; not staged for an order.', self::TONE_NEUTRAL],
        'reviewed' => ['Reviewed', 'Cleared the risk rails and a broker review. Ready to place.', self::TONE_INFO],
        'autopilot_claimed' => ['Autopilot claimed', 'Picked up by the autopilot this cycle for placement.', self::TONE_INFO],
        'submitted' => ['Submitted', 'Sent to the broker. Awaiting acknowledgement / fill.', self::TONE_ACTIVE],
        'open' => ['Working', 'Live at the broker, resting unfilled.', self::TONE_ACTIVE],
        'partially_filled' => ['Partially filled', 'Some contracts/shares filled; remainder still working.', self::TONE_ACTIVE],
        'filled' => ['Filled', 'Fully executed and confirmed by broker truth.', self::TONE_GOOD],
        'closed' => ['Closed', 'Position exited and flat.', self::TONE_NEUTRAL],
        'cancelled' => ['Cancelled', 'Order cancelled before it filled.', self::TONE_NEUTRAL],
        'rejected' => ['Rejected', 'Broker rejected the order.', self::TONE_BAD],
        'expired' => ['Expired', 'Order expired without filling.', self::TONE_NEUTRAL],
        'risk_aborted' => ['Risk-aborted', 'A risk rail blocked it before the broker. Not a market outcome.', self::TONE_WARN],
        'scan_suppressed' => ['Scan-suppressed', 'Producer scan was rate-limited this cycle; not advanced.', self::TONE_NEUTRAL],
        'execution_deferred' => ['Execution deferred', 'A valid, evaluated setup held back only by capital (settled cash / buying power / caps). It re-promotes automatically once capital frees — evaluation never depended on capital.', self::TONE_INFO],
    ];

    // ── Trading intent / direction ───────────────────────────────────────
    private const INTENT = [
        'long_call' => ['Long call', 'Buy-to-open a call — bullish, defined risk.', self::TONE_GOOD],
        'long_put' => ['Long put', 'Buy-to-open a put — bearish, defined risk.', self::TONE_BAD],
        'close_long_call' => ['Close call', 'Sell-to-close a long call (exit).', self::TONE_NEUTRAL],
        'close_long_put' => ['Close put', 'Sell-to-close a long put (exit).', self::TONE_NEUTRAL],
        'long_equity' => ['Buy equity', 'Buy shares long.', self::TONE_GOOD],
        'reduce_equity' => ['Sell equity', 'Sell shares to reduce/close a long.', self::TONE_NEUTRAL],
        'short_equity' => ['Short equity', 'Short shares (not permitted in the cash account).', self::TONE_BAD],
        'watch_only' => ['Watch only', 'Tracked, never routed to the broker.', self::TONE_NEUTRAL],
    ];

    // ── Broker-truth execution classification (BrokerTruthReconciler) ─────
    private const CLASSIFICATION = [
        'LIVE_FILLED' => ['Filled', 'Broker-confirmed fill: qty > 0 and price > 0.', self::TONE_GOOD],
        'LIVE_POSTED_NOT_FILLED' => ['Working', 'Live at the broker, posted but not yet filled.', self::TONE_ACTIVE],
        'LIVE_REJECTED' => ['Rejected', 'Broker rejected or failed the order.', self::TONE_BAD],
        'LIVE_CANCELLED' => ['Cancelled', 'Cancelled at the broker with no fill.', self::TONE_NEUTRAL],
        'LIVE_RISK_ABORTED' => ['Risk-aborted', 'Stopped by a risk rail before the broker. Not a market outcome.', self::TONE_WARN],
        'TEST_FIXTURE_ONLY' => ['Test fixture', 'Simulated/dry-run row. Not a market outcome.', self::TONE_NEUTRAL],
        'BROKER_CAPABILITY_PENDING' => ['Capability pending', 'Waiting on a broker capability. Not a market outcome.', self::TONE_NEUTRAL],
        'LABEL_CONFLICT_NOT_BROKER_FILLED' => ['Label conflict', 'Row says filled but broker truth shows no fill. Trace the producer.', self::TONE_BAD],
        'UNKNOWN_EXECUTION_STATE' => ['Unknown state', 'Execution state could not be classified — investigate.', self::TONE_WARN],
    ];

    // ── Telemetry event types (decisions feed) ───────────────────────────
    private const EVENT = [
        'placed' => ['Order placed', 'A live order was submitted to the broker.', self::TONE_ACTIVE],
        'place_failed' => ['Placement failed', 'The broker place call errored.', self::TONE_BAD],
        'rail_block' => ['Risk rail blocked', 'A risk rail stopped the order before placement.', self::TONE_WARN],
        'review_probe' => ['Review probe', 'A no-place broker review check.', self::TONE_INFO],
        'placed_missing_broker_order_id' => ['Placed, no order id', 'Placement returned without a broker order id — reconcile.', self::TONE_BAD],
        'option_reconcile' => ['Reconcile', 'Broker option orders reconciled against the journal.', self::TONE_INFO],
        'reconcile' => ['Reconcile', 'Broker equity orders reconciled against the journal.', self::TONE_INFO],
        'capability_readback' => ['Capability read', 'Broker option capability surface checked.', self::TONE_INFO],
        'readback' => ['Broker readback', 'Account/portfolio truth read back from the broker.', self::TONE_INFO],
        'chain_readback' => ['Chain read', 'Option chain metadata read back.', self::TONE_INFO],
        'contract_readback' => ['Contract read', 'Option contract + quote read back.', self::TONE_INFO],
        'smoke_test' => ['Data smoke test', 'Market-data plane connectivity check.', self::TONE_INFO],
        'smoke_test_failed' => ['Data smoke test failed', 'Market-data plane connectivity check failed.', self::TONE_BAD],
        'cockpit_snapshot' => ['Cockpit snapshot', 'Broker-truth snapshot captured for the cockpit.', self::TONE_INFO],
        'snapshot_failed' => ['Snapshot failed', 'Broker-truth snapshot capture failed.', self::TONE_BAD],
    ];

    // ── Rail / gate identifiers ──────────────────────────────────────────
    private const GATE = [
        'kill_switch' => ['Kill switch', 'Global arm flag.'],
        'capability' => ['Capability', 'Contract resolves to a tradable broker instrument.'],
        'permission' => ['Permission', 'Account pin + option level + agentic-allowed.'],
        'strategy' => ['Strategy', 'Single-leg long-only shape allowed.'],
        'session' => ['Session', 'Market session / tradability.'],
        'quote_freshness' => ['Quote freshness', 'Live quote present and fresh.'],
        'spread' => ['Spread', 'Bid/ask spread and ask price within bounds.'],
        'marketability' => ['Marketability', 'Limit price will actually fill against the live quote.'],
        'caps' => ['Caps', 'Per-play / per-day / per-symbol debit and position caps.'],
        'sizing' => ['Sizing', 'Per-play debit bound.'],
        'allocation' => ['Allocation', 'Per-day / per-symbol caps and open-position limit.'],
        'settled_cash' => ['Settled cash', 'Settled cash covers the debit.'],
        'idempotency' => ['Idempotency', 'No duplicate of an already-placed ref.'],
        'duplicate' => ['Duplicate', 'No duplicate open order for the same contract.'],
        'review' => ['Review', 'Broker order review is clean.'],
    ];

    // ── Block reasons (exact verbatim producers) ─────────────────────────
    // Keyed by the literal reason string. Dynamic reasons (prefix: detail)
    // are matched on the prefix and the detail is appended at lookup time.
    private const BLOCK_REASON = [
        // kill switch
        'kill_switch_engaged' => ['Kill switch engaged', 'Placement is globally disabled by the kill switch.', self::TONE_BAD],

        // capability gate
        'options_disabled_in_config' => ['Options disabled', 'The options lane is turned off in config.', self::TONE_NEUTRAL],
        'broker_tools_missing' => ['Broker tools missing', 'The broker is not advertising the required option tools.', self::TONE_BAD],
        'contract_unresolved' => ['Contract unresolved', 'No tradable contract could be resolved for this intent.', self::TONE_WARN],
        'contract_untradable' => ['Contract untradable', 'The resolved contract is not tradable right now.', self::TONE_WARN],
        'contract_not_active' => ['Contract inactive', 'The resolved contract is not in an active state.', self::TONE_WARN],
        'option_id_missing' => ['No option id', 'The resolved contract carries no broker option_id.', self::TONE_WARN],

        // permission gate
        'account_capability_unverified' => ['Capability unverified', 'Account option capability could not be verified.', self::TONE_WARN],
        'account_pin_mismatch' => ['Account pin mismatch', 'Order account differs from the pinned agentic account.', self::TONE_BAD],
        'account_not_agentic_allowed' => ['Not agentic-allowed', 'The account is not flagged agentic-allowed.', self::TONE_BAD],
        'option_level_insufficient' => ['Option level too low', 'Account option level is below Level 2.', self::TONE_BAD],

        // strategy gate
        'position_effect_invalid' => ['Bad position effect', 'Position effect must be open or close.', self::TONE_WARN],
        'strategy_not_allowed_short_open' => ['Short-open blocked', 'Selling to open is not allowed (long-only).', self::TONE_BAD],
        'strategy_not_allowed_buy_close' => ['Buy-close blocked', 'Buying to close is not allowed (single-leg long-only).', self::TONE_BAD],
        'option_type_invalid' => ['Bad option type', 'Option type must be call or put.', self::TONE_WARN],
        'quantity_invalid' => ['Bad quantity', 'Order quantity is invalid.', self::TONE_WARN],

        // session gate
        'session_closed' => ['Market closed', 'The market session is closed.', self::TONE_NEUTRAL],
        'session_state_unverified' => ['Session unverified', 'Market session state could not be verified.', self::TONE_WARN],
        'non_regular_hours_not_supported_v0' => ['Regular hours only', 'Options only route in regular hours in this version.', self::TONE_NEUTRAL],
        'tradability_unverified' => ['Tradability unverified', 'Symbol tradability could not be verified.', self::TONE_WARN],
        'not_tradable' => ['Not tradable', 'The symbol is not tradable right now.', self::TONE_WARN],
        'dollar_order_outside_regular_hours' => ['Dollar order off-hours', 'Dollar-notional orders are blocked outside regular hours.', self::TONE_NEUTRAL],

        // quote freshness gate
        'quote_unverified' => ['Quote unverified', 'A live quote could not be verified.', self::TONE_WARN],
        'bid_or_ask_missing' => ['Bid/ask missing', 'The quote is missing a bid or an ask.', self::TONE_WARN],
        'quote_stale' => ['Quote stale', 'The quote is older than the freshness window.', self::TONE_WARN],

        // spread gate
        'spread_unverified' => ['Spread unverified', 'The bid/ask spread could not be computed.', self::TONE_WARN],
        'spread_above_max' => ['Spread too wide', 'Bid/ask spread exceeds the configured maximum.', self::TONE_WARN],
        'ask_above_max' => ['Ask too high', 'Contract ask exceeds the max contract price.', self::TONE_WARN],

        // marketability gate
        'limit_price_missing' => ['No limit price', 'The order has no limit price set.', self::TONE_WARN],
        'ask_unverified' => ['Ask unverified', 'Ask could not be verified for marketability.', self::TONE_WARN],
        'buy_limit_below_live_ask' => ['Buy limit too low', 'Buy limit is below the live ask — it would not fill.', self::TONE_WARN],
        'bid_unverified' => ['Bid unverified', 'Bid could not be verified for marketability.', self::TONE_WARN],
        'sell_limit_above_live_bid' => ['Sell limit too high', 'Sell limit is above the live bid — it would not fill.', self::TONE_WARN],

        // caps gate (options + equity)
        'debit_unbounded_limit_price_required' => ['Unbounded debit', 'Debit is unbounded; a limit price is required.', self::TONE_WARN],
        'debit_unbounded' => ['Unbounded debit', 'Order debit is unbounded.', self::TONE_WARN],
        'debit_not_positive' => ['Non-positive debit', 'Order debit is not a positive number.', self::TONE_WARN],
        'per_play_cap_unconfigured' => ['Per-play cap unset', 'The per-play debit cap is not configured.', self::TONE_WARN],
        'per_play_cap_exceeded' => ['Over per-play cap', 'Order debit exceeds the per-play cap.', self::TONE_NEUTRAL],
        'per_day_cap_unconfigured' => ['Per-day cap unset', 'The per-day debit cap is not configured.', self::TONE_WARN],
        'options_open_debit_unverified' => ['Open debit unverified', 'Open options debit could not be verified.', self::TONE_WARN],
        'per_day_cap_exceeded' => ['Over per-day cap', 'Order would exceed the per-day debit cap.', self::TONE_NEUTRAL],
        'daily_gross_entry_debit_unconfigured' => ['Gross-entry cap unset', 'The daily gross entry debit cap is not configured.', self::TONE_WARN],
        'options_spent_today_unverified' => ['Spent-today unverified', 'Options debit spent today could not be verified.', self::TONE_WARN],
        'daily_gross_entry_debit_exceeded' => ['Over gross-entry cap', "Order would exceed today's gross entry debit cap.", self::TONE_NEUTRAL],
        'per_underlying_cap_unconfigured' => ['Per-underlying cap unset', 'The per-underlying debit cap is not configured.', self::TONE_WARN],
        'underlying_open_debit_unverified' => ['Underlying debit unverified', 'Open debit for this underlying could not be verified.', self::TONE_WARN],
        'per_underlying_cap_exceeded' => ['Over per-underlying cap', 'Order would exceed the per-underlying debit cap.', self::TONE_NEUTRAL],
        'spent_today_unverified' => ['Spent-today unverified', 'Amount spent today could not be verified.', self::TONE_WARN],
        'symbol_open_debit_unverified' => ['Symbol debit unverified', 'Open debit for this symbol could not be verified.', self::TONE_WARN],
        'per_symbol_cap_exceeded' => ['Over per-symbol cap', 'Order would exceed the per-symbol cap.', self::TONE_NEUTRAL],
        'max_open_positions_unconfigured' => ['Max-positions unset', 'The max open positions limit is not configured.', self::TONE_WARN],
        'open_positions_unverified' => ['Open count unverified', 'The open position count could not be verified.', self::TONE_WARN],
        'max_open_positions_reached' => ['Max positions reached', 'The max open position limit has been reached.', self::TONE_NEUTRAL],
        'settled_cash_unverified' => ['Settled cash unverified', 'Settled cash could not be verified — gates fail closed.', self::TONE_WARN],
        'settled_cash_insufficient' => ['Settled cash short', 'Insufficient settled cash for this order.', self::TONE_NEUTRAL],
        'buying_power_unverified' => ['Buying power unverified', 'Buying power could not be verified.', self::TONE_WARN],
        'buying_power_insufficient' => ['Buying power short', 'Insufficient buying power for this order.', self::TONE_NEUTRAL],

        // duplicate / idempotency gate
        'ref_already_placed' => ['Already placed', 'This order ref was already placed (idempotency guard).', self::TONE_NEUTRAL],
        'ref_attempted_requires_reconcile' => ['Needs reconcile', 'A prior placement attempt must be reconciled first.', self::TONE_WARN],
        'duplicate_open_order_same_option' => ['Duplicate open order', 'An open order already exists for this contract.', self::TONE_NEUTRAL],
        'duplicate_open_order' => ['Duplicate open order', 'An open order already exists for this symbol.', self::TONE_NEUTRAL],

        // review gate
        'review_missing' => ['No review', 'A broker order review is missing.', self::TONE_WARN],
        'review_rejected' => ['Review rejected', 'The broker rejected the order during review.', self::TONE_BAD],
        'review_alert_blocking' => ['Review alert', 'The broker review returned a blocking alert.', self::TONE_WARN],

        // autopilot orchestration (prefix-style)
        'journal_intent_incomplete' => ['Intent incomplete', 'The journal intent row is missing required fields.', self::TONE_WARN],
        'resolver_mechanical_blocker' => ['Resolver blocked', 'The contract resolver hit a mechanical blocker.', self::TONE_WARN],
        'fresh_quote_unmarketable_or_incomplete' => ['Quote unmarketable', 'The fresh quote was unmarketable or incomplete.', self::TONE_WARN],
        'broker_truth_mechanical_blocker' => ['Broker read blocked', 'A broker-truth read hit a mechanical blocker.', self::TONE_WARN],
        'placement_mechanical_failure' => ['Placement error', 'Order placement hit a mechanical failure.', self::TONE_BAD],
        'rail_block' => ['Risk rail block', 'Blocked by a risk rail.', self::TONE_WARN],

        // execution deferral (capital-only — evaluation still happened)
        'execution_deferred_capital' => ['Execution deferred (capital)', 'Setup is valid and evaluated; execution is held only by capital availability.', self::TONE_INFO],

        // producer entry/exit (prefix-style)
        'data_plane_mechanical_blocker' => ['Data plane blocked', 'The market-data plane hit a mechanical blocker.', self::TONE_WARN],
        'underlying_quote_stale_or_incomplete' => ['Underlying quote stale', 'The underlying quote is stale or incomplete.', self::TONE_WARN],
        'momentum_data_blocker' => ['Momentum data blocked', 'Momentum data could not be read.', self::TONE_WARN],
        'momentum_signal_absent' => ['No momentum', 'No qualifying momentum signal this cycle.', self::TONE_NEUTRAL],
        'broker_truth_blocker' => ['Broker read blocked', 'A broker-truth read was blocked.', self::TONE_WARN],
        'entry_symbol_cooldown' => ['Symbol cooldown', 'This symbol is in its post-entry cooldown.', self::TONE_NEUTRAL],
        'reentry_after_exit_cooldown' => ['Re-entry cooldown', 'A position in this name closed recently; re-entry is paused to prevent open→close→reopen churn.', self::TONE_NEUTRAL],
        'no_marketable_contract' => ['No marketable contract', 'No contract passed the marketability filter.', self::TONE_NEUTRAL],
        'allocation_expected_gain_below_min' => ['Expected gain low', 'Expected gain is below the allocation minimum.', self::TONE_NEUTRAL],
        'review_blocker' => ['Review blocked', 'The broker review blocked the order.', self::TONE_WARN],
        'exit_contract_unresolved' => ['Exit unresolved', 'The exit contract could not be resolved.', self::TONE_WARN],
        'exit_quote_blocker' => ['Exit quote blocked', 'The exit quote was blocked.', self::TONE_WARN],
        'exit_broker_truth_blocker' => ['Exit read blocked', 'An exit broker-truth read was blocked.', self::TONE_WARN],
        'exit_review_blocker' => ['Exit review blocked', 'The exit broker review was blocked.', self::TONE_WARN],

        // exit-signal verdicts
        'exit_quote_stale_or_incomplete' => ['Exit quote stale', 'The exit quote is stale or incomplete.', self::TONE_WARN],
        'entry_price_unverified' => ['Entry price unverified', 'Entry price could not be verified for the exit math.', self::TONE_WARN],
        'exit_profit_target_reached' => ['Profit target hit', 'Profit target reached — exiting.', self::TONE_GOOD],
        'exit_stop_loss_reached' => ['Stop loss hit', 'Stop loss reached — exiting.', self::TONE_BAD],
        'exit_max_hold_reached' => ['Max hold hit', 'Max hold time reached — exiting.', self::TONE_NEUTRAL],
        'exit_max_hold_waiting_for_min_pnl' => ['Holding for min P&L', 'Max hold reached; waiting for minimum P&L before exit.', self::TONE_NEUTRAL],
        'exit_min_hold_floor' => ['Min-hold floor', 'A discretionary exit (profit-target / max-hold) was held back by the minimum-hold floor. Protective stop-loss still fires.', self::TONE_NEUTRAL],
        'exit_thresholds_not_met' => ['Holding', 'No exit threshold met — holding the position.', self::TONE_NEUTRAL],

        // momentum-signal verdicts
        'underlying_spread_above_max' => ['Underlying spread wide', 'The underlying spread is above the maximum.', self::TONE_WARN],
        'insufficient_momentum_bars' => ['Not enough bars', 'Not enough momentum bars to evaluate yet.', self::TONE_NEUTRAL],
        'latest_momentum_bar_stale' => ['Momentum bar stale', 'The latest momentum bar is stale.', self::TONE_WARN],
        'invalid_momentum_bar_price' => ['Bad bar price', 'A momentum bar has an invalid price.', self::TONE_WARN],
        'volume_expansion_below_min' => ['Volume flat', 'Volume expansion is below the minimum.', self::TONE_NEUTRAL],
        'directional_momentum_below_min' => ['Momentum weak', 'Directional momentum is below the minimum.', self::TONE_NEUTRAL],
        'momentum_confirmed' => ['Momentum confirmed', 'A qualifying momentum signal fired.', self::TONE_GOOD],
    ];

    // ── Structure-engine taxonomy (tools/equity-structure/transitions.py) ─
    private const STRUCTURE_CLASS = [
        'single_leg_long' => ['Single-leg long', 'A single long call or put — the only options shape live today.', self::TONE_INFO],
        'neutral' => ['Neutral', 'No clear structural bias; baseline state.', self::TONE_NEUTRAL],
        'compression' => ['Compression', 'Range/volatility contraction — coiled, no drive.', self::TONE_INFO],
        'shelf' => ['Shelf', 'Compression sitting on a defined level; pre-breakout.', self::TONE_INFO],
        'coil_expansion' => ['Coil expansion', 'Breakout from compression/shelf on volume — prime entry.', self::TONE_GOOD],
        'continuation' => ['Continuation', 'Orderly trend pulling back and reclaiming — core entry.', self::TONE_GOOD],
        'acceptance' => ['Acceptance', 'Value building at a level; constructive hold.', self::TONE_INFO],
        'parabolic' => ['Parabolic', 'Vertical, late momentum — manage only, no new entry.', self::TONE_WARN],
        'exhaustion' => ['Exhaustion', 'Climax/reversal signature — exit signal.', self::TONE_BAD],
        'liquidity_vacuum' => ['Liquidity vacuum', 'Wide/thin/gappy — avoidance gate, overrides structure.', self::TONE_BAD],
        'danger' => ['Danger', 'Halt/news or extreme parabolic∩exhaustion — hard avoid.', self::TONE_BAD],
        'danger_zone' => ['Danger zone', 'Halt/news or extreme parabolic∩exhaustion — hard avoid.', self::TONE_BAD],
    ];

    private const RELATIVE_STRENGTH = [
        'outperforming' => ['Outperforming', 'Leading its benchmark with positive RS momentum.', self::TONE_GOOD],
        'underperforming' => ['Underperforming', 'Lagging its benchmark with negative RS momentum.', self::TONE_BAD],
        'mixed' => ['Mixed', 'No clear relative-strength direction.', self::TONE_NEUTRAL],
        'unknown' => ['Unknown', 'Not enough data to compute relative strength.', self::TONE_NEUTRAL],
    ];

    private const GAP = [
        'breakaway' => ['Breakaway gap', 'Large gap on volume out of a coil/range — direction-confirming.', self::TONE_GOOD],
        'exhaustion_risk' => ['Exhaustion-risk gap', 'Very large gap after a trend — reversal risk.', self::TONE_WARN],
        'common_continuation' => ['Continuation gap', 'Moderate gap inside an ongoing trend.', self::TONE_INFO],
        'common_gap' => ['Common gap', 'Gap with no structural confirmation; noise.', self::TONE_NEUTRAL],
    ];

    private const GEOMETRY = [
        'above_acceptance' => ['Above acceptance', 'Price above both key MAs — clean uptrend.', self::TONE_GOOD],
        'below_acceptance' => ['Below acceptance', 'Price below both key MAs — clean downtrend.', self::TONE_BAD],
        'inside_acceptance_shell' => ['Inside shell', 'Price between the MAs — consolidation/rotation.', self::TONE_NEUTRAL],
    ];

    private const LIQUIDITY = [
        'institutional_liquid' => ['Institutional', 'Deepest names, tight spreads — options-ready.', self::TONE_GOOD],
        'tradeable' => ['Tradeable', 'Sufficient liquidity and acceptable spreads.', self::TONE_GOOD],
        'thin_tradeable' => ['Thin', 'Limited liquidity — wider patience, limit discipline.', self::TONE_WARN],
        'avoid_for_options_execution' => ['Avoid', 'Too thin for options execution — high slippage.', self::TONE_BAD],
    ];

    // ── Order mechanics ──────────────────────────────────────────────────
    private const SIDE = [
        'buy' => ['Buy', 'Buy side.', self::TONE_GOOD],
        'sell' => ['Sell', 'Sell side.', self::TONE_NEUTRAL],
    ];

    private const POSITION_EFFECT = [
        'open' => ['Open', 'Opening a new position (buy-to-open).', self::TONE_GOOD],
        'close' => ['Close', 'Closing an existing position (sell-to-close).', self::TONE_NEUTRAL],
    ];

    private const OPTION_TYPE = [
        'call' => ['Call', 'Right to buy — bullish.', self::TONE_GOOD],
        'put' => ['Put', 'Right to sell — bearish.', self::TONE_BAD],
    ];

    private const ORDER_TYPE = [
        'limit' => ['Limit', 'Resting limit order — controls price.', self::TONE_INFO],
        'market' => ['Market', 'Immediate market order — best effort.', self::TONE_WARN],
        'stop_market' => ['Stop market', 'Stop that triggers a market order.', self::TONE_WARN],
        'stop_limit' => ['Stop limit', 'Stop that triggers a limit order.', self::TONE_INFO],
    ];

    private const TIME_IN_FORCE = [
        'gfd' => ['Good for day', 'Expires at the session close.', self::TONE_INFO],
        'gtc' => ['Good til cancelled', 'Rests until filled or cancelled.', self::TONE_INFO],
        'ioc' => ['Immediate or cancel', 'Fill what you can now, cancel the rest.', self::TONE_INFO],
        'fok' => ['Fill or kill', 'Fill in full immediately or cancel.', self::TONE_INFO],
        'opg' => ['At the open', 'Participates in the opening auction.', self::TONE_INFO],
    ];

    private const MARKET_HOURS = [
        'regular_hours' => ['Regular hours', '09:30–16:00 ET regular session.', self::TONE_INFO],
        'extended_hours' => ['Extended hours', 'Pre/after-market session.', self::TONE_WARN],
        'all_day' => ['All day', 'Spans regular and extended sessions.', self::TONE_INFO],
    ];

    public static function status(?string $v): array
    {
        return self::lookup(self::STATUS, $v);
    }

    public static function intent(?string $v): array
    {
        return self::lookup(self::INTENT, $v);
    }

    public static function classification(?string $v): array
    {
        return self::lookup(self::CLASSIFICATION, $v);
    }

    public static function event(?string $v): array
    {
        return self::lookup(self::EVENT, $v);
    }

    public static function structureClass(?string $v): array
    {
        return self::lookup(self::STRUCTURE_CLASS, $v);
    }

    public static function relativeStrength(?string $v): array
    {
        return self::lookup(self::RELATIVE_STRENGTH, $v);
    }

    public static function gap(?string $v): array
    {
        return self::lookup(self::GAP, $v);
    }

    public static function geometry(?string $v): array
    {
        return self::lookup(self::GEOMETRY, $v);
    }

    public static function liquidity(?string $v): array
    {
        return self::lookup(self::LIQUIDITY, $v);
    }

    public static function side(?string $v): array
    {
        return self::lookup(self::SIDE, $v);
    }

    public static function positionEffect(?string $v): array
    {
        return self::lookup(self::POSITION_EFFECT, $v);
    }

    public static function optionType(?string $v): array
    {
        return self::lookup(self::OPTION_TYPE, $v);
    }

    public static function orderType(?string $v): array
    {
        return self::lookup(self::ORDER_TYPE, $v);
    }

    public static function timeInForce(?string $v): array
    {
        return self::lookup(self::TIME_IN_FORCE, $v);
    }

    public static function marketHours(?string $v): array
    {
        return self::lookup(self::MARKET_HOURS, $v);
    }

    public static function gate(?string $v): array
    {
        $key = strtolower(trim((string) $v));
        if ($key !== '' && isset(self::GATE[$key])) {
            [$label, $description] = self::GATE[$key];

            return self::term($key, $label, $description, self::TONE_INFO, true);
        }

        return self::fallback($v);
    }

    /**
     * Decode a block reason. Reasons are often "prefix: dynamic detail"
     * (e.g. "rail_block: BLOCK_WIDE_SPREAD", "momentum_signal_absent:
     * directional_momentum_below_min"). Match on the exact string first, then
     * on the prefix, and fold the detail into the description so nothing is
     * lost — but the operator never sees the raw token alone.
     */
    public static function blockReason(?string $v): array
    {
        $raw = trim((string) $v);
        if ($raw === '') {
            return self::fallback($v);
        }

        if (isset(self::BLOCK_REASON[$raw])) {
            [$label, $description, $tone] = self::BLOCK_REASON[$raw];

            return self::term($raw, $label, $description, $tone, true);
        }

        $prefix = trim(strstr($raw, ':', true) ?: $raw);
        $detail = trim((string) substr(strstr($raw, ':') ?: '', 1));

        if ($prefix !== '' && isset(self::BLOCK_REASON[$prefix])) {
            [$label, $description, $tone] = self::BLOCK_REASON[$prefix];

            // The detail is frequently itself a known reason — decode it too.
            if ($detail !== '') {
                $nested = self::BLOCK_REASON[$detail] ?? null;
                $detailHuman = $nested !== null ? $nested[0] : self::humanize($detail);
                $description = rtrim($description, '.').': '.$detailHuman.'.';
            }

            return self::term($raw, $label, $description, $tone, true);
        }

        return self::fallback($v);
    }

    public static function mode(?string $v): array
    {
        $key = strtolower(trim((string) $v));

        return match ($key) {
            'auto' => self::term('auto', 'Auto', 'Cleared candidates place automatically — no per-order approval.', self::TONE_ACTIVE, true),
            'approval' => self::term('approval', 'Approval', 'Candidates stage and wait for explicit approval before placement.', self::TONE_INFO, true),
            default => self::fallback($v),
        };
    }

    /**
     * The kill switch is a boolean where true == placement DISABLED. Decoded
     * here so the cockpit never shows a bare true/false for a safety flag.
     */
    public static function killSwitch(bool $engaged): array
    {
        return $engaged
            ? self::term('engaged', 'Engaged', 'Placement is globally blocked. The harness will alert, not trade.', self::TONE_BAD, true)
            : self::term('disengaged', 'Disengaged', 'Placement is allowed (subject to all other rails).', self::TONE_GOOD, true);
    }

    /**
     * All category maps, for tests and tooling. Each value is the same map
     * the per-category methods read.
     *
     * @return array<string, array<string, array{0:string,1:string,2:string}>>
     */
    public static function catalog(): array
    {
        return [
            'status' => self::STATUS,
            'intent' => self::INTENT,
            'classification' => self::CLASSIFICATION,
            'event' => self::EVENT,
            'block_reason' => self::BLOCK_REASON,
            'structure_class' => self::STRUCTURE_CLASS,
            'relative_strength' => self::RELATIVE_STRENGTH,
            'gap' => self::GAP,
            'geometry' => self::GEOMETRY,
            'liquidity' => self::LIQUIDITY,
            'side' => self::SIDE,
            'position_effect' => self::POSITION_EFFECT,
            'option_type' => self::OPTION_TYPE,
            'order_type' => self::ORDER_TYPE,
            'time_in_force' => self::TIME_IN_FORCE,
            'market_hours' => self::MARKET_HOURS,
        ];
    }

    /**
     * @param  array<string, array{0:string,1:string,2:string}>  $map
     * @return array{value:?string,label:string,description:string,tone:string,known:bool}
     */
    private static function lookup(array $map, ?string $v): array
    {
        $key = trim((string) $v);
        if ($key !== '' && isset($map[$key])) {
            [$label, $description, $tone] = $map[$key];

            return self::term($key, $label, $description, $tone, true);
        }

        // Some columns store lowercase variants of broker payloads.
        $lower = strtolower($key);
        if ($lower !== $key && isset($map[$lower])) {
            [$label, $description, $tone] = $map[$lower];

            return self::term($key, $label, $description, $tone, true);
        }

        return self::fallback($v);
    }

    /**
     * @return array{value:?string,label:string,description:string,tone:string,known:bool}
     */
    private static function term(?string $value, string $label, string $description, string $tone, bool $known): array
    {
        return [
            'value' => $value,
            'label' => $label,
            'description' => $description,
            'tone' => $tone,
            'known' => $known,
        ];
    }

    /**
     * @return array{value:?string,label:string,description:string,tone:string,known:bool}
     */
    private static function fallback(?string $v): array
    {
        $raw = trim((string) $v);
        if ($raw === '') {
            return self::term(null, '—', 'Not set.', self::TONE_NEUTRAL, false);
        }

        return self::term(
            $raw,
            self::humanize($raw),
            'Undocumented value, shown verbatim: "'.$raw.'". If this recurs, add it to the cockpit Lexicon.',
            self::TONE_INFO,
            false,
        );
    }

    private static function humanize(string $raw): string
    {
        $clean = trim(str_replace(['_', '-', ':'], ' ', $raw));
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        // Normalize shouting enums (BLOCK_WIDE_SPREAD) to sentence case.
        $clean = strtolower($clean);

        return $clean === '' ? $raw : ucfirst($clean);
    }
}
