<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Console\Command;

/**
 * Re-store a Robinhood OAuth grant under THIS host's APP_KEY.
 *
 * Reads grant JSON from STDIN (default — keeps the token out of argv and shell
 * history) or from --file (for tests). The grant is encrypted at rest by
 * OAuthTokenStore::put() under the local APP_KEY with 0600 perms, so it works
 * even when the source host used a different APP_KEY.
 *
 * Pair with `theta:rh-oauth-export --yes` on the source host:
 *   export --yes | ssh box '... artisan theta:rh-oauth-import'
 */
final class RobinhoodOAuthImportCommand extends Command
{
    protected $signature = 'theta:rh-oauth-import {--file= : Read grant JSON from a file instead of STDIN}';

    protected $description = 'Store a Robinhood OAuth grant (JSON via STDIN) encrypted under this host\'s APP_KEY.';

    public function handle(): int
    {
        $file = $this->option('file');
        $raw = $file !== null
            ? (is_file((string) $file) ? (string) file_get_contents((string) $file) : '')
            : (string) file_get_contents('php://stdin');

        $raw = trim($raw);
        if ($raw === '') {
            $this->error('no grant JSON received (stdin empty / file missing)');

            return self::FAILURE;
        }

        $grant = json_decode($raw, true);
        if (! is_array($grant) || empty($grant['access_token'])) {
            $this->error('invalid grant JSON: an access_token field is required');

            return self::FAILURE;
        }

        OAuthTokenStore::fromConfig((array) config('harness'))->put($grant);

        // Confirm with non-sensitive fields only — never echo the token back.
        $expiresIn = isset($grant['expires_in']) && is_numeric($grant['expires_in']) ? (int) $grant['expires_in'] : null;
        $this->info(sprintf(
            'grant stored under this host APP_KEY (scope=%s, has_refresh=%s, expires_in=%s)',
            (string) ($grant['scope'] ?? 'unknown'),
            empty($grant['refresh_token']) ? 'no' : 'yes',
            $expiresIn !== null ? $expiresIn.'s' : 'unknown',
        ));

        return self::SUCCESS;
    }
}
