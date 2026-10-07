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
            $table->timestamp('thesis_expired_at')->nullable();
            $table->string('thesis_expiry_event_id', 128)->nullable();
            $table->string('thesis_expiry_source', 128)->nullable();
            $table->string('thesis_expiry_reason', 255)->nullable();
            $table->string('exit_reason', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('convex_probe_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'thesis_expired_at', 'thesis_expiry_event_id', 'thesis_expiry_source',
                'thesis_expiry_reason', 'exit_reason',
            ]);
        });
    }
};
