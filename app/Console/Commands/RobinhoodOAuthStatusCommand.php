<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Mcp\OAuthBootstrapClient;
use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Console\Command;

class RobinhoodOAuthStatusCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:rh-oauth-status';

    protected $description = 'Show theta Robinhood OAuth status without printing secrets';

    public function handle(): int
    {
        $cfg = $this->assembleConfig();
        $store = OAuthTokenStore::fromConfig($cfg);
        $grant = $store->get();
        $pending = OAuthBootstrapClient::fromConfig($cfg)->pending();

        $this->table(['field', 'value'], [
            ['stored_access_token', $store->hasToken() ? 'yes' : 'no'],
            ['encrypted_grant', $grant === null ? 'no' : 'yes'],
            ['env_fallback', empty($cfg['oauth_token']) ? 'no' : 'yes'],
            ['expires_at', is_array($grant) ? (string) ($grant['expires_at'] ?? 'unreported') : ''],
            ['has_refresh_token', is_array($grant) && ! empty($grant['refresh_token']) ? 'yes' : 'no'],
            ['pending_grant', $pending === null ? 'no' : 'yes'],
            ['pending_created_at', is_array($pending) ? (string) ($pending['created_at'] ?? '') : ''],
        ]);

        return self::SUCCESS;
    }
}
