<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns (DATABASE_SCHEMA §2.8, FR-CAMP-01..05).
 *
 * `batch_id` links to Laravel's job batch so pause/resume/stop and progress
 * counts map onto batch operations instead of bespoke state tracking.
 *
 * The counters are denormalised deliberately: campaign reports must not COUNT()
 * across millions of `campaign_recipients` rows on every dashboard load.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->string('name', 190);
            $table->text('description')->nullable();

            // email | whatsapp | sms | rcs | voice | ai_call
            $table->string('channel', 20);
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // draft | scheduled | running | paused | stopped | completed | failed
            // `stopped` is terminal - a stopped campaign is cloned, never
            // resumed (BR-CAMP-05).
            $table->string('status', 20)->default('draft');

            // Serialised audience filter set, re-evaluated at dispatch time.
            $table->json('audience_filters')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('batch_id', 100)->nullable();

            $table->unsignedInteger('total_targeted')->default(0);
            $table->unsignedInteger('total_queued')->default(0);
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_delivered')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            // A high skip count signals a data-quality or over-suppression
            // problem and is surfaced in reports (GLOSSARY §2.7).
            $table->unsignedInteger('total_skipped')->default(0);

            $table->decimal('cost', 12, 4)->default(0);
            $table->char('currency', 3)->default('INR');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_at']);
            $table->index(['channel', 'status']);
            $table->index('batch_id');
        });

        // leads.campaign_id was reserved before this table existed.
        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        Schema::dropIfExists('campaigns');
    }
};
