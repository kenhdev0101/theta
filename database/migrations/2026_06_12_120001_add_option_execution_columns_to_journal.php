<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Options execution columns: the broker chain UUID and the leg's
 * position_effect (open/close) become first-class queryable fields now
 * that the agentic account is options-approved (option_level_2,
 * 2026-06-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('robinhood_trade_journal', function (Blueprint $table): void {
            $table->string('chain_id', 64)->nullable()->after('option_id');
            $table->string('position_effect', 8)->nullable()->after('side');

            $table->index('chain_id', 'rh_journal_chain_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('robinhood_trade_journal', function (Blueprint $table): void {
            $table->dropIndex('rh_journal_chain_id_idx');
            $table->dropColumn(['chain_id', 'position_effect']);
        });
    }
};
