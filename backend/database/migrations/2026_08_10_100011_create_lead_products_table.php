<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product interest (BR-PROD-01, FR-STAT-05).
 *
 * This is what lets one lead be "News Portal = Interested, Epaper = Warm,
 * News Posting = Hot" at the same time. Changing one product's row must never
 * touch another's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // RESTRICT: a product with interest history cannot be hard-deleted.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->string('interest_status', 30)->default('new');
            $table->string('temperature', 10)->default('cold');
            $table->unsignedTinyInteger('score')->default(0);

            $table->decimal('quoted_value', 15, 2)->nullable();
            $table->char('currency', 3)->default('INR');

            $table->text('notes')->nullable();

            $table->timestamp('first_interest_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            $table->unique(['lead_id', 'product_id']);
            // Powers the product-wise interested-lead views (FR-INT-03).
            $table->index(['product_id', 'interest_status']);
            $table->index(['interest_status', 'temperature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_products');
    }
};
