<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every outbound and inbound message, across ALL channels (DATABASE_SCHEMA §2.7).
 *
 * One table rather than six. The lead timeline (FR-LEAD-09) and the DNC
 * frequency cap (BR-CAMP-04) both need "everything we sent this lead" - as six
 * per-channel tables that is a six-way union on every read.
 *
 * `idempotency_key` is UNIQUE. That constraint is what makes double-sending
 * structurally impossible when a queued job retries - not an application-level
 * check that a race can defeat (ARCHITECTURE §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            // Who sent it; null for system/campaign sends.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // email | whatsapp | sms | rcs | voice | ai_call
            $table->string('channel', 20);
            $table->string('direction', 10)->default('outbound');

            $table->string('provider', 50)->nullable();
            $table->string('provider_message_id', 190)->nullable();
            $table->string('idempotency_key', 190)->nullable();

            // The phone/email actually used, snapshotted at send time.
            $table->string('recipient', 190);
            $table->string('subject', 255)->nullable();
            $table->longText('body')->nullable();
            $table->json('media')->nullable();

            // queued | sent | delivered | read | replied | failed | bounced |
            // skipped
            $table->string('status', 20)->default('queued');
            $table->string('failure_reason', 255)->nullable();
            // Populated when status = skipped, so a skip is never silent
            // (BR-DNC-05).
            $table->string('skip_reason', 100)->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->decimal('cost', 10, 4)->nullable();
            $table->char('currency', 3)->default('INR');

            $table->timestamps();

            $table->unique('idempotency_key');
            $table->unique(['provider', 'provider_message_id']);

            $table->index(['lead_id', 'created_at']);
            // Campaign progress/reporting.
            $table->index(['campaign_id', 'status']);
            $table->index(['channel', 'status']);
            // Frequency-cap lookups (BR-CAMP-04).
            $table->index(['lead_id', 'channel', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
