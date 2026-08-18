<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thesis_intents', function (Blueprint $table) {
            $table->id();

            // Identity — a standing operator thesis, not a concrete contract.
            // Strikes are never stored here: they derive at pre-open assembly
            // against live spot (playbook lesson: pre-derived strikes die on
            // overnight gaps).
            $table->string('underlying_symbol', 12);
            $table->string('direction', 8); // bullish|bearish
            $table->string('structure', 32); // single_leg_long_call|single_leg_long_put
            $table->unsignedSmallInteger('dte_min');
            $table->unsignedSmallInteger('dte_max');
            $table->decimal('max_debit', 10, 2);
            $table->string('entry_limit_mode', 16)->default('mid');
            $table->decimal('entry_limit_offset_bps', 8, 2)->default(0);

            // The why — required before activation.
            $table->text('thesis');
            $table->text('catalyst')->nullable();

            // Precommitted kill triggers. The thesis stop is the operator's
            // one-tap judgment call and has no schema; these three are the
            // machine-evaluated set and all are required for activation.
            $table->decimal('price_stop', 12, 4)->nullable();
            $table->date('time_stop_date')->nullable();
            $table->decimal('profit_target_bps', 10, 2)->nullable();

            // Lifecycle: draft -> active -> parked|expired|closed.
            $table->string('status', 16)->default('draft');
            $table->string('close_reason', 64)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('parked_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->json('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'underlying_symbol']);
        });

        // Journal linkage: slate plays created from a thesis stamp their
        // origin. Nullable, no FK — matches the journal's FK-free convention.
        Schema::table('robinhood_trade_journal', function (Blueprint $table) {
            $table->unsignedBigInteger('thesis_intent_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('robinhood_trade_journal', function (Blueprint $table) {
            $table->dropIndex(['thesis_intent_id']);
            $table->dropColumn('thesis_intent_id');
        });

        Schema::drop('thesis_intents');
    }
};
