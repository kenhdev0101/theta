<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Mcp\OAuthTokenStore;
use App\Robinhood\Mcp\PortfolioReader;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Console\Command;

/**
 * Options capability readback: pinned-account permission truth, advertised
 * broker tool surface, and a live chain read. Never places; the
 * place_option_order check is mechanical presence+schema only.
 */
class OptionsCapabilityReadbackCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:options-capability-readback {--symbol=SPY}';

    protected $description = 'Options readiness: account option_level, agentic flag, tool surface, chain read, blocker list';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $recorder = JsonlRecorder::fromConfig($cfg);
        $symbol = strtoupper((string) $this->option('symbol'));
        $blockers = [];

        if (! (bool) ($cfg['options']['enabled'] ?? false)) {
            $blockers[] = 'options_disabled_in_config (HARNESS_OPTIONS_ENABLED)';
        }

        if (! OAuthTokenStore::fromConfig($cfg)->hasToken()) {
            $blockers[] = 'oauth_token_absent (one-time desktop grant; store via OAuthTokenStore)';
            $this->report($cfg, $recorder, $symbol, $blockers, account: null, tools: []);

            return self::FAILURE;
        }

        $client = RobinhoodMcpClient::fromConfig($cfg, $recorder);

        // Tool surface — place_option_order is verified by presence and
        // schema only (a dry mechanical capability check; never called).
        $tools = [];

        try {
            $tools = array_map(fn (array $t) => (string) ($t['name'] ?? ''), $client->listTools());
        } catch (\Throwable $e) {
            $blockers[] = 'tool_surface_unreadable: '.$e->getMessage();
        }

        foreach ((array) ($cfg['options']['required_tools'] ?? []) as $required) {
            if ($tools !== [] && ! in_array($required, $tools, true)) {
                $blockers[] = "broker_tool_missing: {$required}";
            }
        }

        // Pinned-account permission truth. Only the pinned row ever leaves
        // the client; the pin comes from config, never from the list.
        $account = null;

        try {
            $account = $client->getAccountCapability();
        } catch (\Throwable $e) {
            $blockers[] = 'account_capability_unverified: '.$e->getMessage();
        }

        if ($account !== null) {
            if (($account['agentic_allowed'] ?? null) !== true) {
                $blockers[] = 'account_not_agentic_allowed';
            }
            if (! in_array($account['option_level'] ?? '', ['option_level_2', 'option_level_3'], true)) {
                $blockers[] = 'option_level_insufficient: '.((string) ($account['option_level'] ?? 'unknown'));
            }
        } elseif (! in_array('account_capability_unverified', array_map(fn ($b) => explode(':', $b)[0], $blockers), true)) {
            $blockers[] = 'pinned_account_not_in_capability_read';
        }

        // Live chain read for the test symbol.
        try {
            $chains = PortfolioReader::listFrom($client->getOptionChains($symbol), ['chains']);
            if ($chains === []) {
                $blockers[] = "no_option_chain_for_{$symbol}";
            } else {
                $this->line(sprintf(
                    'chain: %s id=%s expirations=%d can_open_position=%s',
                    $symbol,
                    (string) ($chains[0]['id'] ?? '—'),
                    count((array) ($chains[0]['expiration_dates'] ?? [])),
                    ($chains[0]['can_open_position'] ?? null) === true ? 'yes' : 'no',
                ));
            }
        } catch (\Throwable $e) {
            $blockers[] = 'chain_read_failed: '.$e->getMessage();
        }

        return $this->report($cfg, $recorder, $symbol, $blockers, $account, $tools);
    }

    private function report(array $cfg, JsonlRecorder $recorder, string $symbol, array $blockers, ?array $account, array $tools): int
    {
        $ready = $blockers === [];

        $this->table(['field', 'value'], [
            ['account (pinned)', (string) $cfg['agentic_account']],
            ['option_level', (string) ($account['option_level'] ?? 'unverified')],
            ['agentic_allowed', $account === null ? 'unverified' : (($account['agentic_allowed'] ?? false) ? 'true' : 'false')],
            ['account_type', (string) ($account['type'] ?? 'unverified')],
            ['tools_advertised', $tools === [] ? 'unverified' : (string) count($tools)],
            ['test_symbol', $symbol],
        ]);

        if ($ready) {
            $this->info('OPTIONS_READY');
        } else {
            $this->error('OPTIONS_BLOCKED');
            foreach ($blockers as $blocker) {
                $this->line("  - {$blocker}");
            }
        }

        $recorder->record('options_capability', [
            'event' => 'capability_readback',
            'ready' => $ready,
            'blockers' => $blockers,
            'account' => $account,
            'symbol' => $symbol,
        ]);

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
