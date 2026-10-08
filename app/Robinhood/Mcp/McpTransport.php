<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * JSON-RPC 2.0 over streamable HTTP to the robinhood-trading MCP.
 * Deterministic transport: typed request out, decoded result in, bounded
 * retries on transport faults only (never on order-mutating ambiguity —
 * placement retries are governed by the persisted ref_id upstream).
 *
 * Unit tests drive this through Http::fake() fixtures; integration proof
 * is live broker/account truth.
 */
class McpTransport
{
    private int $requestId = 0;

    private bool $initialized = false;

    private ?string $sessionId = null;

    private string $creditLane = 'standard';

    public function __construct(
        private readonly string $endpoint,
        private readonly OAuthTokenStore $tokens,
        private readonly ?BrokerCreditLimiter $credits = null,
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectRetries = 2,
    ) {}

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function setCreditLane(string $lane): void
    {
        if (! in_array($lane, ['standard', 'stewardship'], true)) {
            throw new RuntimeException("broker credits lane '{$lane}' is invalid");
        }
        $this->creditLane = $lane;
    }

    /**
     * Call one MCP tool and return the decoded tool result payload.
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->initializeIfNeeded();

        $result = $this->rpc('tools/call', [
            'name' => $name,
            // Empty arguments must encode as {} — Go servers reject [].
            'arguments' => $arguments === [] ? new \stdClass : $arguments,
        ]);

        if (($result['isError'] ?? false) === true) {
            throw new RuntimeException("mcp tool {$name} returned error: ".json_encode($result));
        }

        if (isset($result['structuredContent']) && is_array($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        foreach ((array) ($result['content'] ?? []) as $content) {
            if (($content['type'] ?? null) === 'text') {
                $decoded = json_decode((string) $content['text'], true);

                return is_array($decoded) ? $decoded : ['text' => $content['text']];
            }
        }

        return $result;
    }

    /**
     * Broker capability read: the advertised tool surface with input
     * schemas. The options lane gates on exactly this (option tools
     * appearing here is the activation signal).
     *
     * @return list<array{name: string, description?: string, inputSchema?: array}>
     */
    public function listTools(): array
    {
        $this->initializeIfNeeded();

        $tools = [];
        $cursor = null;

        do {
            $result = $this->rpc('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            foreach ((array) ($result['tools'] ?? []) as $tool) {
                $tools[] = $tool;
            }
            $cursor = $result['nextCursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');

        return $tools;
    }

    /**
     * Read an MCP resource (e.g. trading://market-hours — the broker-truth
     * session clock). Returns decoded JSON when the resource is JSON text.
     */
    public function readResource(string $uri): array
    {
        $this->initializeIfNeeded();

        $result = $this->rpc('resources/read', ['uri' => $uri]);

        foreach ((array) ($result['contents'] ?? []) as $content) {
            if (isset($content['text'])) {
                $decoded = json_decode((string) $content['text'], true);

                return is_array($decoded) ? $decoded : ['text' => $content['text']];
            }
        }

        return $result;
    }

    private function initializeIfNeeded(): void
    {
        if ($this->initialized) {
            return;
        }

        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-03-26',
            'clientInfo' => ['name' => 'theta-harness', 'version' => '0.1.0'],
            'capabilities' => new \stdClass,
        ]);

        $this->initialized = true;
        $this->notify('notifications/initialized');

        unset($init);
    }

    private function rpc(string $method, array $params): array
    {
        $token = $this->tokens->accessToken();
        if ($token === null) {
            throw new RuntimeException(
                'oauth token absent: complete the one-time desktop grant and store it via OAuthTokenStore (arch §13 #1)'
            );
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => $method,
            // Empty params must encode as {} (object), not [] — Go-based
            // MCP servers reject the array form.
            'params' => $params === [] ? new \stdClass : $params,
        ];

        $response = $this->send($token, $payload);
        $body = $this->decodeBody($response);

        if (isset($body['error'])) {
            throw new RuntimeException("mcp rpc {$method} error: ".json_encode($body['error']));
        }

        return (array) ($body['result'] ?? []);
    }

    private function notify(string $method): void
    {
        $token = $this->tokens->accessToken();
        if ($token === null) {
            return;
        }

        $this->send($token, ['jsonrpc' => '2.0', 'method' => $method]);
    }

    private function send(string $token, array $payload): Response
    {
        $operation = (string) ($payload['method'] ?? 'unknown');
        $attempts = max(1, $this->connectRetries + 1);
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->credits?->acquire($operation, lane: $this->creditLane);

            $response = Http::withToken($token)
                ->withHeaders(array_filter([
                    'Accept' => 'application/json, text/event-stream',
                    'Mcp-Session-Id' => $this->sessionId,
                ]))
                ->timeout($this->timeoutSeconds)
                ->post($this->endpoint, $payload);

            if (! $response->failed() || $attempt === $attempts) {
                break;
            }

            usleep(250_000);
        }

        if ($response === null || $response->failed()) {
            throw new RuntimeException(
                'mcp transport failure: HTTP '.($response?->status() ?? 'none')." from {$this->endpoint}"
            );
        }

        $session = $response->header('Mcp-Session-Id');
        if ($session !== '') {
            $this->sessionId = $session;
        }

        return $response;
    }

    /**
     * Streamable HTTP may answer application/json or a one-event SSE frame.
     */
    private function decodeBody(Response $response): array
    {
        $contentType = strtolower($response->header('Content-Type'));
        $raw = $response->body();

        if (str_contains($contentType, 'text/event-stream')) {
            foreach (preg_split('/\r?\n/', $raw) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $decoded = json_decode(trim(substr($line, 5)), true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }

            throw new RuntimeException('mcp transport: unparseable SSE frame');
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('mcp transport: unparseable JSON body');
        }

        return $decoded;
    }
}
