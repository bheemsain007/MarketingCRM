<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers (DATABASE_SCHEMA §2.12, BR-CUST-01..04).
 *
 * A Customer is created at the FIRST SALE, never before. The Lead is NOT
 * converted in place - it survives with status = converted and links here via
 * `origin_lead_id`. That keeps the lead's calls, campaigns and source
 * attribution intact for reporting, and allows one customer to have arrived
 * from more than one lead (see the customer_leads pivot).
 *
 * Billing identity lives here, not on the lead: changing a lead's phone number
 * must not silently rewrite invoicing data (BR-CUST-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('origin_lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('name', 150);
            $table->string('company', 150)->nullable();
            $table->string('phone_e164', 20);
            $table->string('email', 190)->nullable();

            $table->string('billing_name', 190)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->default('India');
            $table->string('postal_code', 20)->nullable();
            // GSTIN or equivalent.
            $table->string('tax_id', 30)->nullable();

            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Deduplication signals (BR-CUST-04).
            $table->index('phone_e164');
            $table->index('email');
            $table->index('tax_id');
            $table->index('origin_lead_id');
        });

        // One customer may legitimately have arrived from several leads.
        Schema::create('customer_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'lead_id']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_leads');
        Schema::dropIfExists('customers');
    }
};
