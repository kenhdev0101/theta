<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Mcp\BrokerCreditExhausted;
use App\Robinhood\Mcp\BrokerCreditLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class BrokerCreditLimiterTest extends TestCase
{
    public function test_fail_mode_throws_when_credits_are_exhausted(): void
    {
        Cache::store('array')->flush();

        $limiter = new BrokerCreditLimiter(
            enabled: true,
            maxPerMinute: 1,
            cacheKey: 'test:broker:credits:fail',
            defaultMode: 'fail',
            defaultMaxWaitSeconds: 0,
            cache: Cache::store('array'),
        );

        $limiter->acquire('first');

        $this->expectException(BrokerCreditExhausted::class);
        $this->expectExceptionMessage('broker credits exhausted');

        $limiter->acquire('second');
    }

    public function test_wait_mode_throws_after_bounded_wait_when_window_does_not_clear(): void
    {
        Cache::store('array')->flush();

        $limiter = new BrokerCreditLimiter(
            enabled: true,
            maxPerMinute: 1,
            cacheKey: 'test:broker:credits:wait',
            defaultMode: 'wait',
            defaultMaxWaitSeconds: 0,
            cache: Cache::store('array'),
        );

        $limiter->acquire('first');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mode=wait');

        $limiter->acquire('second');
    }

    public function test_standard_lane_leaves_shared_credits_for_stewardship(): void
    {
        Cache::store('array')->flush();
        $limiter = new BrokerCreditLimiter(true, 3, 'test:broker:reserved', 'fail', 0, Cache::store('array'), 1);
        $limiter->acquire('ingest-one');
        $limiter->acquire('ingest-two');
        try {
            $limiter->acquire('ingest-three');
            $this->fail('standard lane spent the stewardship reserve');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lane=standard ceiling=2', $e->getMessage());
        }
        $this->assertSame(2, Cache::store('array')->get('test:broker:reserved:'.now()->format('YmdHi')));
        $limiter->acquire('position-exit', lane: 'stewardship');
        $this->expectException(RuntimeException::class);
        $limiter->acquire('another-exit', lane: 'stewardship');
    }

    public function test_failed_cache_increment_never_grants_a_broker_credit(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('add')->willReturn(true);
        $cache->method('get')->willReturn(0);
        $cache->method('increment')->willReturn(false);
        $limiter = new BrokerCreditLimiter(true, 3, 'test:broker:failed-increment', 'fail', 0, $cache);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('broker credit reservation failed');
        $limiter->acquire('buy');
    }
}
