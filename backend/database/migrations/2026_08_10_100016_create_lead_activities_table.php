<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user-facing lead timeline (FR-LEAD-09).
 *
 * Deliberately separate from `audit_logs`: this is what a telecaller reads to
 * understand a lead's history, whereas audit_logs is compliance-grade evidence
 * (SEC-AUD-04). Different audiences, different retention.
 *
 * Denormalised on purpose - the timeline must render from ONE query rather than
 * a six-way union across calls/messages/status/notes/follow-ups/payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // call | message | status_change | note | follow_up | assignment |
            // payment | interest | dnc | import
            $table->string('activity_type', 30);

            $table->string('title', 190);
            $table->text('description')->nullable();

            // Points at the underlying record (call, message, follow_up...).
            $table->nullableMorphs('subject');

            // Extra display detail (duration, channel, amount) so the timeline
            // needs no further lookups to render.
            $table->json('meta')->nullable();

            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['lead_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index('activity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
