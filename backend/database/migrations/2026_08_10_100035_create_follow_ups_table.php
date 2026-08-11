<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-ups (FR-FUP-01..05, BR-FUP-01..03).
 *
 * Rescheduling writes a NEW row and closes the old one rather than overwriting
 * `scheduled_at`, so the history of what was promised and when survives
 * (BR-FUP-03). `rescheduled_from_id` chains them together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // call | whatsapp | email | sms | rcs | voice | visit
            $table->string('channel', 20)->default('call');

            $table->timestamp('scheduled_at');
            // open | completed | missed | cancelled | rescheduled
            $table->string('status', 20)->default('open');

            $table->string('subject', 190)->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('outcome')->nullable();

            $table->foreignId('rescheduled_from_id')->nullable()->constrained('follow_ups')->nullOnDelete();

            $table->boolean('reminder_sent')->default(false);
            $table->timestamp('reminder_sent_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // "My follow-ups due today" - the telecaller's main work queue.
            $table->index(['assigned_to', 'status', 'scheduled_at']);
            $table->index(['lead_id', 'status']);
            // Scheduler scans this for due reminders and missed detection.
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
