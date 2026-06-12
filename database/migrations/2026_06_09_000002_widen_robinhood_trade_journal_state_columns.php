<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('robinhood_trade_journal')) {
            return;
        }

        Schema::table('robinhood_trade_journal', function (Blueprint $table): void {
            $table->string('relative_strength_state', 96)->nullable()->change();
            $table->string('gap_classification', 96)->nullable()->change();
            $table->string('trend_geometry_state', 96)->nullable()->change();
            $table->string('liquidity_tier', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robinhood_trade_journal')) {
            return;
        }

        Schema::table('robinhood_trade_journal', function (Blueprint $table): void {
            $table->string('relative_strength_state', 32)->nullable()->change();
            $table->string('gap_classification', 32)->nullable()->change();
            $table->string('trend_geometry_state', 64)->nullable()->change();
            $table->string('liquidity_tier', 32)->nullable()->change();
        });
    }
};
