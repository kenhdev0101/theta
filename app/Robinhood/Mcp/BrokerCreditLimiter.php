<?php

declare(strict_types=1);

namespace App\Robinhood\Mcp;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Fixed-window broker API credit ledger. This sits below typed broker clients:
 * every outbound Robinhood MCP HTTP request must acquire one credit before it
 * leaves the process.
 */
final class BrokerCreditLimiter
{
    public function __construct(
        private readonly bool $enabled,
        private readonly int $maxPerMinute,
        private readonly string $cacheKey,
        private readonly string $defaultMode = 'wait',
        private readonly int $defaultMaxWaitSeconds = 20,
        private readonly ?CacheRepository $cache = null,
    ) {}

    public static function fromConfig(array $cfg): self
    {
        $credits = (array) ($cfg['broker_credits'] ?? []);

        return new self(
            enabled: (bool) ($credits['enabled'] ?? true),
            maxPerMinute: (int) ($credits['max_per_minute'] ?? 45),
            cacheKey: (string) ($credits['cache_key'] ?? 'theta:robinhood:mcp:credits'),
            defaultMode: (string) ($credits['mode'] ?? 'wait'),
            defaultMaxWaitSeconds: (int) ($credits['max_wait_seconds'] ?? 20),
        );
    }

    public function acquire(string $operation, int $credits = 1, ?string $mode = null, ?int $maxWaitSeconds = null): void
    {
        if (! $this->enabled) {
            return;
        }

        if ($this->maxPerMinute <= 0) {
            throw new RuntimeException('broker credits exhausted: max_per_minute is not configured');
        }

        $credits = max(1, $credits);
        $mode ??= $this->defaultMode;
        $maxWaitSeconds ??= $this->defaultMaxWaitSeconds;

        if (! in_array($mode, ['fail', 'wait'], true)) {
            throw new RuntimeException("broker credits mode '{$mode}' is invalid");
        }

        $cache = $this->cache ?? Cache::store();
        $deadline = microtime(true) + max(0, $maxWaitSeconds);

        do {
            $windowKey = $this->cacheKey.':'.now()->format('YmdHi');
            $cache->add($windowKey, 0, now()->addSeconds(70));
            $used = (int) $cache->get($windowKey, 0);

            if ($used + $credits <= $this->maxPerMinute) {
                $cache->increment($windowKey, $credits);

                return;
            }

            if ($mode === 'fail' || microtime(true) >= $deadline) {
                throw new RuntimeException(sprintf(
                    'broker credits exhausted for %s: used=%d requested=%d max_per_minute=%d mode=%s',
                    $operation,
                    $used,
                    $credits,
                    $this->maxPerMinute,
                    $mode,
                ));
            }

            usleep(250_000);
        } while (true);
    }
}
