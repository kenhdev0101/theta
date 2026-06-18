<?php

// theta harness configuration. Every command/job/service that consumes these
// values must propagate the FULL relevant block into its $cfg assembly —
// cherry-picked keys are the firm's known inert-patch failure mode
// (see CLAUDE.md "Config Propagation Is Part Of The Patch").
return [

    // Broker/control plane — Robinhood agentic MCP.
    'mcp_endpoint' => env('HARNESS_MCP_ENDPOINT', 'https://agent.robinhood.com/mcp/trading'),

    // Pinned agentic cash account. Never model-selected, never defaulted from
    // get_accounts. The capital firewall is this pin.
    'agentic_account' => env('HARNESS_AGENTIC_ACCOUNT', '872449178'),

    // approval | auto — the nightly gate posture.
    'mode' => env('HARNESS_MODE', 'auto'),

    // Global arm flag. true = no placement, alert.
    'kill_switch' => (bool) env('HARNESS_KILL_SWITCH', false),

    // RH agentic OAuth credential (env fallback; encrypted-at-rest store is
    // the primary path — see App\Robinhood\Mcp\OAuthTokenStore).
    'oauth_token' => env('HARNESS_OAUTH_TOKEN'),

    // Broker API credit budget. This is enforced at the Robinhood MCP
    // transport boundary, so every consumer must acquire credits before an
    // outbound broker HTTP request. Keep conservative until the broker
    // publishes exact MCP limits.
    'broker_credits' => [
        'enabled' => (bool) env('HARNESS_BROKER_CREDITS_ENABLED', true),
        'max_per_minute' => (int) env('HARNESS_BROKER_CREDITS_PER_MINUTE', 45),
        'mode' => env('HARNESS_BROKER_CREDITS_MODE', 'wait'), // wait|fail
        'max_wait_seconds' => (int) env('HARNESS_BROKER_CREDITS_MAX_WAIT_SECONDS', 20),
        'cache_key' => env('HARNESS_BROKER_CREDITS_KEY', 'theta:robinhood:mcp:credits'),
    ],

    // Sanctum-style bearer for the read-only telemetry API.
    'telemetry_token' => env('HARNESS_TELEMETRY_TOKEN'),

    // Bankroll caps — settled-cash accounting, USD debit bounds.
    'allocation' => [
        'per_day_max_debit' => (float) env('HARNESS_ALLOC_PER_DAY', 200.00),
        'per_play_max_debit' => (float) env('HARNESS_ALLOC_PER_PLAY', 50.00),
        'per_symbol_max_debit' => (float) env('HARNESS_ALLOC_PER_SYMBOL', 50.00),
        'max_open_positions' => (int) env('HARNESS_MAX_OPEN_POSITIONS', 8),
    ],

    // Equity execution policy defaults (marketable-limit over naked market).
    // marketable_limit_offset_bps / max_spread_bps are reserved for the
    // structure-engine spread/entry gates (not yet on the execution path).
    'equity' => [
        'default_order_type' => 'limit',
        'default_time_in_force' => 'gfd',
        'default_market_hours' => 'regular_hours',
        'marketable_limit_offset_bps' => (float) env('HARNESS_MARKETABLE_LIMIT_OFFSET_BPS', 10.0),
        'max_spread_bps' => (float) env('HARNESS_MAX_SPREAD_BPS', 50.0),
    ],

    // Stateless Python compute (Greeks/EV) behind local HTTP. Never on the
    // order path, never holds broker credentials. Consumed by the options
    // math lane; not yet wired into a PHP caller.
    'greeks' => [
        'base_url' => env('HARNESS_GREEKS_BASE_URL', 'http://greeks:8000'),
        'timeout_seconds' => (int) env('HARNESS_GREEKS_TIMEOUT', 130),
    ],

    // Options lane — single-leg Level 2 only (long calls/puts BTO, STC to
    // exit). Account 872449178 cleared option_level_2 on 2026-06-12.
    // Spreads/short options stay out until a later explicit pass.
    'options' => [
        'enabled' => (bool) env('HARNESS_OPTIONS_ENABLED', true),
        'default_order_type' => 'limit',
        'default_time_in_force' => 'gfd',
        'default_market_hours' => 'regular_hours',
        // Spread cap in bps of mid (1500 = 15%).
        'max_spread_bps' => (float) env('HARNESS_OPTIONS_MAX_SPREAD_BPS', 1500),
        'max_contract_ask' => (float) env('HARNESS_OPTIONS_MAX_CONTRACT_ASK', 5.00),
        // Minimum contract ask (premium floor). Below this, a 1-2c spread and the
        // percentage stop-loss live inside the contract's own noise band, so the
        // position is bought near the ask and stopped out by the spread. Default
        // 0.0 (off); armed via env on the box. Pairs with max_spread_bps.
        'min_contract_ask' => (float) env('HARNESS_OPTIONS_MIN_CONTRACT_ASK', 0.0),
        'per_play_max_debit' => (float) env('HARNESS_OPTIONS_PER_PLAY_MAX_DEBIT', 75.00),
        'per_day_max_debit' => (float) env('HARNESS_OPTIONS_PER_DAY_MAX_DEBIT', 250.00),
        'daily_gross_entry_debit' => (float) env('HARNESS_OPTIONS_DAILY_GROSS_ENTRY_DEBIT', 500.00),
        'per_underlying_max_debit' => (float) env('HARNESS_OPTIONS_PER_UNDERLYING_MAX_DEBIT', 50.00),
        'max_open_positions' => (int) env('HARNESS_OPTIONS_MAX_OPEN_POSITIONS', 4),
        'min_quote_fresh_seconds' => (int) env('HARNESS_OPTIONS_QUOTE_FRESH_SECONDS', 10),
        'metadata_cache_seconds' => (int) env('HARNESS_OPTIONS_METADATA_CACHE_SECONDS', 900),
        'contract_multiplier' => 100.0,
        'producer' => [
            'enabled' => (bool) env('HARNESS_OPTIONS_PRODUCER_ENABLED', true),
            'universe_source' => env('HARNESS_OPTIONS_PRODUCER_UNIVERSE_SOURCE', 'dynamic'), // dynamic|static
            'symbols' => array_values(array_filter(array_map(
                'trim',
                explode(',', env('HARNESS_OPTIONS_PRODUCER_SYMBOLS', 'SPY,QQQ,IWM,DIA,SMH,SOXX,XLF,XLK,XLE,NVDA,AAPL,MSFT,META,AMZN,AMD,TSLA,GOOGL')),
            ))),
            'dynamic_max_symbols' => (int) env('HARNESS_OPTIONS_PRODUCER_DYNAMIC_MAX_SYMBOLS', 6),
            'option_type' => env('HARNESS_OPTIONS_PRODUCER_OPTION_TYPE', 'auto'),
            'expiration_min_days' => (int) env('HARNESS_OPTIONS_PRODUCER_EXPIRATION_MIN_DAYS', 1),
            'max_chain_pages' => (int) env('HARNESS_OPTIONS_PRODUCER_MAX_CHAIN_PAGES', 3),
            'quote_chunk_limit' => (int) env('HARNESS_OPTIONS_PRODUCER_QUOTE_CHUNK_LIMIT', 3),
            'block_cooldown_minutes' => (int) env('HARNESS_OPTIONS_PRODUCER_BLOCK_COOLDOWN_MINUTES', 5),
            'entry_symbol_cooldown_minutes' => (int) env('HARNESS_OPTIONS_PRODUCER_ENTRY_SYMBOL_COOLDOWN_MINUTES', 10),
            'buying_power_reserve_debit' => (float) env('HARNESS_OPTIONS_PRODUCER_BUYING_POWER_RESERVE_DEBIT', 25.00),
            'momentum_timeframe' => env('HARNESS_OPTIONS_PRODUCER_MOMENTUM_TIMEFRAME', '1Min'),
            'momentum_lookback_bars' => (int) env('HARNESS_OPTIONS_PRODUCER_MOMENTUM_LOOKBACK_BARS', 10),
            'momentum_bar_fresh_seconds' => (int) env('HARNESS_OPTIONS_PRODUCER_MOMENTUM_BAR_FRESH_SECONDS', 180),
            'momentum_min_return_bps' => (float) env('HARNESS_OPTIONS_PRODUCER_MOMENTUM_MIN_RETURN_BPS', 3.0),
            'momentum_min_volume_expansion' => (float) env('HARNESS_OPTIONS_PRODUCER_MOMENTUM_MIN_VOLUME_EXPANSION', 0.5),
            // Decision/execution separation: evaluate and select candidates
            // independent of account capital; gate capital at execution only
            // (capital-short setups record as execution_deferred and re-promote
            // once capital frees). Default-off rollout; armed via env on the box.
            'capital_independent_evaluation' => (bool) env('HARNESS_OPTIONS_PRODUCER_CAPITAL_INDEPENDENT', false),
            'underlying_max_spread_bps' => (float) env('HARNESS_OPTIONS_PRODUCER_UNDERLYING_MAX_SPREAD_BPS', 5.0),
            'exit_enabled' => (bool) env('HARNESS_OPTIONS_PRODUCER_EXIT_ENABLED', true),
            'exit_profit_target_bps' => (float) env('HARNESS_OPTIONS_PRODUCER_EXIT_PROFIT_TARGET_BPS', 1200.0),
            'exit_stop_loss_bps' => (float) env('HARNESS_OPTIONS_PRODUCER_EXIT_STOP_LOSS_BPS', 2000.0),
            'exit_max_hold_minutes' => (float) env('HARNESS_OPTIONS_PRODUCER_EXIT_MAX_HOLD_MINUTES', 1.0),
            'exit_max_hold_min_pnl_bps' => (float) env('HARNESS_OPTIONS_PRODUCER_EXIT_MAX_HOLD_MIN_PNL_BPS', 1.0),
            // Anti-churn floor: discretionary exits (profit-target, max-hold) are
            // held back while a position is younger than this many minutes. The
            // protective stop-loss is NEVER suppressed. Default-off (0.0); armed
            // via env on the box.
            'exit_min_hold_minutes' => (float) env('HARNESS_OPTIONS_PRODUCER_EXIT_MIN_HOLD_MINUTES', 0.0),
            // Anti-churn re-entry cooldown: block a fresh open in an underlying
            // for this many minutes after a position in that name was closed, so
            // the loop cannot open->close->reopen the same name. Default-off (0);
            // armed via env on the box. Complements entry_symbol_cooldown_minutes
            // (which keys off recent opens).
            'reentry_after_exit_minutes' => (int) env('HARNESS_OPTIONS_PRODUCER_REENTRY_AFTER_EXIT_MINUTES', 0),
            'allocation_capital_lock_days' => (float) env('HARNESS_OPTIONS_PRODUCER_ALLOCATION_CAPITAL_LOCK_DAYS', 1.0),
            'allocation_min_expected_gain' => (float) env('HARNESS_OPTIONS_PRODUCER_ALLOCATION_MIN_EXPECTED_GAIN', 0.0),
            'allocation_expected_gain_multiplier' => (float) env('HARNESS_OPTIONS_PRODUCER_ALLOCATION_EXPECTED_GAIN_MULTIPLIER', 1.0),
        ],
        'required_tools' => [
            'get_option_chains',
            'get_option_instruments',
            'get_option_quotes',
            'review_option_order',
            'place_option_order',
            'get_option_orders',
            'get_option_positions',
        ],
    ],

    // Data plane — Alpaca SIP feed (scanner substrate; RH MCP is not the firehose).
    'alpaca' => [
        'key_id' => env('ALPACA_DATA_KEY_ID'),
        'secret_key' => env('ALPACA_DATA_SECRET_KEY'),
        'feed' => env('ALPACA_DATA_FEED', 'sip'),
        'base_url' => env('ALPACA_DATA_BASE_URL', 'https://data.alpaca.markets'),
        'stream_url' => env('ALPACA_DATA_STREAM_URL', 'wss://stream.data.alpaca.markets/v2/sip'),
        'staleness_seconds' => (int) env('HARNESS_DATA_STALENESS_SECONDS', 10),
    ],

    // JSONL system-of-record location.
    'telemetry' => [
        'dir' => env('HARNESS_TELEMETRY_DIR', storage_path('harness')),
        'agent_id' => env('HARNESS_AGENT_ID', 'theta-harness'),
    ],

    // Cockpit behaviour monitor — on-box anomaly digest + operator push alerts.
    // Read-only; default-off until a webhook channel is configured.
    'monitor' => [
        'enabled' => (bool) env('HARNESS_MONITOR_ENABLED', false),
        'webhook_url' => env('HARNESS_MONITOR_WEBHOOK_URL'),
        'webhook_format' => env('HARNESS_MONITOR_WEBHOOK_FORMAT', 'slack'), // slack|discord|ntfy|raw
        'grant_warn_hours' => (int) env('HARNESS_MONITOR_GRANT_WARN_HOURS', 24),
        'snapshot_stale_seconds' => (int) env('HARNESS_MONITOR_SNAPSHOT_STALE_SECONDS', 180),
    ],
];
