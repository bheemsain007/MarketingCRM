<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message templates per channel (FR-COMM-02).
 *
 * `provider_template_id` / `approval_status` exist because WhatsApp (and RCS)
 * require templates to be registered and approved by the provider before use -
 * a template that is approved locally but not at the provider will fail at send
 * time, so both states are tracked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->string('name', 150);
            $table->string('code', 100);
            // email | whatsapp | sms | rcs | voice | ai_call
            $table->string('channel', 20);

            $table->string('subject', 255)->nullable();   // email only
            $table->longText('body');
            // Placeholder names available to this template, e.g. ["name","company"]
            $table->json('variables')->nullable();
            // Media/audio payloads for RCS, WhatsApp, Voice.
            $table->json('media')->nullable();

            $table->string('provider', 50)->nullable();
            $table->string('provider_template_id', 190)->nullable();
            // draft | pending | approved | rejected
            $table->string('approval_status', 20)->default('draft');
            $table->string('rejection_reason', 255)->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['channel', 'is_active']);
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
