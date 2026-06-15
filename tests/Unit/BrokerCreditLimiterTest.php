<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Mcp\BrokerCreditLimiter;
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

        $this->expectException(RuntimeException::class);
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
}
