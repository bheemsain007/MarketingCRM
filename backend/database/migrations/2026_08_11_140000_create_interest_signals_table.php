<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interest signals (Phase 20, FR-INT-01/02, BR-INT-01/03, BR-SCORE-01).
 *
 * The score is **derived from this table**, never accumulated in place on the
 * lead. Two reasons, both load-bearing:
 *
 *   1. BR-SCORE-01 requires the score to be explainable - a telecaller must see
 *      *why* a lead is Hot. "Why" is these rows with their points.
 *   2. The model is still a proposal (T-16). Re-weighting an accumulated
 *      integer means guessing at history; re-weighting a derived score means
 *      recomputing it.
 *
 * `points_awarded` is stored anyway, as the value at the time. That is not a
 * contradiction: recomputation uses the current weights, but the stored figure
 * shows what the lead was actually credited when the signal landed, which is
 * what somebody querying an old score needs to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_signals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Interest is often in a specific product, and product-wise
            // interested views (FR-INT-03) read this.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('type', 40);

            // BR-INT-01: eight sources, one engine. Recorded so the report can
            // show which channel actually produces interest.
            $table->string('channel', 20)->nullable();
            $table->string('source', 20)->default('manual');   // manual | system | ai | webhook

            // BR-INT-03: evidence. A polymorphic reference to the call,
            // message or follow-up that caused this.
            $table->nullableMorphs('evidence');
            $table->text('excerpt')->nullable();

            // BR-INT-04: AI-detected interest carries its confidence, and below
            // the threshold it is recorded but does not move status.
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('acted_on')->default(true);

            $table->decimal('points_awarded', 6, 2)->default(0);

            // Null for system-generated signals - crediting them to whoever
            // happened to trigger the job would corrupt attribution.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            // Score recomputation reads every signal for a lead.
            $table->index(['lead_id', 'occurred_at']);
            // "Which channel produces interest?" and the cumulative-cap lookup.
            $table->index(['lead_id', 'type']);
            $table->index(['product_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_signals');
    }
};
