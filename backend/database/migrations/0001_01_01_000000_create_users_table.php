<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core user table.
 *
 * Roles/permissions land in Phase 4 - this migration only adds the columns the
 * rest of the schema depends on (scoping, timezone for calling hours, activity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Reserved for future multi-tenant SaaS - ADR-C. No FK, no scoping
            // logic until Phase 36; present now so no table needs rebuilding.
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->string('name', 150);
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            $table->string('phone_e164', 20)->nullable();

            // Drives calling-hours enforcement in the lead's/agent's local time
            // (BR-CALL-04) and report bucketing.
            $table->string('timezone', 64)->default('Asia/Kolkata');

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            // Set in Phase 4 when teams/roles exist; Managers are scoped by team
            // (SEC-AUTHZ-03).
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
