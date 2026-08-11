<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form lead labels (FR-LEAD-03). Also used by the Interest Engine to apply
 * automatic labels (BR-INT-02 step 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->string('name', 60);
            $table->string('slug', 60);
            $table->string('color', 7)->default('#6B7280');

            // System tags are applied by the Interest Engine and cannot be
            // deleted by users - only user-created tags are removable.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
