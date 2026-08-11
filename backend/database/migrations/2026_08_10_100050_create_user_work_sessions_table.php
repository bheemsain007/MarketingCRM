<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance / productivity sessions (DATABASE_SCHEMA §2.10, FR-ATT-01..04).
 *
 * FR-RPT-01 requires active time, idle time and office-hours activity. Nothing
 * else in the schema could produce those numbers, so this table exists from
 * Phase 2 rather than being retrofitted at Phase 26.
 *
 * Idle is NOT "not on a call" - a telecaller writing notes is working
 * (GLOSSARY §2.3). Active seconds are rolled up from user_activity_pings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_work_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            // logout | timeout | forced | shift_end
            $table->string('end_reason', 20)->nullable();

            $table->string('source', 10)->default('web'); // web | android

            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('idle_seconds')->default(0);
            // Marked breaks are excluded from idle so a break does not read as
            // slacking in reports.
            $table->unsignedInteger('break_seconds')->default(0);

            $table->timestamp('break_started_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_id', 100)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            // Scheduler closes stale open sessions so attendance stays accurate
            // when someone forgets to log out.
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_work_sessions');
    }
};
