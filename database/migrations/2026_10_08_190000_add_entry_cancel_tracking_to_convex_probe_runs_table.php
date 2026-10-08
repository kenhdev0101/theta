<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convex_probe_runs', function (Blueprint $table): void {
            $table->timestamp('entry_cancel_requested_at')->nullable();
            $table->string('entry_cancel_reason', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('convex_probe_runs', function (Blueprint $table): void {
            $table->dropColumn(['entry_cancel_requested_at', 'entry_cancel_reason']);
        });
    }
};
