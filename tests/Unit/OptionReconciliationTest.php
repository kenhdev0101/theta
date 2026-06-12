<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Reconciliation\BrokerTruthReconciler;
use PHPUnit\Framework\TestCase;

class OptionReconciliationTest extends TestCase
{
    private BrokerTruthReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new BrokerTruthReconciler;
    }

    private function brokerOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => 'opt-order-1',
            'ref_id' => 'ref-1',
            'chain_symbol' => 'SPY',
            'state' => 'filled',
            'quantity' => '1',
            'processed_quantity' => '1',
            'average_price' => '0.500000',
            'legs' => [['option_id' => 'opt-uuid-1', 'side' => 'buy', 'position_effect' => 'open']],
        ], $overrides);
    }

    public function test_broker_proven_option_fill_classifies_live_filled(): void
    {
        $this->assertSame(BrokerTruthReconciler::LIVE_FILLED, $this->reconciler->classifyOption($this->brokerOrder()));
    }

    public function test_filled_label_without_option_id_is_label_conflict(): void
    {
        $row = $this->brokerOrder(['legs' => [['side' => 'buy', 'position_effect' => 'open']]]);

        $this->assertSame(BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED, $this->reconciler->classifyOption($row));
    }

    public function test_filled_label_with_zero_processed_quantity_is_label_conflict(): void
    {
        $row = $this->brokerOrder(['processed_quantity' => '0']);

        $this->assertSame(BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED, $this->reconciler->classifyOption($row));
    }

    public function test_open_states_classify_posted_not_filled(): void
    {
        foreach (['queued', 'confirmed', 'placed'] as $state) {
            $row = $this->brokerOrder(['state' => $state, 'processed_quantity' => '0', 'average_price' => null]);
            $this->assertSame(BrokerTruthReconciler::LIVE_POSTED_NOT_FILLED, $this->reconciler->classifyOption($row), $state);
        }
    }

    public function test_rejected_and_cancelled_classify_explicitly(): void
    {
        $this->assertSame(
            BrokerTruthReconciler::LIVE_REJECTED,
            $this->reconciler->classifyOption($this->brokerOrder(['state' => 'rejected', 'processed_quantity' => '0', 'average_price' => null])),
        );
        $this->assertSame(
            BrokerTruthReconciler::LIVE_CANCELLED,
            $this->reconciler->classifyOption($this->brokerOrder(['state' => 'cancelled', 'processed_quantity' => '0', 'average_price' => null])),
        );
    }

    public function test_cancel_after_partial_fill_is_still_a_fill_outcome(): void
    {
        $row = $this->brokerOrder(['state' => 'cancelled', 'processed_quantity' => '0.5', 'average_price' => '0.500000']);

        $this->assertSame(BrokerTruthReconciler::LIVE_FILLED, $this->reconciler->classifyOption($row));
    }

    public function test_malformed_legs_payloads_classify_without_errors(): void
    {
        $missingLegs = $this->brokerOrder();
        unset($missingLegs['legs']);
        $this->assertSame(BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED, $this->reconciler->classifyOption($missingLegs));

        $scalarLegs = $this->brokerOrder(['legs' => 'corrupt']);
        $this->assertSame(BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED, $this->reconciler->classifyOption($scalarLegs));

        $emptyLegs = $this->brokerOrder(['legs' => []]);
        $result = $this->reconciler->reconcileOptions([$emptyLegs], []);
        $this->assertNull($result['ledger'][0]['option_id']);
        $this->assertNull($result['ledger'][0]['position_effect']);
    }

    public function test_dry_run_option_rows_are_fixtures_not_market_outcomes(): void
    {
        $classification = $this->reconciler->classifyOption($this->brokerOrder(['dry_run' => true]));

        $this->assertSame(BrokerTruthReconciler::TEST_FIXTURE_ONLY, $classification);
        $this->assertFalse($this->reconciler->isMarketOutcome($classification));
    }

    public function test_reconcile_options_matches_journal_and_summarizes(): void
    {
        $result = $this->reconciler->reconcileOptions(
            [
                $this->brokerOrder(),
                $this->brokerOrder(['id' => 'opt-order-2', 'ref_id' => 'ref-2', 'state' => 'queued', 'processed_quantity' => '0', 'average_price' => null]),
            ],
            [
                ['client_ref_id' => 'ref-1', 'broker_order_id' => 'opt-order-1', 'status' => 'submitted', 'underlying_symbol' => 'SPY', 'option_id' => 'opt-uuid-1', 'side' => 'buy', 'position_effect' => 'open'],
                ['client_ref_id' => 'ref-2', 'broker_order_id' => null, 'status' => 'submitted', 'underlying_symbol' => 'SPY', 'option_id' => 'opt-uuid-1', 'side' => 'buy', 'position_effect' => 'open'],
            ],
        );

        $this->assertSame(1, $result['summary'][BrokerTruthReconciler::LIVE_FILLED]);
        $this->assertSame(1, $result['summary'][BrokerTruthReconciler::LIVE_POSTED_NOT_FILLED]);
        $this->assertTrue(collect($result['ledger'])->every(fn ($row) => $row['journal_matched']));
        $this->assertSame('opt-uuid-1', $result['ledger'][0]['option_id']);
        $this->assertSame('open', $result['ledger'][0]['position_effect']);
    }

    public function test_orphan_local_option_submission_surfaces_as_unknown(): void
    {
        $result = $this->reconciler->reconcileOptions(
            [],
            [['client_ref_id' => 'ref-9', 'status' => 'submitted', 'underlying_symbol' => 'SPY', 'option_id' => 'opt-x', 'side' => 'buy', 'position_effect' => 'open']],
        );

        $this->assertCount(1, $result['ledger']);
        $this->assertSame(BrokerTruthReconciler::UNKNOWN_EXECUTION_STATE, $result['ledger'][0]['classification']);
        $this->assertSame('journal_orphan', $result['ledger'][0]['source']);
    }
}
