<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RobinhoodTradeJournal;
use App\Robinhood\Execution\EquityExecutor;
use App\Robinhood\Execution\OrderRefStore;
use App\Robinhood\Execution\TacitEquityOwnership;
use App\Robinhood\Mcp\RobinhoodMcpClient;
use App\Robinhood\Rails\OrderIntent;
use App\Robinhood\Rails\RailPipeline;
use App\Robinhood\Telemetry\JsonlRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TacitEquityOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function cfg(): array
    {
        return array_replace((array) config('harness'), [
            'agentic_account' => 'fixture-account', 'oauth_token' => 'fixture-token',
            'basket' => ['enabled' => false],
        ]);
    }

    private function owned(array $attributes = []): RobinhoodTradeJournal
    {
        return RobinhoodTradeJournal::create(array_replace([
            'account_number' => 'fixture-account', 'asset_class' => 'equity',
            'symbol' => 'AAA', 'side' => 'buy', 'strategy_label' => TacitEquityOwnership::STRATEGY,
            'status' => 'filled', 'filled_quantity' => 0.25, 'broker_order_id' => 'fixture-order',
        ], $attributes));
    }

    public function test_foreign_buys_and_sells_cannot_review_or_place_even_with_empty_rails_and_context(): void
    {
        Http::fake();
        $owned = $this->owned();
        $before = $owned->refresh()->getRawOriginal();
        $cfg = $this->cfg();
        $executor = new EquityExecutor(RobinhoodMcpClient::fromConfig($cfg), new RailPipeline([]),
            new OrderRefStore, new JsonlRecorder(sys_get_temp_dir().'/theta-ownership-test-'.getmypid()), $cfg);
        foreach (['buy', 'sell'] as $side) {
            $result = $executor->execute(new OrderIntent(symbol: 'aaa', side: $side, dollarAmount: 5));
            $this->assertSame('LIVE_RISK_ABORTED', $result['classification']);
            $this->assertContains('equity_ownership:tacit_symbol_reserved', $result['verdict']->blockReasons());
        }
        Http::assertNothingSent();
        $this->assertSame($before, $owned->refresh()->getRawOriginal(), 'a foreign attempt cannot rewrite tacit fill truth');
    }

    public function test_enabled_universe_is_reserved_before_first_fill(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tacit_owner_');
        try {
            file_put_contents($path, "ticker,weight_pct\nAAA,100\n");
            $cfg = array_replace($this->cfg(), ['basket' => ['enabled' => true, 'weights_path' => $path]]);
            $gate = new TacitEquityOwnership;
            $this->assertSame('tacit_symbol_reserved', $gate->evaluate(new OrderIntent('AAA', 'buy'), $cfg)->reason);
            $this->assertTrue($gate->evaluate(new OrderIntent('OTHER', 'buy'), $cfg)->ok);
        } finally {
            unlink($path);
        }
    }

    public function test_missing_enabled_universe_fails_closed(): void
    {
        $cfg = array_replace($this->cfg(), ['basket' => ['enabled' => true, 'weights_path' => '/missing/weights']]);
        $this->assertSame('tacit_universe_unverified', (new TacitEquityOwnership)->evaluate(new OrderIntent('AAA', 'sell'), $cfg)->reason);
    }

    public function test_ambiguous_reference_retains_ownership_without_a_journal_or_enabled_basket(): void
    {
        $intent = new OrderIntent('AAA', 'buy', strategyLabel: TacitEquityOwnership::STRATEGY, logicalOrderId: 'basket:test:AAA');
        $store = new OrderRefStore;
        $ref = $store->reserve($intent);
        foreach (['submitting', 'failed', 'placed'] as $status) {
            $ref->forceFill(['status' => $status])->save();
            $this->assertSame('tacit_symbol_reserved', (new TacitEquityOwnership)->evaluate(new OrderIntent('AAA', 'sell'), $this->cfg())->reason);
        }
    }

    public function test_partial_fill_survives_cancelled_status_and_disabled_basket(): void
    {
        $this->owned(['status' => 'cancelled', 'broker_order_id' => null]);
        $this->assertFalse((new TacitEquityOwnership)->evaluate(new OrderIntent('AAA', 'sell'), $this->cfg())->ok);
    }

    public function test_other_accounts_and_option_journals_do_not_claim_an_equity(): void
    {
        $this->owned(['account_number' => 'other-account']);
        $this->owned(['asset_class' => 'option']);
        $this->assertTrue((new TacitEquityOwnership)->evaluate(new OrderIntent('AAA', 'buy'), $this->cfg())->ok);
    }

    public function test_unfilled_risk_abort_does_not_claim_a_disabled_symbol(): void
    {
        $this->owned(['status' => 'risk_aborted', 'filled_quantity' => null, 'broker_order_id' => null]);
        $this->assertTrue((new TacitEquityOwnership)->evaluate(new OrderIntent('AAA', 'buy'), $this->cfg())->ok);
    }

    public function test_tacit_buy_remains_allowed_and_tacit_sell_is_explicitly_blocked(): void
    {
        $this->owned();
        $gate = new TacitEquityOwnership;
        $this->assertTrue($gate->evaluate(new OrderIntent('AAA', 'buy', strategyLabel: TacitEquityOwnership::STRATEGY), $this->cfg())->ok);
        $this->assertSame('tacit_v1_buy_only', $gate->evaluate(new OrderIntent('AAA', 'sell', strategyLabel: TacitEquityOwnership::STRATEGY), $this->cfg())->reason);
    }

    public function test_asset_label_cannot_bypass_equity_executor_guard(): void
    {
        $this->owned();
        $result = (new TacitEquityOwnership)->evaluate(new OrderIntent('AAA', 'sell', assetClass: 'option'), $this->cfg());
        $this->assertSame('equity_executor_asset_mismatch', $result->reason);
    }
}
