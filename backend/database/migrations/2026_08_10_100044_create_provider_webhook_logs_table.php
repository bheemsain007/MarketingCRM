<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw inbound webhook payloads, append-only (DATABASE_SCHEMA §2.9).
 *
 * The UNIQUE (provider, provider_event_id) index IS the replay protection
 * (FR-META-03, SEC-WH-03): a redelivered Meta/WhatsApp event hits a duplicate
 * key and is skipped, so a retried delivery cannot create a second lead or
 * double-count a payment.
 *
 * The payload is stored BEFORE processing so a bug in our handler can be fixed
 * and the event replayed, rather than the data being lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_webhook_logs', function (Blueprint $table) {
            $table->id();

            // meta | whatsapp | mailercloud | bhashsms | rcs | voice | vaaad |
            // payment_gateway
            $table->string('provider', 50);
            $table->string('event_type', 100)->nullable();
            $table->string('provider_event_id', 190)->nullable();

            // False = rejected before processing (SEC-WH-01).
            $table->boolean('signature_valid')->default(false);

            $table->json('payload');
            $table->json('headers')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->unsignedTinyInteger('processing_attempts')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['provider', 'created_at']);
            // Finds unprocessed/stuck events.
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_logs');
    }
};
