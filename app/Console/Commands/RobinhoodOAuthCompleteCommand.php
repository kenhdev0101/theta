<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Mcp\OAuthBootstrapClient;
use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Console\Command;

class RobinhoodOAuthCompleteCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:rh-oauth-complete
        {--callback-url= : Full redirect URL after Robinhood authorization}
        {--code= : Authorization code, if not passing callback-url}
        {--state= : OAuth state, if not passing callback-url}';

    protected $description = 'Complete Robinhood agentic MCP OAuth and store the theta token grant encrypted at rest';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();

        try {
            $grant = OAuthBootstrapClient::fromConfig($cfg)->complete(
                callbackUrl: $this->option('callback-url') !== null ? (string) $this->option('callback-url') : null,
                code: $this->option('code') !== null ? (string) $this->option('code') : null,
                state: $this->option('state') !== null ? (string) $this->option('state') : null,
            );

            OAuthTokenStore::fromConfig($cfg)->put($grant);
        } catch (\Throwable $e) {
            $this->error("oauth complete failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Robinhood OAuth grant stored for theta.');
        $this->table(['field', 'value'], [
            ['resource', (string) ($grant['resource'] ?? '')],
            ['token_type', (string) ($grant['token_type'] ?? 'unknown')],
            ['scope', (string) ($grant['scope'] ?? '')],
            ['expires_at', (string) ($grant['expires_at'] ?? 'unreported')],
            ['has_refresh_token', empty($grant['refresh_token']) ? 'no' : 'yes'],
        ]);

        return self::SUCCESS;
    }
}
