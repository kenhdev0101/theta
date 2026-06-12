<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class OAuthBootstrapClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $pendingPath,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        return new self(
            endpoint: (string) ($cfg['mcp_endpoint'] ?? ''),
            pendingPath: storage_path('harness/oauth_pending.json'),
        );
    }

    public function begin(string $redirectUri, ?string $scope = null): array
    {
        if ($this->endpoint === '') {
            throw new RuntimeException('mcp endpoint is empty');
        }

        $resourceMetadata = $this->discoverProtectedResourceMetadata();
        $authorizationServer = $this->authorizationServerFrom($resourceMetadata);
        $serverMetadata = $this->discoverAuthorizationServerMetadata($authorizationServer);
        $scope = $scope ?: 'internal';
        $client = $this->registerClient($serverMetadata, $redirectUri, $scope);

        $state = Str::random(40);
        $verifier = $this->base64Url(random_bytes(32));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));

        $params = array_filter([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'resource' => $this->endpoint,
            'scope' => $scope,
        ], fn ($value) => $value !== null && $value !== '');

        $authorizationEndpoint = (string) ($serverMetadata['authorization_endpoint'] ?? '');
        if ($authorizationEndpoint === '') {
            throw new RuntimeException('authorization server metadata did not include authorization_endpoint');
        }

        $pending = [
            'endpoint' => $this->endpoint,
            'redirect_uri' => $redirectUri,
            'resource_metadata' => $resourceMetadata,
            'authorization_server' => $authorizationServer,
            'authorization_server_metadata' => $serverMetadata,
            'client' => $client,
            'state' => $state,
            'code_verifier' => $verifier,
            'scope' => $scope,
            'created_at' => now()->toIso8601ZuluString(),
        ];

        $this->writePending($pending);

        return [
            'authorization_url' => $authorizationEndpoint.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'authorization_server' => $authorizationServer,
            'client_id' => $client['client_id'],
            'pending_path' => $this->pendingPath,
        ];
    }

    public function complete(?string $callbackUrl, ?string $code, ?string $state): array
    {
        $pending = $this->pending();
        if ($pending === null) {
            throw new RuntimeException('no pending OAuth grant found; run theta:rh-oauth-init first');
        }

        if ($callbackUrl !== null && $callbackUrl !== '') {
            $parts = parse_url($callbackUrl);
            parse_str((string) ($parts['query'] ?? ''), $query);
            $code = (string) ($query['code'] ?? $code ?? '');
            $state = (string) ($query['state'] ?? $state ?? '');
        }

        if (($code ?? '') === '') {
            throw new RuntimeException('authorization code is required');
        }

        if (($state ?? '') === '' || ! hash_equals((string) $pending['state'], (string) $state)) {
            throw new RuntimeException('OAuth state mismatch; refusing to exchange code');
        }

        $metadata = (array) $pending['authorization_server_metadata'];
        $tokenEndpoint = (string) ($metadata['token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            throw new RuntimeException('authorization server metadata did not include token_endpoint');
        }

        $client = (array) $pending['client'];
        $form = array_filter([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) $pending['redirect_uri'],
            'client_id' => (string) $client['client_id'],
            'client_secret' => $client['client_secret'] ?? null,
            'code_verifier' => (string) $pending['code_verifier'],
            'resource' => $this->endpoint,
        ], fn ($value) => $value !== null && $value !== '');

        $response = Http::asForm()->acceptJson()->post($tokenEndpoint, $form);
        if ($response->failed()) {
            throw new RuntimeException("token exchange failed: HTTP {$response->status()} {$response->body()}");
        }

        $grant = $response->json();
        if (! is_array($grant) || empty($grant['access_token'])) {
            throw new RuntimeException('token exchange response did not include access_token');
        }

        $grant['obtained_at'] = now()->toIso8601ZuluString();
        $grant['resource'] = $this->endpoint;
        $grant['client_id'] = (string) $client['client_id'];
        if (isset($grant['expires_in']) && is_numeric($grant['expires_in'])) {
            $grant['expires_at'] = now()->addSeconds((int) $grant['expires_in'])->toIso8601ZuluString();
        }

        @unlink($this->pendingPath);

        return $grant;
    }

    public function pending(): ?array
    {
        if (! is_file($this->pendingPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->pendingPath), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function discoverProtectedResourceMetadata(): array
    {
        $response = Http::acceptJson()->post($this->endpoint, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'clientInfo' => ['name' => 'theta-harness-oauth-bootstrap', 'version' => '0.1.0'],
                'capabilities' => new \stdClass,
            ],
        ]);

        $metadataUrl = $this->resourceMetadataUrlFrom($response->header('WWW-Authenticate'));
        if ($metadataUrl === null) {
            throw new RuntimeException(
                "MCP endpoint did not advertise OAuth protected resource metadata; HTTP {$response->status()}"
            );
        }

        $metadataResponse = Http::acceptJson()->get($metadataUrl);
        if ($metadataResponse->failed()) {
            throw new RuntimeException("protected resource metadata fetch failed: HTTP {$metadataResponse->status()}");
        }

        $metadata = $metadataResponse->json();
        if (! is_array($metadata)) {
            throw new RuntimeException('protected resource metadata response was not JSON');
        }

        $metadata['metadata_url'] = $metadataUrl;

        return $metadata;
    }

    private function discoverAuthorizationServerMetadata(string $issuer): array
    {
        $url = $this->wellKnownUrl($issuer, 'oauth-authorization-server');

        $response = Http::acceptJson()->get($url);
        if ($response->failed()) {
            throw new RuntimeException("authorization server metadata fetch failed: HTTP {$response->status()} {$url}");
        }

        $metadata = $response->json();
        if (! is_array($metadata)) {
            throw new RuntimeException('authorization server metadata response was not JSON');
        }

        return $metadata;
    }

    private function registerClient(array $metadata, string $redirectUri, string $scope): array
    {
        $registrationEndpoint = (string) ($metadata['registration_endpoint'] ?? '');
        if ($registrationEndpoint === '') {
            throw new RuntimeException('authorization server did not advertise dynamic client registration');
        }

        $response = Http::acceptJson()->post($registrationEndpoint, [
            'client_name' => 'theta-harness',
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => $scope,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("dynamic client registration failed: HTTP {$response->status()} {$response->body()}");
        }

        $client = $response->json();
        if (! is_array($client) || empty($client['client_id'])) {
            throw new RuntimeException('dynamic client registration response did not include client_id');
        }

        return $client;
    }

    private function authorizationServerFrom(array $metadata): string
    {
        $servers = $metadata['authorization_servers'] ?? null;
        if (! is_array($servers) || count($servers) === 0) {
            throw new RuntimeException('protected resource metadata did not include authorization_servers');
        }

        return rtrim((string) $servers[0], '/');
    }

    private function resourceMetadataUrlFrom(string $header): ?string
    {
        if (preg_match('/resource_metadata="([^"]+)"/i', $header, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/resource_metadata=([^,\s]+)/i', $header, $match) === 1) {
            return trim($match[1], '"');
        }

        return null;
    }

    private function writePending(array $pending): void
    {
        $dir = dirname($this->pendingPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->pendingPath, json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($this->pendingPath, 0600);
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function wellKnownUrl(string $issuer, string $suffix): string
    {
        $parts = parse_url(rtrim($issuer, '/'));
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException("invalid issuer URL: {$issuer}");
        }

        $authority = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = trim((string) ($parts['path'] ?? ''), '/');

        return $authority.'/.well-known/'.$suffix.($path === '' ? '' : '/'.$path);
    }
}
