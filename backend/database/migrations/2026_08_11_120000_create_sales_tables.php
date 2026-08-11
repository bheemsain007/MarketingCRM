<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales pipeline (Phase 22, FR-SALE-01..05, BR-SALE-01..04, BR-CUST-01/03).
 *
 * `leads --< opportunities --< sales` (DATABASE_SCHEMA §relationships), with
 * quotations hanging off the opportunity. Payments arrive in Phase 23 and hang
 * off `sales`.
 *
 * Money is `decimal(12,2)` throughout, never a float. A float cannot represent
 * 0.1 exactly, and a discount calculated in floats disagrees with the invoice
 * by a paisa often enough to be noticed - by an accountant, in front of a
 * customer.
 *
 * **One deviation from the planned schema**: DATABASE_SCHEMA §267 lists a
 * separate `lost_sales` table. A lost opportunity is the same row in a
 * different state, not a different entity, and a second table would need
 * keeping in step with the first for no reporting benefit - `WHERE status =
 * 'lost' GROUP BY lost_reason` answers FR-SALE-05 off this table. Recorded as
 * a deliberate deviation, not an oversight (T-56).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * An opportunity is a deal in progress against a lead. It exists BEFORE
         * a customer does - BR-CUST-01 keeps the lead and the customer as
         * separate records, and the customer is only created when a sale is
         * actually made.
         */
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Set on repeat business under an existing customer (BR-CUST-03).
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('title', 190);

            // open | won | lost
            $table->string('status', 20)->default('open');

            // The headline number. Derived from the products but stored,
            // because a quotation may discount it and reporting needs the
            // figure as it stood.
            $table->decimal('value', 12, 2)->default(0);
            $table->char('currency', 3)->default('INR');

            $table->date('expected_close_on')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // FR-SALE-05 / BR-SALE-04: a lost sale is reportable BY REASON, so
            // the reason is a column and not free text buried in a note.
            $table->string('lost_reason', 100)->nullable();
            $table->text('lost_notes')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lead_id', 'status']);
            $table->index(['status', 'expected_close_on']);
            // "Why are we losing deals?" - the whole point of FR-SALE-05.
            $table->index(['status', 'lost_reason']);
            $table->index('customer_id');
        });

        // FR-SALE-02: an opportunity references one or more products with a
        // per-product value.
        Schema::create('opportunity_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->unsignedInteger('quantity')->default(1);
            // Snapshotted at the time it was added. A product's list price
            // changing later must not silently rewrite an open deal.
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->timestamps();

            // One line per product per opportunity; quantity carries the rest.
            $table->unique(['opportunity_id', 'product_id']);
        });

        /*
         * FR-SALE-03/04. A quotation is issued at a point in time and must not
         * change afterwards, so its items are copied rather than referenced.
         */
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            $table->string('number', 40);

            // draft | pending_approval | approved | rejected | issued
            $table->string('status', 20)->default('draft');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->char('currency', 3)->default('INR');

            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();

            // BR-SALE-03: approval above the threshold is audited, so who
            // approved it and when are columns, not log lines.
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // A quotation number a customer can quote back at us must be
            // unique per tenant.
            $table->unique(['tenant_id', 'number']);
            $table->index(['opportunity_id', 'status']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            // Nullable: a product archived years later must not delete a line
            // from an issued quotation.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            // Copied, not joined - the description as it was quoted.
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->timestamps();

            $table->index('quotation_id');
        });

        /*
         * The sale itself. Creating one is what produces a Customer
         * (BR-CUST-01) and what allows the lead to reach `Converted`.
         */
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            // Kept alongside customer_id: the lead survives conversion
            // (BR-CUST-01) and attribution reports read from it (GLOSSARY §2.6).
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();

            $table->string('reference', 40);

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');

            // Who gets the credit. Stored rather than derived, because the
            // lead may be reassigned afterwards and the attribution must not
            // move with it (T-24).
            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sold_at');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['customer_id', 'sold_at']);
            $table->index(['sold_by', 'sold_at']);
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        // Children first - the FKs are RESTRICT/CASCADE and the order matters.
        Schema::dropIfExists('sales');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('opportunity_products');
        Schema::dropIfExists('opportunities');
    }
};
