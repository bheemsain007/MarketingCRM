<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per requested lead CSV export (FR-LEAD-12, SEC-PII-04).
 *
 * Mirrors `lead_imports`: the export runs as a queued job the client polls,
 * and the row is the audit trail of who exported what filters and when, kept
 * even after the file itself is purged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_exports', function (Blueprint $table) {
            $table->id();

            // Reserved for future multi-tenancy (ADR-C). Never nullable.
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            // Nullable + nullOnDelete, like `lead_imports.uploaded_by`: the row
            // is an audit record and must survive the requester's account being
            // removed later, not disappear with it.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // pending | processing | completed | failed
            $table->string('status', 20)->default('pending');

            // The same filter/search params the lead list screen sent, so the
            // job re-derives exactly the audience the operator was looking at.
            $table->json('filters')->nullable();

            $table->string('disk', 30)->nullable();
            // Null until the job finishes; also nulled again once purged.
            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('row_count')->nullable();

            $table->text('failure_reason')->nullable();

            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            // Download window for the generated file - a stale export must not
            // stay downloadable forever (SEC-PII-04/05).
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // "My recent exports", the only list this table serves.
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_exports');
    }
};
