<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The duplicate review queue (BR-DUP-03, T-64).
 *
 * BR-DUP-03 says a matching email with a different phone is "flagged for
 * review, not auto-merged" - and until this table existed there was nothing to
 * flag it INTO, which is why the rule went unbuilt rather than half-built.
 *
 * Why review rather than merge: phone is identity (BR-DUP-01) and email is not.
 * Two people at one company legitimately share `info@`, and colleagues commonly
 * give a shared address. Auto-merging on that evidence fuses distinct humans -
 * and a merge cannot be undone by any amount of care afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            // Ordered by the service so (a, b) and (b, a) cannot both exist:
            // the same pair surfacing twice is two decisions to make about one
            // question.
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('duplicate_lead_id')->constrained('leads')->cascadeOnDelete();

            // What matched. Only `email` today; the column exists so a future
            // signal does not need a second table.
            $table->string('match_type', 20)->default('email');
            $table->string('match_value', 190)->nullable();

            $table->string('status', 20)->default('pending');

            // Dismissals are kept, not deleted. "These two are different
            // people" is an answer, and re-asking it every time the detector
            // runs is how a review queue becomes noise nobody reads.
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique(['lead_id', 'duplicate_lead_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_duplicate_candidates');
    }
};
