<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HarnessOrderRef;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Rails\OrderIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function intent(): OrderIntent
    {
        return new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'limit', dollarAmount: 5.0, source: 'test');
    }

    public function test_same_logical_intent_reuses_the_persisted_ref(): void
    {
        $store = new OrderRefStore;

        $first = $store->reserve($this->intent(), '2026-06-12');
        $second = $store->reserve($this->intent(), '2026-06-12');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->client_ref_id, $second->client_ref_id);
        $this->assertSame(1, HarnessOrderRef::count());
        $this->assertSame('reserved', $second->status);
    }

    public function test_a_new_trading_day_is_a_new_logical_order(): void
    {
        $store = new OrderRefStore;

        $day1 = $store->reserve($this->intent(), '2026-06-12');
        $day2 = $store->reserve($this->intent(), '2026-06-13');

        $this->assertNotSame($day1->client_ref_id, $day2->client_ref_id);
        $this->assertSame(2, HarnessOrderRef::count());
    }

    public function test_different_intent_parameters_produce_different_refs(): void
    {
        $store = new OrderRefStore;

        $a = $store->reserve($this->intent(), '2026-06-12');
        $b = $store->reserve(new OrderIntent(symbol: 'XYZ', side: 'buy', orderType: 'limit', dollarAmount: 6.0, source: 'test'), '2026-06-12');

        $this->assertNotSame($a->client_ref_id, $b->client_ref_id);
    }

    public function test_ref_survives_as_placed_with_broker_order_id(): void
    {
        $store = new OrderRefStore;

        $ref = $store->reserve($this->intent(), '2026-06-12');
        $store->markPlaced($ref, 'broker-123');

        $reloaded = $store->reserve($this->intent(), '2026-06-12');

        $this->assertSame('placed', $reloaded->status);
        $this->assertSame('broker-123', $reloaded->broker_order_id);
        $this->assertNotNull($reloaded->placed_at);
    }
}
