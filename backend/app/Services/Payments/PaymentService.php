<?php

namespace App\Services\Payments;

use App\Enums\ErrorCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\PaymentStatusHistory;
use App\Models\Sale;
use App\Services\Leads\LeadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payments (FR-PAY-01..05, BR-PAY-01..06).
 *
 * The only code permitted to write `payments.status`, exactly as
 * `LeadStatusService` owns lead status. The BR-PAY-02 matrix lives in the
 * `PaymentStatus` enum so there is one definition of what is legal; this is
 * what enforces it and writes the append-only history.
 */
class PaymentService
{
    public function __construct(private readonly LeadService $leads) {}

    /**
     * Records a payment against a sale.
     *
     * BR-PAY-03: lead, customer and sale are taken FROM the sale rather than
     * from the caller. A request that could name its own customer is a request
     * that can attach a payment to the wrong account, and the database
     * constraint would happily accept it.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $awaitingGateway  the payment exists only to be collected
     *                                 online and no money has arrived yet - see
     *                                 openingStatus() for why this is a code
     *                                 argument and not a request field
     */
    public function record(Sale $sale, array $data, ?int $actorId = null, bool $awaitingGateway = false): Payment
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw new ApiException(ErrorCode::ValidationFailed, 'A payment must be above zero.');
        }

        $productId = $this->resolveProductId($sale, $data);
        $status = $this->openingStatus($sale, $amount, $data, $awaitingGateway);

        return DB::transaction(function () use ($sale, $data, $amount, $productId, $status, $actorId) {
            $payment = Payment::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'lead_id' => $sale->lead_id,
                'product_id' => $productId,
                'reference' => $this->nextReference(),
                'amount' => $amount,
                'currency' => $data['currency'] ?? $sale->currency,
                'method' => $data['method'] ?? 'other',
                'due_on' => $data['due_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            // `status` is guarded against mass assignment - this service is the
            // exception, not a loophole.
            $payment->forceFill([
                'status' => $status,
                'paid_at' => $status->countsAsCollected() ? now() : null,
            ])->save();

            $this->writeHistory($payment, null, $status, $actorId, $data['source'] ?? 'manual');

            if ($sale->lead) {
                $this->leads->recordActivity(
                    $sale->lead,
                    $actorId,
                    'payment_recorded',
                    sprintf('Payment %s recorded: %s %s', $payment->reference, $payment->currency, $amount),
                    [
                        'payment_id' => $payment->id,
                        'sale_id' => $sale->id,
                        'status' => $status->value,
                    ],
                );
            }

            return $payment->fresh();
        });
    }

    /**
     * Moves a payment to a new status through the BR-PAY-02 matrix.
     *
     * @throws ApiException on an illegal transition
     */
    public function transition(
        Payment $payment,
        PaymentStatus $target,
        ?int $actorId = null,
        ?string $reason = null,
        string $source = 'manual',
    ): Payment {
        $current = $payment->status;

        if (! $current->canTransitionTo($target)) {
            throw new ApiException(
                ErrorCode::PaymentInvalidTransition,
                sprintf('A payment cannot move from %s to %s.', $current->label(), $target->label()),
                context: [
                    'from' => $current->value,
                    'to' => $target->value,
                    'allowed' => array_map(
                        fn (PaymentStatus $s) => $s->value,
                        $current->allowedTransitions(),
                    ),
                ],
            );
        }

        // A refund is money going back out. Requiring a reason is the
        // difference between an auditable decision and an unexplained
        // reversal (SEC-AUD-02 lists payment status changes).
        if ($target === PaymentStatus::Refund && ($reason === null || trim($reason) === '')) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'A refund requires a reason.',
                errors: [[
                    'field' => 'reason',
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => 'Explain why this payment is being refunded.',
                ]],
            );
        }

        return DB::transaction(function () use ($payment, $current, $target, $actorId, $reason, $source) {
            $payment->forceFill([
                'status' => $target,
                'updated_by' => $actorId,
                'paid_at' => $target->countsAsCollected() ? ($payment->paid_at ?? now()) : $payment->paid_at,
                'failure_reason' => $target === PaymentStatus::Failed ? $reason : null,
            ])->save();

            $this->writeHistory($payment, $current, $target, $actorId, $source, $reason);

            return $payment->fresh();
        });
    }

    /**
     * What a payment that has just been collected should become: `Paid` if it
     * settles what is still outstanding on the sale, `Partial` otherwise.
     *
     * The same derivation `openingStatus()` uses, exposed because a
     * gateway-collected payment learns it was paid LATER, by webhook, and the
     * answer has to be recomputed against the balance at that moment rather
     * than the one when the link was issued. Two instalment links generated on
     * the same afternoon would otherwise both claim to settle the sale.
     */
    public function settlementStatusFor(Payment $payment): PaymentStatus
    {
        $sale = $payment->sale;

        if ($sale === null) {
            return PaymentStatus::Paid;
        }

        // The payment is still Pending here, so it is not in `collected()` and
        // does not net itself out of the outstanding figure.
        return (float) $payment->amount >= $this->balanceFor($sale)
            ? PaymentStatus::Paid
            : PaymentStatus::Partial;
    }

    /**
     * Stamps the gateway's own payment id onto the record.
     *
     * Kept here rather than in the link service because `gateway_payment_id` is
     * outside `$fillable` and the unique `(gateway, gateway_payment_id)` pair is
     * a ledger guarantee - this service is the exception to mass-assignment
     * protection on `payments`, and having a second one would make the rule
     * decorative.
     */
    public function attachGatewayPayment(Payment $payment, string $gateway, ?string $gatewayPaymentId): Payment
    {
        $payment->forceFill([
            'gateway' => $gateway,
            'gateway_payment_id' => $gatewayPaymentId,
        ])->save();

        return $payment;
    }

    /**
     * BR-PAY-04: balance = sale value - sum of non-failed, non-refunded payments.
     *
     * Computed, never stored. A stored balance is a second source of truth that
     * drifts the first time two instalments land in the same second, and it is
     * the number an argument with a customer turns on.
     */
    public function balanceFor(Sale $sale): float
    {
        return round((float) $sale->amount - $this->collectedFor($sale), 2);
    }

    /** What has actually been received against this sale. */
    public function collectedFor(Sale $sale): float
    {
        return round((float) Payment::query()
            ->where('sale_id', $sale->id)
            ->collected()
            ->sum('amount'), 2);
    }

    /**
     * BR-PAY-05: a lead converts only once a linked sale has a payment in
     * `Partial` or `Paid`.
     *
     * This is what `LeadStatusService` asks, so the money rule lives here with
     * the rest of the money rules rather than being reimplemented in a guard.
     */
    public function saleHasQualifyingPayment(int $leadId): bool
    {
        return Payment::query()
            ->where('lead_id', $leadId)
            ->collected()
            ->exists();
    }

    /**
     * The opening status of a new payment.
     *
     * Derived from what the sale is still owed, not supplied by the caller: a
     * payment that settles the remaining balance is `Paid`, one that covers
     * part of it is `Partial`, and one that is merely scheduled is `Pending`.
     * Letting a caller declare "paid" on a part-payment is how a half-collected
     * sale reports as settled.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $awaitingGateway  a payment generated to back an online link
     */
    private function openingStatus(
        Sale $sale,
        float $amount,
        array $data,
        bool $awaitingGateway = false,
    ): PaymentStatus {
        /*
         * A payment link is an INVITATION to pay. Without this clause the
         * derivation below would look at an amount that settles the sale and
         * open the row as `Paid` - so merely generating a link would settle the
         * balance and satisfy BR-PAY-05, letting the lead be converted before
         * anybody had paid anything. The link path therefore forces Pending and
         * waits for the gateway's callback to move it, which is the only event
         * that actually means money arrived.
         *
         * It is a code argument rather than a `$data` key on purpose: it must
         * not be reachable from a request body. The direction is safe (it can
         * only ever UNDERSTATE collection), but a caller-settable status field
         * is what BR-PAY-02 exists to prevent, and one exception is how that
         * becomes two.
         */
        if ($awaitingGateway) {
            return PaymentStatus::Pending;
        }

        // A future-dated instalment is a commitment, not a receipt - it becomes
        // Partial or Paid when the money actually arrives, and Overdue if it
        // does not (BR-PAY-06).
        $dueOn = $data['due_on'] ?? null;

        if ($dueOn !== null && Carbon::parse($dueOn)->isFuture()) {
            return PaymentStatus::Pending;
        }

        $outstanding = $this->balanceFor($sale);

        return $amount >= $outstanding ? PaymentStatus::Paid : PaymentStatus::Partial;
    }

    /**
     * BR-PAY-03's strictest clause: a payment names a product.
     *
     * A single-product sale needs no help; a multi-product sale must say which
     * product an instalment is against, because revenue-by-product (FR-PAY-04)
     * is exact rather than apportioned. See T-58 for the ergonomic cost.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveProductId(Sale $sale, array $data): int
    {
        $lines = $sale->opportunity?->products()->pluck('product_id') ?? collect();

        if (isset($data['product_id'])) {
            $productId = (int) $data['product_id'];

            // The product must belong to what was sold, or revenue by product
            // reports against something the customer never bought.
            if ($lines->isNotEmpty() && ! $lines->contains($productId)) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'That product is not part of this sale.',
                );
            }

            return $productId;
        }

        if ($lines->count() === 1) {
            return (int) $lines->first();
        }

        throw new ApiException(
            ErrorCode::ValidationFailed,
            $lines->isEmpty()
                ? 'This sale has no products, so a payment cannot be attributed to one.'
                : 'This sale covers several products - say which one this payment is against.',
            errors: [[
                'field' => 'product_id',
                'code' => ErrorCode::ValidationFailed->value,
                'message' => 'Choose the product this payment is against.',
            ]],
        );
    }

    private function writeHistory(
        Payment $payment,
        ?PaymentStatus $from,
        PaymentStatus $to,
        ?int $actorId,
        string $source,
        ?string $reason = null,
    ): void {
        PaymentStatusHistory::create([
            'payment_id' => $payment->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'amount_at_change' => $payment->amount,
            'changed_by' => $actorId,
            'source' => $source,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function nextReference(): string
    {
        $prefix = 'P-'.now()->format('Y').'-';

        $last = Payment::withTrashed()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $next = $last !== null ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
