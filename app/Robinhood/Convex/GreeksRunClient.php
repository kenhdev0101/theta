<?php

declare(strict_types=1);

namespace App\Robinhood\Convex;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin typed client over the greeks service's whitelisted primitive runner
 * (`POST /run/{script}`). Stateless math only — this NEVER places orders,
 * writes trading state, or holds broker credentials (firm invariant: the
 * sealed convex primitives live behind HTTP, deterministic PHP owns the
 * broker path). The script keys are the service whitelist in
 * services/greeks/main.py (convex_feature_row / convex_feature_schema / ...).
 */
final class GreeksRunClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        return new self(
            baseUrl: rtrim((string) data_get($cfg, 'greeks.base_url', 'http://greeks:8000'), '/'),
            timeoutSeconds: max(1, (int) data_get($cfg, 'greeks.timeout_seconds', 130)),
        );
    }

    /**
     * Run a whitelisted primitive and return the decoded run envelope:
     * {ok, script, argv, returncode, stdout_json, stdout_raw, stderr, ...}.
     *
     * @param  list<string>  $args  argv passed verbatim to the CLI primitive
     */
    public function run(string $script, array $args): array
    {
        $response = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl.'/run/'.$script, ['args' => array_values($args)]);

        if ($response->failed()) {
            throw new RuntimeException("greeks /run/{$script} http {$response->status()}: ".$response->body());
        }

        return (array) $response->json();
    }
}
