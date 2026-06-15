<?php

declare(strict_types=1);

namespace App\MarketData;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Data plane v0 — Alpaca Algo Trader Plus / SIP REST reads.
 * Every read carries a freshness stamp and a staleness verdict; a frozen
 * quote is never reported as a fresh one. This client is scan substrate
 * only — it has no broker credentials and no order path.
 */
class AlpacaMarketDataClient
{
    public function __construct(private readonly array $cfg) {}

    /** Accepts the full harness cfg assembly or the alpaca block directly. */
    public static function fromConfig(array $cfg): self
    {
        return new self($cfg['alpaca'] ?? $cfg);
    }

    public function latestQuote(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        $payload = $this->get("/v2/stocks/{$symbol}/quotes/latest", ['feed' => $this->feed()]);

        $quote = $payload['quote'] ?? [];

        return $this->withFreshness([
            'symbol' => $symbol,
            'bid' => $quote['bp'] ?? null,
            'bid_size' => $quote['bs'] ?? null,
            'ask' => $quote['ap'] ?? null,
            'ask_size' => $quote['as'] ?? null,
            'source_ts' => $quote['t'] ?? null,
            'raw' => $quote,
        ]);
    }

    public function latestBar(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        $payload = $this->get("/v2/stocks/{$symbol}/bars/latest", ['feed' => $this->feed()]);

        $bar = $payload['bar'] ?? [];

        return $this->withFreshness([
            'symbol' => $symbol,
            'open' => $bar['o'] ?? null,
            'high' => $bar['h'] ?? null,
            'low' => $bar['l'] ?? null,
            'close' => $bar['c'] ?? null,
            'volume' => $bar['v'] ?? null,
            'source_ts' => $bar['t'] ?? null,
            'raw' => $bar,
        ]);
    }

    public function recentBars(string $symbol, int $limit = 30, string $timeframe = '1Min'): array
    {
        $symbol = strtoupper($symbol);
        $end = now();
        $start = $end->copy()->subMinutes(max(5, $limit + 5));
        $payload = $this->get("/v2/stocks/{$symbol}/bars", [
            'feed' => $this->feed(),
            'timeframe' => $timeframe,
            'limit' => max(1, $limit),
            'sort' => 'asc',
            'start' => $start->toIso8601ZuluString('microsecond'),
            'end' => $end->toIso8601ZuluString('microsecond'),
        ]);

        $bars = [];
        foreach ((array) ($payload['bars'] ?? []) as $bar) {
            if (! is_array($bar)) {
                continue;
            }

            $bars[] = $this->withFreshness([
                'symbol' => $symbol,
                'open' => $bar['o'] ?? null,
                'high' => $bar['h'] ?? null,
                'low' => $bar['l'] ?? null,
                'close' => $bar['c'] ?? null,
                'volume' => $bar['v'] ?? null,
                'source_ts' => $bar['t'] ?? null,
                'raw' => $bar,
            ]);
        }

        try {
            $latest = $this->latestBar($symbol);
            if (is_numeric($latest['close'] ?? null) && ! empty($latest['source_ts'])) {
                $latestTs = Carbon::parse((string) $latest['source_ts']);
                $lastTs = $bars === [] ? null : Carbon::parse((string) ($bars[array_key_last($bars)]['source_ts'] ?? ''));
                if ($lastTs === null || $latestTs->greaterThan($lastTs)) {
                    $bars[] = $latest;
                }
            }
        } catch (\Throwable) {
            // The bounded bars response is still useful; callers decide
            // freshness. latestBar is an improvement, not a hard dependency.
        }

        return [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'limit' => max(1, $limit),
            'feed' => $this->feed(),
            'fetched_at' => now()->toIso8601ZuluString('microsecond'),
            'latency_ms' => $payload['_latency_ms'] ?? null,
            'bars' => $bars,
        ];
    }

    public function snapshot(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        $payload = $this->get("/v2/stocks/{$symbol}/snapshot", ['feed' => $this->feed()]);

        return array_merge(['symbol' => $symbol], $payload);
    }

    /**
     * Config surface safe to stamp into telemetry/decision logs.
     * The API secret is never present here; the key id is masked.
     */
    public function telemetryContext(): array
    {
        $keyId = (string) ($this->cfg['key_id'] ?? '');

        return [
            'vendor' => 'alpaca',
            'key_id_masked' => $keyId === '' ? null : substr($keyId, 0, 4).'…',
            'feed' => $this->feed(),
            'base_url' => (string) ($this->cfg['base_url'] ?? ''),
            'staleness_seconds' => $this->stalenessSeconds(),
        ];
    }

    private function get(string $path, array $query): array
    {
        $keyId = (string) ($this->cfg['key_id'] ?? '');
        $secret = (string) ($this->cfg['secret_key'] ?? '');
        $baseUrl = rtrim((string) ($this->cfg['base_url'] ?? ''), '/');

        if ($keyId === '' || $secret === '' || $baseUrl === '') {
            throw new RuntimeException('alpaca config incomplete: ALPACA_DATA_KEY_ID / ALPACA_DATA_SECRET_KEY / ALPACA_DATA_BASE_URL must be set');
        }

        $started = microtime(true);
        $response = Http::withHeaders([
            'APCA-API-KEY-ID' => $keyId,
            'APCA-API-SECRET-KEY' => $secret,
        ])->timeout(10)->retry(2, 200, throw: false)->get($baseUrl.$path, $query);

        if ($response->failed()) {
            throw new RuntimeException("alpaca request failed: HTTP {$response->status()} {$path}");
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException("alpaca request returned non-JSON body: {$path}");
        }

        $payload['_latency_ms'] = round((microtime(true) - $started) * 1000, 1);

        return $payload;
    }

    private function withFreshness(array $data): array
    {
        $ageSeconds = null;
        $stale = true;

        if (! empty($data['source_ts'])) {
            $ageSeconds = round(abs(Carbon::parse($data['source_ts'])->diffInMilliseconds(now())) / 1000, 3);
            $stale = $ageSeconds > $this->stalenessSeconds();
        }

        $data['feed'] = $this->feed();
        $data['fetched_at'] = now()->toIso8601ZuluString('microsecond');
        $data['age_seconds'] = $ageSeconds;
        $data['stale'] = $stale;
        $data['staleness_gate_seconds'] = $this->stalenessSeconds();

        return $data;
    }

    private function feed(): string
    {
        return (string) ($this->cfg['feed'] ?? 'sip');
    }

    private function stalenessSeconds(): int
    {
        return (int) ($this->cfg['staleness_seconds'] ?? 10);
    }
}
