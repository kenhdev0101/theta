<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Console\Command;

/**
 * Decrypt the stored Robinhood grant and emit it as JSON on STDOUT.
 *
 * This is a credential-handling utility for porting a grant between hosts that
 * do NOT share an APP_KEY (the encrypted file is APP_KEY-bound, so a raw file
 * copy cannot decrypt on the other side). Pipe the output straight into
 * `theta:rh-oauth-import` on the target host over SSH — the plaintext token
 * only ever crosses the encrypted channel; it is never written to disk here.
 *
 * Guarded by --yes so the credential is never printed by accident.
 */
final class RobinhoodOAuthExportCommand extends Command
{
    protected $signature = 'theta:rh-oauth-export {--yes : Confirm printing the decrypted grant to STDOUT}';

    protected $description = 'Decrypt the stored Robinhood OAuth grant to STDOUT as JSON (for porting to another host).';

    public function handle(): int
    {
        if (! $this->option('yes')) {
            $this->error('refusing to print a broker credential without --yes (it prints the access/refresh token as JSON to STDOUT)');

            return self::FAILURE;
        }

        $grant = OAuthTokenStore::fromConfig((array) config('harness'))->get();
        if (! is_array($grant) || empty($grant['access_token'])) {
            $this->error('no stored grant on this host (storage/harness/oauth_token.enc absent or empty)');

            return self::FAILURE;
        }

        // Raw JSON only — no decoration — so it pipes cleanly into the importer.
        $this->output->writeln((string) json_encode($grant, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
