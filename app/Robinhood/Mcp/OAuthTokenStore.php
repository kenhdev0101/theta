<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

use Illuminate\Support\Facades\Crypt;

/**
 * Robinhood agentic OAuth credential, encrypted at rest (Laravel Crypt).
 * The grant lifecycle (access TTL / refresh TTL / headless re-mint) is the
 * architecture §13 #1 characterization item; this store records what the
 * probe learns. Env HARNESS_OAUTH_TOKEN is the bootstrap fallback.
 */
final class OAuthTokenStore
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $envToken = null,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        return new self(
            path: storage_path('harness/oauth_token.enc'),
            envToken: $cfg['oauth_token'] ?? null,
        );
    }

    /** Persist a token grant (access_token, refresh_token, expires_in, scope, obtained_at). */
    public function put(array $grant): void
    {
        $grant['stored_at'] = now()->toIso8601ZuluString();

        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->path, Crypt::encryptString((string) json_encode($grant)), LOCK_EX);
        @chmod($this->path, 0600);
    }

    public function get(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $decrypted = Crypt::decryptString((string) file_get_contents($this->path));

        return json_decode($decrypted, true) ?: null;
    }

    public function accessToken(): ?string
    {
        $grant = $this->get();
        if (is_array($grant) && ! empty($grant['access_token'])) {
            return (string) $grant['access_token'];
        }

        return $this->envToken !== null && $this->envToken !== '' ? $this->envToken : null;
    }

    public function hasToken(): bool
    {
        return $this->accessToken() !== null;
    }
}
