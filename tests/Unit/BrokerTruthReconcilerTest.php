<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Robinhood\Reconciliation\BrokerTruthReconciler;
use PHPUnit\Framework\TestCase;

class BrokerTruthReconcilerTest extends TestCase
{
    private BrokerTruthReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new BrokerTruthReconciler;
    }

    public function test_broker_proven_fill_classifies_live_filled(): void
    {
        $this->assertSame(BrokerTruthReconciler::LIVE_FILLED, $this->reconciler->classify([
            'state' => 'filled',
            'filled_quantity' => 1.0,
            'average_fill_price' => 12.34,
        ]));
    }

    public function test_filled_label_with_zero_qty_is_label_conflict_not_a_fill(): void
    {
        $this->assertSame(BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED, $this->reconciler->classify([
            'state' => 'filled',
            'filled_quantity' => 0,
            'average_fill_price' => 12.34,
        ]));
    }

    public function test_filled_label_with_zero_price_is_label_conflict_not_a_fill(): void
    {
        $this->assertSame(BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED, $this->reconciler->classify([
            'status' => 'filled',
            'filled_qty' => 2.0,
            'fill_price' => 0,
        ]));
    }

    public function test_open_states_classify_posted_not_filled(): void
    {
        foreach (['queued', 'confirmed', 'placed', 'pending'] as $state) {
            $this->assertSame(
                BrokerTruthReconciler::LIVE_POSTED_NOT_FILLED,
                $this->reconciler->classify(['state' => $state]),
                $state,
            );
        }
    }

    public function test_rejected_and_cancelled_classify_explicitly(): void
    {
        $this->assertSame(BrokerTruthReconciler::LIVE_REJECTED, $this->reconciler->classify(['state' => 'rejected']));
        $this->assertSame(BrokerTruthReconciler::LIVE_CANCELLED, $this->reconciler->classify(['state' => 'cancelled']));
    }

    public function test_cancel_after_partial_fill_is_still_a_fill_outcome(): void
    {
        $this->assertSame(BrokerTruthReconciler::LIVE_FILLED, $this->reconciler->classify([
            'state' => 'cancelled',
            'filled_quantity' => 0.5,
            'average_fill_price' => 10.0,
        ]));
    }

    public function test_fixture_and_dry_run_rows_are_not_market_outcomes(): void
    {
        $fixture = $this->reconciler->classify(['state' => 'fixture']);
        $dryRun = $this->reconciler->classify(['state' => 'filled', 'filled_quantity' => 1, 'average_fill_price' => 1, 'dry_run' => true]);

        $this->assertSame(BrokerTruthReconciler::TEST_FIXTURE_ONLY, $fixture);
        $this->assertSame(BrokerTruthReconciler::TEST_FIXTURE_ONLY, $dryRun);
        $this->assertFalse($this->reconciler->isMarketOutcome($fixture));
        $this->assertFalse($this->reconciler->isMarketOutcome(BrokerTruthReconciler::BROKER_CAPABILITY_PENDING));
    }

    public function test_unknown_status_is_named_unknown_not_guessed(): void
    {
        $this->assertSame(BrokerTruthReconciler::UNKNOWN_EXECUTION_STATE, $this->reconciler->classify(['state' => 'weird_new_state']));
    }

    public function test_reconcile_flags_label_conflict_and_matches_journal(): void
    {
        $result = $this->reconciler->reconcile(
            [
                ['id' => 'b-1', 'ref_id' => 'ref-1', 'symbol' => 'AAPL', 'side' => 'buy', 'state' => 'filled', 'filled_quantity' => 0, 'average_fill_price' => 0],
                ['id' => 'b-2', 'ref_id' => 'ref-2', 'symbol' => 'MSFT', 'side' => 'buy', 'state' => 'filled', 'filled_quantity' => 1, 'average_fill_price' => 400.10],
            ],
            [
                ['client_ref_id' => 'ref-1', 'broker_order_id' => 'b-1', 'status' => 'submitted', 'symbol' => 'AAPL', 'side' => 'buy'],
                ['client_ref_id' => 'ref-2', 'broker_order_id' => 'b-2', 'status' => 'submitted', 'symbol' => 'MSFT', 'side' => 'buy'],
            ],
        );

        $this->assertSame(1, $result['summary'][BrokerTruthReconciler::LABEL_CONFLICT_NOT_BROKER_FILLED]);
        $this->assertSame(1, $result['summary'][BrokerTruthReconciler::LIVE_FILLED]);
        $this->assertTrue(collect($result['ledger'])->every(fn ($row) => $row['journal_matched']));
        $this->assertTrue(collect($result['ledger'])->every(fn ($row) => $row['agent_id'] === 'theta-harness'));
    }

    public function test_reconcile_surfaces_orphan_local_submission_as_unknown_state(): void
    {
        $result = $this->reconciler->reconcile(
            [],
            [['client_ref_id' => 'ref-9', 'broker_order_id' => null, 'status' => 'submitted', 'symbol' => 'TSLA', 'side' => 'buy']],
        );

        $this->assertCount(1, $result['ledger']);
        $this->assertSame(BrokerTruthReconciler::UNKNOWN_EXECUTION_STATE, $result['ledger'][0]['classification']);
        $this->assertSame('journal_orphan', $result['ledger'][0]['source']);
    }
}
