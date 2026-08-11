<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where leads come from (FR-LEAD-04). Source quality is one of the highest-value
 * reports - it decides where marketing spend goes (GLOSSARY §2.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->string('code', 50);
            $table->string('name', 100);

            // facebook | instagram | website | referral | walk_in | import |
            // manual | other - groups sources for channel-level reporting
            $table->string('category', 30)->default('other');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sources');
    }
};
