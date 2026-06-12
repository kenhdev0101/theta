<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency spine: one durable client_ref_id per logical order intent.
 * The ref is persisted BEFORE the place call and replayed verbatim on
 * transport retry, so a retried submission cannot double-place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harness_order_refs', function (Blueprint $table): void {
            $table->id();
            $table->string('intent_key', 64)->unique()->comment('sha256 of (day, asset, symbol, side, type, qty, dollars, limit, source)');
            $table->uuid('client_ref_id')->unique();
            $table->string('symbol', 32);
            $table->string('side', 8);
            $table->string('status', 24)->default('reserved')->comment('reserved|placed|failed|cancelled');
            $table->json('order_payload')->nullable();
            $table->string('broker_order_id', 64)->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->index(['symbol', 'side', 'status'], 'harness_refs_symbol_side_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harness_order_refs');
    }
};
