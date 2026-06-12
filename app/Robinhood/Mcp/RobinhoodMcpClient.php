<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

use App\Robinhood\Telemetry\JsonlRecorder;
use InvalidArgumentException;

/**
 * Native PHP MCP client — thin typed method per tool actually used.
 * Every account-scoped call injects the pinned agentic account from config;
 * the account is never model-selected and never defaulted from get_accounts.
 * Order placement requires a harness-persisted ref_id (idempotency is owned
 * by the execution layer, persisted BEFORE the place call).
 */
class RobinhoodMcpClient
{
    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $agenticAccount,
        private readonly ?JsonlRecorder $telemetry = null,
    ) {
        if ($this->agenticAccount === '') {
            throw new InvalidArgumentException('agentic account pin is empty — refusing to construct an unpinned broker client');
        }
    }

    public static function fromConfig(array $cfg, ?JsonlRecorder $telemetry = null): self
    {
        return new self(
            transport: new McpTransport(
                endpoint: (string) ($cfg['mcp_endpoint'] ?? ''),
                tokens: OAuthTokenStore::fromConfig($cfg),
            ),
            agenticAccount: (string) ($cfg['agentic_account'] ?? ''),
            telemetry: $telemetry,
        );
    }

    /** Broker capability read — the advertised tool surface (options-lane activation signal). */
    public function listTools(): array
    {
        return $this->transport->listTools();
    }

    /** Broker-truth session clock. */
    public function getMarketHours(): array
    {
        return $this->transport->readResource('trading://market-hours');
    }

    // ── Account / portfolio truth ────────────────────────────────────────

    /** Buying power and cash truth come from get_portfolio, never get_accounts. */
    public function getPortfolio(): array
    {
        return $this->call('get_portfolio', [
            'account_number' => $this->agenticAccount,
        ]);
    }

    public function getEquityPositions(): array
    {
        return $this->call('get_equity_positions', [
            'account_number' => $this->agenticAccount,
        ]);
    }

    /** Reconciliation key: harness orders are placed_agent='agentic'. */
    public function getEquityOrders(array $filters = []): array
    {
        return $this->call('get_equity_orders', array_merge([
            'account_number' => $this->agenticAccount,
            'placed_agent' => 'agentic',
        ], $filters));
    }

    // ── Market/control reads (not the scanner firehose) ─────────────────

    public function getEquityQuotes(array $symbols): array
    {
        return $this->call('get_equity_quotes', [
            'symbols' => array_map('strtoupper', $symbols),
        ]);
    }

    /** @param  string|list<string>  $symbols  max 10 per broker schema */
    public function getEquityTradability(string|array $symbols): array
    {
        $symbols = array_map('strtoupper', (array) $symbols);

        return $this->call('get_equity_tradability', [
            'account_number' => $this->agenticAccount,
            'symbols' => $symbols,
        ]);
    }

    public function search(string $query): array
    {
        return $this->call('search', ['query' => $query]);
    }

    // ── Order path ───────────────────────────────────────────────────────

    public function reviewEquityOrder(array $order): array
    {
        return $this->call('review_equity_order', array_merge($order, [
            'account_number' => $this->agenticAccount,
        ]));
    }

    /**
     * @param  string  $refId  harness-persisted idempotency key; must already be
     *                         durable before this method is invoked
     */
    public function placeEquityOrder(array $order, string $refId): array
    {
        if ($refId === '') {
            throw new InvalidArgumentException('placeEquityOrder requires a persisted ref_id');
        }

        return $this->call('place_equity_order', array_merge($order, [
            'account_number' => $this->agenticAccount,
            'ref_id' => $refId,
        ]));
    }

    public function cancelEquityOrder(string $orderId): array
    {
        return $this->call('cancel_equity_order', [
            'account_number' => $this->agenticAccount,
            'order_id' => $orderId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function call(string $tool, array $arguments): array
    {
        $startedAt = microtime(true);

        try {
            $result = $this->transport->callTool($tool, $arguments);

            $this->stamp($tool, $arguments, true, $startedAt);

            return $result;
        } catch (\Throwable $e) {
            $this->stamp($tool, $arguments, false, $startedAt, $e->getMessage());

            throw $e;
        }
    }

    private function stamp(string $tool, array $arguments, bool $ok, float $startedAt, ?string $error = null): void
    {
        $this->telemetry?->record('mcp_calls', [
            'tool' => $tool,
            // Never stamp credentials; argument keys are enough for audit.
            'argument_keys' => array_keys($arguments),
            'symbol' => $arguments['symbol'] ?? ($arguments['symbols'] ?? null),
            'ok' => $ok,
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'error' => $error,
        ]);
    }
}
