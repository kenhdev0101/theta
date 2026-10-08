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
        private readonly int $reserveForStewardship = 0,
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
            reserveForStewardship: (int) ($credits['reserve_for_stewardship'] ?? 0),
        );
    }

    public function acquire(string $operation, int $credits = 1, ?string $mode = null, ?int $maxWaitSeconds = null, string $lane = 'standard'): void
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
        if (! in_array($lane, ['standard', 'stewardship'], true)) {
            throw new RuntimeException("broker credits lane '{$lane}' is invalid");
        }
        $ceiling = $lane === 'stewardship' ? $this->maxPerMinute
            : max(0, $this->maxPerMinute - max(0, $this->reserveForStewardship));

        $cache = $this->cache ?? Cache::store();
        $deadline = microtime(true) + max(0, $maxWaitSeconds);

        do {
            $windowKey = $this->cacheKey.':'.now()->format('YmdHi');
            $cache->add($windowKey, 0, now()->addSeconds(70));
            // Reserve with one atomic increment across all workers. A failed
            // reservation is rolled back before waiting; it never sends HTTP.
            $used = (int) $cache->increment($windowKey, $credits);
            if ($used <= $ceiling) {
                return;
            }
            $cache->decrement($windowKey, $credits);

            if ($mode === 'fail' || microtime(true) >= $deadline) {
                throw new RuntimeException(sprintf(
                    'broker credits exhausted for %s: used=%d requested=%d max_per_minute=%d lane=%s ceiling=%d mode=%s',
                    $operation,
                    $used - $credits,
                    $credits,
                    $this->maxPerMinute,
                    $lane,
                    $ceiling,
                    $mode,
                ));
            }

            usleep(250_000);
        } while (true);
    }
}
