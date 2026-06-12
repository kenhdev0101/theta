<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Robinhood portfolio-manager journal for equities we can route now and options
 * candidates/watchlist legs we need to track before option order routing is exposed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('robinhood_trade_journal')) {
            return;
        }

        Schema::create('robinhood_trade_journal', function (Blueprint $table): void {
            $table->id();

            // Identity / broker linkage.
            $table->string('account_number', 64)->nullable();
            $table->string('asset_class', 16)->comment('equity|option');
            $table->string('symbol', 32);
            $table->string('underlying_symbol', 32)->nullable();
            $table->string('option_id', 64)->nullable();
            $table->string('option_type', 8)->nullable()->comment('call|put');
            $table->date('expiration_date')->nullable();
            $table->decimal('strike_price', 18, 8)->nullable();

            // Lifecycle: candidate -> watchlisted/reviewed -> submitted/open -> closed/rejected/cancelled.
            $table->string('status', 32)->default('candidate');
            $table->string('intent', 32)->nullable()->comment('long_call|long_put|long_equity|short_equity|watch_only');
            $table->string('side', 8)->nullable()->comment('buy|sell');
            $table->string('order_type', 24)->nullable()->comment('market|limit|stop_market|stop_limit');
            $table->string('time_in_force', 8)->nullable();
            $table->string('market_hours', 24)->nullable();

            // Order and sizing fields.
            $table->uuid('client_ref_id')->nullable()->unique();
            $table->string('broker_order_id', 64)->nullable();
            $table->decimal('quantity', 18, 8)->nullable();
            $table->decimal('dollar_amount', 14, 2)->nullable();
            $table->decimal('limit_price', 18, 8)->nullable();
            $table->decimal('stop_price', 18, 8)->nullable();
            $table->decimal('review_estimated_cost', 14, 2)->nullable();
            $table->decimal('average_fill_price', 18, 8)->nullable();
            $table->decimal('filled_quantity', 18, 8)->nullable();

            // Thesis and structure extraction.
            $table->string('strategy_label', 64)->default('options_portfolio_manager');
            $table->string('structure_class', 64)->nullable();
            $table->string('relative_strength_state', 96)->nullable();
            $table->string('gap_classification', 96)->nullable();
            $table->string('trend_geometry_state', 96)->nullable();
            $table->string('liquidity_tier', 64)->nullable();
            $table->integer('days_until_earnings')->nullable();
            $table->integer('days_since_earnings')->nullable();
            $table->text('thesis')->nullable();
            $table->text('invalidation')->nullable();

            // Raw immutable-ish artifacts for audit/replay.
            $table->json('scanner_payload')->nullable();
            $table->json('option_math_payload')->nullable();
            $table->json('review_payload')->nullable();
            $table->json('broker_payload')->nullable();
            $table->json('notes')->nullable();

            // Timing and realized outcome.
            $table->timestamp('watchlisted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('realized_pnl', 14, 2)->nullable();
            $table->decimal('unrealized_pnl', 14, 2)->nullable();

            $table->timestamps();

            $table->index(['status', 'asset_class'], 'rh_journal_status_asset_idx');
            $table->index(['symbol', 'status'], 'rh_journal_symbol_status_idx');
            $table->index(['underlying_symbol', 'expiration_date'], 'rh_journal_underlying_exp_idx');
            $table->index('option_id', 'rh_journal_option_id_idx');
            $table->index('broker_order_id', 'rh_journal_broker_order_idx');
            $table->index('submitted_at', 'rh_journal_submitted_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robinhood_trade_journal');
    }
};
