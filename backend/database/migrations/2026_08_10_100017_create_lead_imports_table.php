<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded lead file (FR-LEAD-07, DATABASE_SCHEMA §7).
 *
 * The counters here are a denormalised summary of `lead_import_rows`, which is
 * the source of truth for the per-row report. They exist because a progress
 * poll must not COUNT() over 50,000 rows every few seconds; they are written
 * with atomic increments so concurrent row jobs cannot lose an update.
 *
 * `stored_path` is nullable on purpose: the uploaded file is bulk PII and is
 * purged after the retention window, while the import record and its per-row
 * outcomes are kept for audit (SEC-PII-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_imports', function (Blueprint $table) {
            $table->id();

            // Reserved for future multi-tenancy (ADR-C). Never nullable.
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            // Exactly what the user uploaded, for the "which file was this?"
            // question six weeks later.
            $table->string('original_filename', 255);
            $table->string('disk', 30);
            // Null once the file has been purged; the report survives it.
            $table->string('stored_path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            // pending | processing | completed | completed_with_errors | failed
            $table->string('status', 30)->default('pending');

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);

            // Resolved header -> lead field mapping, kept so a support query can
            // tell whether a bad import was a mapping problem or a data problem.
            $table->json('column_map');
            // Import-wide defaults: source, campaign, tags, auto-assign.
            $table->json('options')->nullable();

            // Queue batch driving the row jobs, for cancel/progress inspection.
            $table->string('batch_id', 36)->nullable()->index();

            // Why the import as a whole failed - not per-row failures, which
            // live in lead_import_rows.
            $table->text('failure_reason')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // "My recent imports", the only list this table serves.
            $table->index(['tenant_id', 'uploaded_by', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_imports');
    }
};
