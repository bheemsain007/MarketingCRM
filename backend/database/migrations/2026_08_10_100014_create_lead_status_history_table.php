<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only status audit (BR-STAT-03, FR-STAT-03).
 *
 * No timestamps() - only created_at. No soft deletes, no updates: rows here are
 * never modified or removed. This table answers "who moved this lead and why",
 * which is the first question asked when a conversion is disputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            // Null on the very first entry (lead creation).
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            // Null when changed by the system (Interest Engine, scheduler).
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();

            // call | ai_call | whatsapp | email | sms | rcs | voice | manual |
            // system | import
            $table->string('source_channel', 20)->default('manual');

            // Required for reopen transitions (BR-STAT-02).
            $table->string('reason', 255)->nullable();

            // Evidence: the call or message that caused the change.
            $table->nullableMorphs('reference');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'created_at']);
            $table->index('to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status_history');
    }
};
