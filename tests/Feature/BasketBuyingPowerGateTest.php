<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Robinhood\Basket\BasketBuyingPowerGate;
use App\Robinhood\Rails\OrderIntent;
use Tests\TestCase;

class BasketBuyingPowerGateTest extends TestCase
{
    public function test_requires_basket_authorization_and_verified_unleveraged_power(): void
    {
        $gate = new BasketBuyingPowerGate;
        $cfg = ['basket' => ['funding_source' => 'broker_buying_power']];
        $intent = new OrderIntent('AAA', 'buy', dollarAmount: 50, strategyLabel: 'theta_basket_etf');
        $this->assertFalse($gate->evaluate($intent, $cfg, ['broker_buying_power' => 317.08])->ok);
        $this->assertFalse($gate->evaluate(new OrderIntent('AAA', 'buy', dollarAmount: 50), $cfg, ['broker_buying_power' => 317.08, 'unleveraged_buying_power' => 317.08])->ok);
        $this->assertFalse($gate->evaluate($intent, $cfg, ['broker_buying_power' => 317.08, 'unleveraged_buying_power' => 40])->ok);
        $this->assertFalse($gate->evaluate($intent, $cfg, ['broker_buying_power' => 317.08, 'unleveraged_buying_power' => 317.08, 'open_order_reserved_debit' => 300])->ok);
        $this->assertTrue($gate->evaluate($intent, $cfg, ['broker_buying_power' => 317.08, 'unleveraged_buying_power' => 317.08])->ok);
    }
}
