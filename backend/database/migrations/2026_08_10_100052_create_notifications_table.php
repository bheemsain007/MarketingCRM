<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTERNAL notifications to CRM users (DATABASE_SCHEMA §2.13, BR-NOTIF-01..04).
 *
 * These target staff, not leads. They are unrelated to `messages` and are NEVER
 * subject to DNC - suppression protects leads, not employees. Do not route this
 * table through DncService.
 *
 * The in-app row is always written even if push/email delivery fails, so a
 * reminder is never lost to a delivery problem (BR-NOTIF-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // follow_up_due | follow_up_missed | lead_assigned |
            // campaign_completed | campaign_failed | payment_overdue |
            // recording_failed | approval_requested
            $table->string('type', 50);

            $table->string('title', 190);
            $table->text('body')->nullable();

            // in_app | push | email | sms
            $table->string('channel', 20)->default('in_app');

            $table->nullableMorphs('reference');
            $table->string('action_url', 255)->nullable();

            // pending | sent | failed
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 255)->nullable();

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Unread badge count.
            $table->index(['user_id', 'read_at']);
            // Dispatch job scans this.
            $table->index(['status', 'scheduled_for']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
