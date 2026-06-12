<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Mcp\OAuthBootstrapClient;
use Illuminate\Console\Command;

class RobinhoodOAuthInitCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:rh-oauth-init
        {--redirect-uri=http://127.0.0.1:8787/callback : OAuth redirect URI registered for this one-time grant}
        {--scope=internal : OAuth scope requested from Robinhood}';

    protected $description = 'Initialize the Robinhood agentic MCP OAuth grant for theta';

    public function handle(): int
    {
        try {
            $result = OAuthBootstrapClient::fromConfig($this->assembleConfig())->begin(
                redirectUri: (string) $this->option('redirect-uri'),
                scope: $this->option('scope') !== null ? (string) $this->option('scope') : null,
            );
        } catch (\Throwable $e) {
            $this->error("oauth init failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Robinhood OAuth grant initialized.');
        $this->table(['field', 'value'], [
            ['authorization_server', $result['authorization_server']],
            ['client_id', $result['client_id']],
            ['redirect_uri', $result['redirect_uri']],
            ['pending_path', $result['pending_path']],
        ]);
        $this->line('Open this URL, authorize the Agentic account, then pass the callback URL to theta:rh-oauth-complete:');
        $this->newLine();
        $this->line($result['authorization_url']);

        return self::SUCCESS;
    }
}
