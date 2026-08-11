<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC tables (ROLE-01..07, SEC-AUTHZ-01/02).
 *
 * Roles and permissions are data, not code, so the role model can be adjusted
 * without a migration - which matters because the six roles are still a proposal
 * pending sign-off (T-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Teams exist so Manager scope ("own team") has something to resolve
        // against (SEC-AUTHZ-03).
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
            $table->string('name', 100);
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();
            $table->string('name', 50);
            $table->string('label', 100);
            $table->string('description', 255)->nullable();
            // all | team | own (DataScope)
            $table->string('data_scope', 10)->default('own');
            // System roles cannot be deleted or renamed by users; their
            // permission sets remain editable.
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('module', 30)->index();
            $table->string('description', 255)->nullable();
            // Use of this permission is written to audit_logs (SEC-AUD-02).
            $table->boolean('is_audited')->default(false);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Who granted this role - privilege changes are audited
            // (SEC-AUTHZ-05, SEC-AUD-02).
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('teams');
    }
};
