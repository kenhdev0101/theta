<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convex_probe_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('thesis_id', 63)->unique();
            $table->string('leg_id', 63);
            $table->string('account_number', 20);
            $table->string('status', 24)->default('staged');
            $table->json('spec');
            $table->string('spec_hash', 64);
            $table->string('option_id', 64)->nullable();
            $table->string('chain_id', 64)->nullable();
            $table->string('underlying_symbol', 12);
            $table->string('option_type', 4);
            $table->date('expiration_date')->nullable();
            $table->decimal('strike_price', 12, 4)->nullable();
            $table->string('entry_intent_key', 64)->nullable();
            $table->string('entry_client_ref_id', 64)->nullable();
            $table->string('entry_order_id', 64)->nullable();
            $table->timestamp('entry_submitted_at')->nullable();
            $table->decimal('entry_limit_price', 12, 4)->nullable();
            $table->decimal('entry_filled_qty', 10, 4)->default(0);
            $table->decimal('entry_average_price', 12, 4)->nullable();
            $table->string('exit_intent_key', 64)->nullable();
            $table->string('exit_client_ref_id', 64)->nullable();
            $table->string('exit_order_id', 64)->nullable();
            $table->unsignedSmallInteger('exit_attempt')->default(0);
            $table->timestamp('exit_submitted_at')->nullable();
            $table->decimal('exit_filled_qty', 10, 4)->default(0);
            $table->decimal('exit_average_price', 12, 4)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('exit_due_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('block_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convex_probe_runs');
    }
};
