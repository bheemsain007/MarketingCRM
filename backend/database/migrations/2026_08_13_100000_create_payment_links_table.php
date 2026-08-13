<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment links (Phase 23, FR-PAY-02, T-34/T-59). Planned in DATABASE_SCHEMA §3.
 *
 * **Why a table rather than two more columns on `payments`.**
 * A gateway issues TWO identifiers for one collection: a link id when the link
 * is created (`plink_...`) and a payment id when somebody actually pays it
 * (`pay_...`). `payments` has room for exactly one, under the unique
 * `(gateway, gateway_payment_id)` pair that stops a redelivered webhook
 * recording the same money twice. Putting the link id in that column would
 * either lose the payment id or make the uniqueness guarantee guard the wrong
 * thing; adding a third column would leave the pair half-populated for the
 * whole life of a link, which is when duplicate protection matters most.
 *
 * Separating them also keeps the two lifecycles honest. A link can expire, be
 * cancelled, or be re-issued without any of that being a fact about money - a
 * payment row has no state for "the invitation to pay lapsed", and BR-PAY-02
 * should not grow one. So the link's own status lives here and the ledger's
 * status stays in `payments`, moved only by `PaymentService`.
 *
 * `payment_id` is UNIQUE: one link per payment row. Re-issuing after an expiry
 * creates a fresh Pending payment with its own reference, so the audit trail
 * shows two attempts rather than one row quietly re-pointed at a new link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0)->index();

            $table->foreignId('payment_id')->unique()->constrained('payments')->cascadeOnDelete();

            $table->string('gateway', 30);
            $table->string('gateway_link_id', 190);
            // Razorpay short URLs are ~30 characters; the column is generous
            // because a hosted checkout URL from another gateway is not.
            $table->string('short_url', 500);

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');

            // created | paid | failed | expired | cancelled - the LINK's state,
            // never the ledger's (that is payments.status, BR-PAY-02).
            $table->string('status', 20)->default('created');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The redelivery guard for link-scoped events: one row per gateway
            // link, so a repeated `payment_link.paid` resolves to the same
            // record and the service can see it has already been applied.
            $table->unique(['gateway', 'gateway_link_id']);

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
