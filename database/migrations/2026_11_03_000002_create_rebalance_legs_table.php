<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebalance ledger — line items (thesis §11.5). One row per basket name a
 * pass touches. Points at the RobinhoodTradeJournal row by client_ref_id for
 * order/fill truth — NO duplicated fill columns here (reuse boundary §11.5).
 *
 * The leg carries only basket context: target weight, drift, action, reason,
 * the fractionability gate result (§11.6), and the planned buy notional. Fill
 * truth (filled_qty, fill_price, filled_at, status) lives on the journal row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebalance_legs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rebalance_event_id')
                ->constrained('rebalance_events')
                ->cascadeOnDelete();

            $table->string('ticker', 16);
            $table->string('logical_order_id', 128)->comment('durable idempotency key for this slot: durable|strategy|logical_id');

            // Target vs. broker-truth current position.
            $table->decimal('weight_target', 12, 10)->comment('normalized basket weight fraction (0..1)');
            $table->decimal('notional_target', 16, 2)->comment('target position USD = weight_target x total_equity');
            $table->decimal('position_before_shares', 18, 6)->default(0)->comment('broker truth');
            $table->decimal('position_before_mv', 16, 2)->default(0)->comment('broker truth');
            $table->decimal('drift_usd', 16, 2)->comment('notional_target - position_before_mv');
            $table->decimal('planned_buy_usd', 16, 2)->default(0)->comment('bounded notional buy this pass (0 for hold/drop)');

            $table->string('action', 8)->comment('buy|sell|hold|drop');
            $table->string('reason', 32)->comment('deposit_fill|drift_trim|new_admit|dropped_untradable|at_target');

            // Fractionability gate (§11.6). Null = unverified (never silently admitted).
            $table->boolean('fractionable')->nullable();

            // Order/fill truth BY REFERENCE — the journal row owns the fill columns.
            $table->uuid('client_ref_id')->nullable();
            $table->string('broker_order_id', 64)->nullable();

            $table->json('plan_notes')->nullable()->comment('cap application, water-fill share, raw tradability verdict');
            $table->timestamps();

            $table->index(['rebalance_event_id', 'action'], 'rebalance_legs_event_action_idx');
            $table->index('ticker', 'rebalance_legs_ticker_idx');
            $table->index('client_ref_id', 'rebalance_legs_client_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebalance_legs');
    }
};
