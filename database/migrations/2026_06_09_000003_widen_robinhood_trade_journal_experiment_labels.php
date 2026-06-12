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
            $table->string('intent', 96)->nullable()->change();
            $table->string('strategy_label', 96)->default('options_portfolio_manager')->change();
            $table->string('structure_class', 96)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('robinhood_trade_journal')) {
            return;
        }

        Schema::table('robinhood_trade_journal', function (Blueprint $table): void {
            $table->string('intent', 32)->nullable()->change();
            $table->string('strategy_label', 64)->default('options_portfolio_manager')->change();
            $table->string('structure_class', 64)->nullable()->change();
        });
    }
};
