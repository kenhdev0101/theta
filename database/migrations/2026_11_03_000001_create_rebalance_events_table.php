<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rebalance ledger — parent table (thesis §11.5). One row per rebalance pass
 * of the harness-as-ETF-wrapper. A pass is a first-class durable entity: it
 * snapshots broker/account truth at open, records the reproducible weights
 * source, and reconciles its outcome totals against broker truth at close.
 *
 * Artifact-ownership law: owner + agent_id are machine-readable columns.
 * Fill truth stays on the RobinhoodTradeJournal row referenced by each leg's
 * client_ref_id — this table carries basket context and outcome totals only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebalance_events', function (Blueprint $table): void {
            $table->id();

            // Artifact-ownership law (CLAUDE.md "Artifact Ownership And Agent IDs").
            $table->string('owner', 64)->comment('producing team/role');
            $table->string('agent_id', 64)->comment('producing agent');

            $table->string('trigger', 24)->comment('deposit|drift|score_change|manual|scheduled');
            $table->timestamp('as_of');

            // Broker-truth snapshot at open (null = unverified, never assumed).
            $table->decimal('settled_cash_before', 16, 2)->nullable();
            $table->decimal('basket_mv_before', 16, 2)->nullable()->comment('MV of basket-ticker positions, broker truth');
            $table->decimal('book_before', 16, 2)->nullable()->comment('total account equity MV, broker truth');
            $table->boolean('deposit_detected')->default(false);
            $table->decimal('deposit_amount', 16, 2)->nullable()->comment('settled_cash_before - prior pass settled_cash_after when positive');

            // Reproducible target source.
            $table->string('thesis_spec_id', 128)->nullable();
            $table->string('spec_version', 64)->nullable();
            $table->string('weights_source', 191)->comment('weights artifact id, e.g. docs/weighting-basket.csv');
            $table->string('weights_payload_hash', 64)->comment('sha256 of the normalized weight rows');

            // Outcome totals (planned in dry-run; reconciled from broker truth after an armed pass).
            $table->decimal('total_equity', 16, 2)->nullable()->comment('settled_cash_before + basket_mv_before');
            $table->unsignedInteger('n_legs')->default(0);
            $table->decimal('gross_buys_usd', 16, 2)->default(0);
            $table->decimal('gross_sells_usd', 16, 2)->default(0)->comment('always 0 in buy-only V1');
            $table->decimal('cash_deployed', 16, 2)->default(0);
            $table->decimal('settled_cash_after', 16, 2)->nullable();

            $table->string('status', 24)->default('planned')
                ->comment('planned|placing|placed|reconciled|partially_filled|aborted');

            // Rail/mode context snapshot — makes an inert patch visible in the ledger.
            $table->boolean('dry_run')->default(true);
            $table->boolean('kill_switch')->comment('kill switch state at pass open');
            $table->boolean('armed')->default(false)->comment('operator arm flag at pass open');

            $table->json('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'as_of'], 'rebalance_events_status_asof_idx');
            $table->index('trigger', 'rebalance_events_trigger_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebalance_events');
    }
};
