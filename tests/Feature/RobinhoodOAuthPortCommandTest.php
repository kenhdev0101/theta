<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Robinhood\Mcp\OAuthTokenStore;
use Tests\TestCase;

/**
 * Round-trips a grant through the import/export bootstrap utilities. Storage is
 * redirected to a temp dir so the test can never overwrite a real stored grant.
 */
class RobinhoodOAuthPortCommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/theta-oauth-'.getmypid().'-'.uniqid();
        mkdir($this->storage.'/harness', 0775, true);
        $this->app->useStoragePath($this->storage);
        config()->set('harness.oauth_token', null);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage.'/harness/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage.'/harness');
        @rmdir($this->storage);
        parent::tearDown();
    }

    public function test_import_persists_grant_encrypted_under_this_host_key(): void
    {
        $grant = [
            'access_token' => 'tok-abc',
            'refresh_token' => 'ref-xyz',
            'expires_in' => 604800,
            'scope' => 'internal',
            'obtained_at' => now()->toIso8601ZuluString(),
        ];
        $file = $this->storage.'/grant.json';
        file_put_contents($file, (string) json_encode($grant));

        $this->artisan('theta:rh-oauth-import', ['--file' => $file])->assertExitCode(0);

        $this->assertTrue(is_file($this->storage.'/harness/oauth_token.enc'));
        $stored = OAuthTokenStore::fromConfig((array) config('harness'))->get();
        $this->assertSame('tok-abc', $stored['access_token']);
        $this->assertSame('ref-xyz', $stored['refresh_token']);
        $this->assertSame('internal', $stored['scope']);
    }

    public function test_import_rejects_grant_without_access_token(): void
    {
        $file = $this->storage.'/bad.json';
        file_put_contents($file, (string) json_encode(['scope' => 'internal']));

        $this->artisan('theta:rh-oauth-import', ['--file' => $file])->assertExitCode(1);
        $this->assertFalse(is_file($this->storage.'/harness/oauth_token.enc'));
    }

    public function test_export_refuses_without_confirmation_flag(): void
    {
        $this->artisan('theta:rh-oauth-export')->assertExitCode(1);
    }
}
