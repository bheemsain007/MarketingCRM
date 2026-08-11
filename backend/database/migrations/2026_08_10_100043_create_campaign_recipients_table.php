<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per lead targeted by a campaign (DATABASE_SCHEMA §2.8).
 *
 * Ineligible leads get a row with status = skipped and a skip_reason. They are
 * never simply omitted: "nothing happened" is not an acceptable outcome, and a
 * campaign's skip breakdown is how a data-quality or over-suppression problem
 * becomes visible (BR-DNC-05, FR-CAMP-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            // pending | queued | sent | delivered | failed | skipped
            $table->string('status', 20)->default('pending');

            // dnc_suppressed | no_contact_detail | archived | frequency_capped |
            // outside_calling_hours | duplicate
            $table->string('skip_reason', 50)->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'lead_id']);
            $table->index(['campaign_id', 'status']);
            $table->index(['campaign_id', 'skip_reason']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
