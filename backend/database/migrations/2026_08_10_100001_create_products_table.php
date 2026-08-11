<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products the CRM sells (PROJECT_REQUIREMENTS §1.1, P1-P7).
 *
 * Seeded with the seven real products; kept as a table rather than an enum so
 * the business can add products without a deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->string('code', 50);
            $table->string('name', 150);
            $table->text('description')->nullable();

            // saas | project | service - drives which sales flow applies
            $table->string('delivery_type', 20)->default('project');

            $table->decimal('base_price', 15, 2)->default(0);
            $table->char('currency', 3)->default('INR');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
