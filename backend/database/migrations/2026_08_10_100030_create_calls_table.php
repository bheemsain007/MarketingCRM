<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call records (DATABASE_SCHEMA §2.5, FR-CALL-02).
 *
 * `duration_seconds` is total call time; talk time in reports counts only rows
 * with status = connected (GLOSSARY §2.2). Keeping both means Average Call
 * Duration can be divided by CONNECTED calls rather than all attempts, which is
 * the difference between a fair metric and one that punishes an agent handed a
 * bad list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('direction', 10)->default('outbound');

            // 11 values per FR-CALL-01 / CallStatus enum.
            $table->string('status', 30);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);

            $table->text('notes')->nullable();

            // manual | auto_dialer | ai | inbound
            $table->string('dial_source', 20)->default('manual');
            $table->unsignedBigInteger('auto_dialer_session_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->unsignedBigInteger('follow_up_id')->nullable()->index();

            // Device/provider reference, used to reconcile recordings and AI calls.
            $table->string('external_call_id', 190)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'started_at']);
            // Telecaller performance reports (FR-RPT-01).
            $table->index(['user_id', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index('external_call_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
