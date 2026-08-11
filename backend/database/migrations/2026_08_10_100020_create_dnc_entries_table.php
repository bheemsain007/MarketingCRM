<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suppression source of truth (DATABASE_SCHEMA §2.4, BR-DNC-01..07).
 *
 * CRITICAL: this table - not `leads.is_suppressed` - decides whether a lead may
 * be contacted. DncService is the only code allowed to make that decision, and
 * every outbound path (campaign job, individual send, human call, auto dialer)
 * must call it (ADR-E).
 *
 * Suppression is per REASON and per CHANNEL, not a single global flag
 * (BR-DNC-02). A wrong phone number must not block a valid email address.
 * `channel = null` means "all channels".
 *
 * Removal deactivates (`active = false`) rather than deleting, so the audit
 * trail of who un-suppressed a lead, and why, survives (BR-DNC-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dnc_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            // Nullable: a number/email can be suppressed before any lead exists
            // for it (e.g. an inbound STOP from an unknown number).
            $table->foreignId('lead_id')->nullable()->constrained('leads')->cascadeOnDelete();
            $table->string('phone_e164', 20)->nullable();
            $table->string('email', 190)->nullable();

            // do_not_contact | not_interested | opted_out | wrong_number |
            // invalid_number | bounced_email
            $table->string('reason', 30);

            // NULL = every channel. Set = that channel only (e.g. an SMS opt-out
            // leaves email contactable).
            $table->string('channel', 20)->nullable();

            // manual | call_outcome | webhook | import | inbound_keyword | system
            $table->string('source', 30)->default('manual');

            $table->text('note')->nullable();

            $table->boolean('active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 255)->nullable();

            $table->timestamps();

            // The hot path: DncService::canContact() resolves against this.
            $table->index(['lead_id', 'channel', 'active']);
            $table->index(['phone_e164', 'active']);
            $table->index(['email', 'active']);
            $table->index(['reason', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dnc_entries');
    }
};
