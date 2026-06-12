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
        'per_play_max_debit' => (float) env('HARNESS_OPTIONS_PER_PLAY_MAX_DEBIT', 75.00),
        'per_day_max_debit' => (float) env('HARNESS_OPTIONS_PER_DAY_MAX_DEBIT', 250.00),
        'per_underlying_max_debit' => (float) env('HARNESS_OPTIONS_PER_UNDERLYING_MAX_DEBIT', 100.00),
        'max_open_positions' => (int) env('HARNESS_OPTIONS_MAX_OPEN_POSITIONS', 4),
        'min_quote_fresh_seconds' => (int) env('HARNESS_OPTIONS_QUOTE_FRESH_SECONDS', 10),
        'contract_multiplier' => 100.0,
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
];
