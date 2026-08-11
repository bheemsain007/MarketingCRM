<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw activity signal used to compute active vs idle time (DATABASE_SCHEMA §2.11).
 *
 * Any gap longer than IDLE_THRESHOLD_MINUTES between pings counts as idle.
 * High volume, low long-term value - pruned by the retention job once the
 * owning session has been rolled up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_activity_pings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('work_session_id')->nullable()->constrained('user_work_sessions')->cascadeOnDelete();

            // call | note | status_change | message | follow_up | page_view |
            // heartbeat
            $table->string('action_type', 30);
            $table->nullableMorphs('reference');

            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['work_session_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_pings');
    }
};
