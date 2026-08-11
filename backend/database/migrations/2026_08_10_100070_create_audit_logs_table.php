<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance-grade audit trail (SEC-AUD-01..04).
 *
 * Append-only and immutable: no updated_at, no soft delete, and the application
 * must never UPDATE or DELETE rows here. Distinct from `lead_activities`, which
 * is the user-facing timeline - different audience, different retention.
 *
 * Minimum audited events (SEC-AUD-02): authentication success/failure/lockout,
 * role & permission changes, lead status changes, DNC add/remove, payment status
 * changes, recording access, data export, provider credential changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            // Null for unauthenticated events (failed login, webhook).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // login | login_failed | lockout | role_changed | status_changed |
            // dnc_added | dnc_removed | payment_status_changed |
            // recording_accessed | data_exported | credentials_changed
            $table->string('action', 60);

            $table->nullableMorphs('auditable');

            // Before/after state. PII and secrets are redacted before writing
            // (SEC-PII-03, SEC-CFG-05).
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('description', 255)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
