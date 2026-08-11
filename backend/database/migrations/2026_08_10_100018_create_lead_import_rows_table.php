<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-row import report (FR-LEAD-07: "imported / duplicate / invalid").
 *
 * UNIQUE(lead_import_id, row_number) is the idempotency key. Row jobs are
 * retryable, and a job that failed *after* creating its lead but *before*
 * acknowledging would otherwise write a second result - or worse, a second
 * lead - on retry. With the constraint in place a retry updates the existing
 * outcome instead of duplicating it.
 *
 * `data` holds the raw source row so the operator can see what was rejected
 * without re-opening the file. It is PII and is cleared with the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_import_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_import_id')->constrained('lead_imports')->cascadeOnDelete();

            // 1-based, counting data rows only - the header is not row 1, so
            // the number matches what a spreadsheet shows minus the header.
            $table->unsignedInteger('row_number');

            // imported | duplicate | invalid | failed
            $table->string('status', 20);

            // The lead this row produced, or - for a duplicate - the existing
            // lead it collided with, so the operator can go straight to it.
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('message', 500)->nullable();
            $table->json('data')->nullable();

            $table->timestamps();

            $table->unique(['lead_import_id', 'row_number']);
            // Filtering the report to "show me only the failures".
            $table->index(['lead_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_rows');
    }
};
