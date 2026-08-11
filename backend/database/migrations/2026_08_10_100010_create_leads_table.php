<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central table (DATABASE_SCHEMA §2.1).
 *
 * Two decisions worth knowing before changing anything here:
 *
 * 1. `phone_e164` carries a UNIQUE constraint with tenant_id. Duplicate
 *    detection (BR-DUP-01/02) is therefore enforced by the database, not by
 *    application checks that a race condition can slip past.
 *
 * 2. `is_suppressed` is a DENORMALISED CACHE for fast list filtering only.
 *    `dnc_entries` is the source of truth and DncService always reads the real
 *    table. Never gate an outbound send on this column alone (BR-DNC-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            // Reserved for future multi-tenancy (ADR-C).
            //
            // DEFAULT 0, NOT NULL - deliberately not nullable. In SQL, NULL
            // never equals NULL, so a UNIQUE(tenant_id, phone_e164) index over a
            // nullable tenant_id silently permits unlimited duplicates while
            // appearing to prevent them. 0 means "default tenant"; real tenant
            // IDs start at 1 in Phase 36.
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->string('name', 150);
            $table->string('company', 150)->nullable();

            // Normalised to E.164 before storage - this is the identity key.
            $table->string('phone_e164', 20);
            // Exactly what the user/import typed, kept for support queries.
            $table->string('phone_raw', 30)->nullable();
            $table->string('alt_phone_e164', 20)->nullable();

            $table->string('email', 190)->nullable();

            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('India');
            // Calling-hours enforcement uses the LEAD's timezone (BR-CALL-04).
            $table->string('timezone', 64)->nullable();

            // Enums are varchar + an application enum class, not MySQL ENUM -
            // the status sets are expected to be tuned and ALTERing a large
            // table in production is expensive (DATABASE_SCHEMA §1).
            $table->string('status', 30)->default('new');
            $table->string('temperature', 10)->default('cold');
            $table->unsignedTinyInteger('score')->default(0);
            $table->unsignedTinyInteger('priority')->default(0);

            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            // Originating campaign (FR-LEAD-04). FK added in the campaigns
            // migration, which runs later - column reserved here.
            $table->unsignedBigInteger('campaign_id')->nullable()->index();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Any outbound contact.
            $table->timestamp('last_contacted_at')->nullable();
            // Any INBOUND signal - drives temperature recency (BR-TEMP-02).
            $table->timestamp('last_engagement_at')->nullable();

            $table->boolean('is_suppressed')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Archive = soft delete (FR-LEAD-01). Archived leads are excluded
            // from lists AND from all outbound targeting.
            $table->softDeletes();

            // Duplicate detection enforced at the DB level (BR-DUP-01).
            $table->unique(['tenant_id', 'phone_e164']);

            $table->index(['status', 'temperature']);
            $table->index(['assigned_to', 'status']);
            $table->index('last_contacted_at');
            $table->index('last_engagement_at');
            $table->index('is_suppressed');
            $table->index('email');
            // Paginated, filtered lead lists - the most frequent query.
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
