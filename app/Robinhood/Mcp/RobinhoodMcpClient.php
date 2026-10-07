<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Support\Facades\Cache;
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
    private ?array $cachedTools = null;

    private ?array $cachedAccountCapability = null;

    private bool $accountCapabilityLoaded = false;

    public function __construct(
        private readonly McpTransport $transport,
        private readonly string $agenticAccount,
        private readonly ?JsonlRecorder $telemetry = null,
        private readonly int $optionMetadataCacheSeconds = 0,
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
                credits: BrokerCreditLimiter::fromConfig($cfg),
            ),
            agenticAccount: (string) ($cfg['agentic_account'] ?? ''),
            telemetry: $telemetry,
            optionMetadataCacheSeconds: max(0, (int) data_get($cfg, 'options.metadata_cache_seconds', 0)),
        );
    }

    /** Broker capability read — the advertised tool surface (options-lane activation signal). */
    public function listTools(): array
    {
        return $this->cachedTools ??= $this->transport->listTools();
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

    // ── Options surface (single-leg Level 2; live-measured schemas) ──────

    public function getOptionChains(string $underlyingSymbol): array
    {
        $arguments = [
            'underlying_symbol' => strtoupper($underlyingSymbol),
        ];

        return $this->cachedOptionMetadata('get_option_chains', $arguments);
    }

    /**
     * @param  array  $filters  chain_id|chain_symbol|expiration_dates|strike_price|type|state|tradability|ids|cursor
     */
    public function getOptionInstruments(array $filters): array
    {
        $allowed = ['chain_id', 'chain_symbol', 'expiration_dates', 'strike_price', 'type', 'state', 'tradability', 'ids', 'cursor'];

        return $this->cachedOptionMetadata('get_option_instruments', array_intersect_key($filters, array_flip($allowed)));
    }

    /** @param list<string> $instrumentIds max 20 for close data per broker guide */
    public function getOptionQuotes(array $instrumentIds): array
    {
        return $this->call('get_option_quotes', [
            'instrument_ids' => array_values($instrumentIds),
        ]);
    }

    public function getOptionPositions(bool $nonzero = true): array
    {
        return $this->call('get_option_positions', [
            'account_number' => $this->agenticAccount,
            'nonzero' => $nonzero,
        ]);
    }

    /** Reconciliation key: harness option orders are placed_agent='agentic'. */
    public function getOptionOrders(array $filters = []): array
    {
        return $this->call('get_option_orders', array_merge([
            'account_number' => $this->agenticAccount,
            'placed_agent' => 'agentic',
        ], $filters));
    }

    /** The caller must confirm cancellation through a subsequent broker order read. */
    public function cancelOptionOrder(string $orderId): array
    {
        return $this->call('cancel_option_order', [
            'account_number' => $this->agenticAccount,
            'order_id' => $orderId,
        ]);
    }

    public function reviewOptionOrder(array $order): array
    {
        return $this->call('review_option_order', array_merge($order, [
            'account_number' => $this->agenticAccount,
        ]));
    }

    /**
     * @param  string  $refId  harness-persisted idempotency key; durable BEFORE
     *                         this method is invoked
     */
    public function placeOptionOrder(array $order, string $refId): array
    {
        if ($refId === '') {
            throw new InvalidArgumentException('placeOptionOrder requires a persisted ref_id');
        }

        return $this->call('place_option_order', array_merge($order, [
            'account_number' => $this->agenticAccount,
            'ref_id' => $refId,
        ]));
    }

    /**
     * Capability truth for the PINNED account only. get_accounts is called
     * as the only broker surface exposing option_level/agentic_allowed, but
     * every other account row is discarded inside this method — nothing but
     * the pin ever crosses this boundary, and the pin itself always comes
     * from config, never from the list.
     */
    public function getAccountCapability(): ?array
    {
        if ($this->accountCapabilityLoaded) {
            return $this->cachedAccountCapability;
        }

        $this->accountCapabilityLoaded = true;
        $payload = $this->call('get_accounts', []);

        foreach (PortfolioReader::listFrom($payload, ['accounts', 'results']) as $account) {
            if ((string) ($account['account_number'] ?? '') !== $this->agenticAccount) {
                continue;
            }

            $this->cachedAccountCapability = [
                'account_number' => $this->agenticAccount,
                'type' => $account['type'] ?? $account['brokerage_account_type'] ?? null,
                'option_level' => $account['option_level'] ?? null,
                'agentic_allowed' => $account['agentic_allowed'] ?? null,
                'nickname' => $account['nickname'] ?? null,
            ];

            return $this->cachedAccountCapability;
        }

        return null;
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

    private function cachedOptionMetadata(string $tool, array $arguments): array
    {
        if ($this->optionMetadataCacheSeconds <= 0) {
            return $this->call($tool, $arguments);
        }

        ksort($arguments);
        $key = 'theta:robinhood:mcp:option_metadata:'.$tool.':'.sha1(json_encode($arguments, JSON_THROW_ON_ERROR));

        return Cache::remember($key, now()->addSeconds($this->optionMetadataCacheSeconds), fn (): array => $this->call($tool, $arguments));
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
