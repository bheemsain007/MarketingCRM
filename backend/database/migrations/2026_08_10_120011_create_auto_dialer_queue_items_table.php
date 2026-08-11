<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered work list behind a dialling session (FR-CALL-06/07).
 *
 * Skipped leads are kept as rows with a reason rather than being dropped from
 * the queue: FR-CALL-07 requires skips to be *logged*, and a lead that
 * silently vanished from a run is indistinguishable from one that was never
 * queued. The reason is also the only way to answer "why did the dialer never
 * ring this person?" without re-deriving the whole run.
 *
 * The unique index on (session, lead) stops one run offering the same person
 * twice; cross-session collisions are prevented at claim time by a row lock
 * (BR-CALL-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_dialer_queue_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('auto_dialer_session_id')
                ->constrained('auto_dialer_sessions')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->unsignedInteger('position');

            // pending | dialling | dialled | skipped
            $table->string('state', 20)->default('pending');

            // Set only on skipped rows: suppressed | no_phone | cooldown |
            // follow_up_scheduled | outside_calling_hours | claimed_elsewhere |
            // archived
            $table->string('skip_reason', 40)->nullable();

            $table->foreignId('call_id')->nullable()->constrained('calls')->nullOnDelete();

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Index names are given explicitly: the generated ones combine the
            // table and every column and blow past MySQL's 64-character
            // identifier limit.
            $table->unique(['auto_dialer_session_id', 'lead_id'], 'dialer_items_session_lead_unique');
            // The next-lead query: pending items in order, within one session.
            $table->index(['auto_dialer_session_id', 'state', 'position'], 'dialer_items_session_state_pos_idx');
            // Claim-time collision check across concurrent sessions.
            $table->index(['lead_id', 'state'], 'dialer_items_lead_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_dialer_queue_items');
    }
};
