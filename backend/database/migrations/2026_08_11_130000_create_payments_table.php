<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments (Phase 23, FR-PAY-01..05, BR-PAY-01..06).
 *
 * **No orphan payments** (BR-PAY-03). Lead, customer, product and sale are all
 * `NOT NULL` foreign keys, enforced at the database as well as in the service,
 * because a payment that cannot be traced to what was sold is money the
 * business cannot account for - and the service layer is one refactor away
 * from being bypassed by a queue job or an import.
 *
 * `product_id` being required is the strictest part of that rule and is taken
 * literally: it is what makes revenue-by-product (FR-PAY-04) exact rather than
 * apportioned. For a single-product sale the service fills it in; a
 * multi-product sale must say which product an instalment is against, which is
 * more work at the till and correct in the ledger (T-58).
 *
 * There is no `balance` column anywhere. Balance is sale value minus the sum of
 * non-failed, non-refunded payments (BR-PAY-04) and is computed on read - a
 * stored balance is a second source of truth that drifts the first time two
 * instalments land in the same second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            // BR-PAY-03: all four, all NOT NULL, all constrained.
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->string('reference', 40);

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');

            // pending | partial | paid | failed | overdue | refund
            $table->string('status', 20)->default('pending');

            // cash | bank_transfer | upi | card | cheque | gateway | other
            $table->string('method', 20)->default('other');

            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Populated only for gateway-collected payments (Phase 23's online
            // half, which needs the gateway named - T-34).
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_payment_id', 190)->nullable();
            $table->string('failure_reason', 255)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            // A gateway must not be able to double-record one payment.
            $table->unique(['gateway', 'gateway_payment_id']);

            $table->index(['sale_id', 'status']);
            $table->index(['customer_id', 'status']);
            // The overdue sweep (BR-PAY-06) and the collections report.
            $table->index(['status', 'due_on']);
            // Revenue by product and by period (FR-PAY-04).
            $table->index(['product_id', 'paid_at']);
        });

        /*
         * Append-only history, exactly as lead status has (BR-STAT-03's
         * reasoning applies here too): a disputed refund six months later is
         * unanswerable if the only record is the current value of a column.
         */
        Schema::create('payment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->decimal('amount_at_change', 12, 2)->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('manual');   // manual | gateway | system
            $table->string('reason', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_status_history');
        Schema::dropIfExists('payments');
    }
};
