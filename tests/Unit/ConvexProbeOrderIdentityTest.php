<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Options\OptionOrderIntent;
use PHPUnit\Framework\TestCase;

final class ConvexProbeOrderIdentityTest extends TestCase
{
    public function test_one_logical_entry_keeps_its_ref_across_requotes_and_restart_days(): void
    {
        $first = $this->intent(0.22, 'probe:entry:1');
        $requoted = $this->intent(0.23, 'probe:entry:1');
        $nextExitAttempt = $this->intent(0.22, 'probe:exit:2');

        $this->assertSame($first->intentKey('2026-10-07'), $requoted->intentKey('2026-10-08'));
        $this->assertNotSame($first->intentKey('2026-10-07'), $nextExitAttempt->intentKey('2026-10-08'));
        $this->assertSame($first->intentKey('2026-10-07'), $first->withContract('option-1', 'chain-1')->intentKey('2026-10-08'));
        $this->assertSame('convex_thesis_probe', $first->withContract('option-1', 'chain-1')->strategyLabel);
    }

    private function intent(float $limit, string $logicalId): OptionOrderIntent
    {
        return new OptionOrderIntent(
            underlyingSymbol: 'XLF', optionType: 'call', expirationDate: '2026-10-23', strikePrice: 55.5,
            side: 'buy', positionEffect: 'open', quantity: 1, limitPrice: $limit,
            source: 'theta:convex-probe', logicalOrderId: $logicalId,
            strategyLabel: 'convex_thesis_probe', metadata: ['thesis_id' => 'probe'],
        );
    }
}
