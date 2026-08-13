<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\ErrorCode;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Sale;
use App\Services\Leads\LeadService;
use App\Services\Payments\Gateways\GatewayEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Payment links and gateway collection (Phase 23, FR-PAY-02, T-59).
 *
 * The online half of payments. It orchestrates and nothing more: the gateway
 * owns the wire format, `PaymentService` owns the BR-PAY-02 matrix and every
 * write to `payments.status`, and this class is what connects the two. It does
 * not decide what "collected" means and it never writes a status itself.
 *
 * Two rules shape everything here.
 *
 * 1. **Generating a link collects nothing.** The payment row is created
 *    Pending and stays Pending until the gateway says money arrived. A link is
 *    an invitation; treating it as a receipt would settle the sale balance
 *    (BR-PAY-04) and satisfy the conversion precondition (BR-PAY-05) on the
 *    strength of a URL having been generated.
 * 2. **A callback may apply a fact at most once.** Gateways retry, and they
 *    retry successful deliveries too. Three independent guards stand behind
 *    that: the unique `(provider, provider_event_id)` on the webhook log, the
 *    unique `(gateway, gateway_payment_id)` on `payments`, and the check here
 *    that the ledger is not already where the event wants to put it.
 */
class PaymentLinkService
{
    /** The event changed something. */
    public const OUTCOME_APPLIED = 'applied';

    /** Nothing here matches the link or payment the gateway named. */
    public const OUTCOME_UNKNOWN_LINK = 'unknown_link';

    /** Already applied - a redelivery, or a second event saying the same thing. */
    public const OUTCOME_DUPLICATE = 'duplicate';

    /** Recognised, but BR-PAY-02 forbids the move it asks for. */
    public const OUTCOME_REFUSED = 'refused';

    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentService $payments,
        private readonly LeadService $leads,
    ) {}

    /**
     * Issues a payment link for a sale.
     *
     * @param  array<string, mixed>  $data  amount, product_id, description, expires_at
     *
     * @throws ApiException (503) when no gateway is configured, (422) when there
     *                            is nothing left to collect
     */
    public function create(Sale $sale, array $data, ?int $actorId = null): PaymentLink
    {
        // Refuses here, before a payment row exists, naming the missing
        // credential (SEC-CFG-04). An unkeyed install must not leave a trail of
        // Pending phantoms behind failed attempts to collect.
        $gateway = $this->gateways->resolve();

        $amount = isset($data['amount'])
            ? round((float) $data['amount'], 2)
            // Defaulting to the outstanding balance is the common case - "send
            // them a link for what they still owe" - and it is derived
            // (BR-PAY-04), never a stored figure.
            : $this->payments->balanceFor($sale);

        if ($amount <= 0) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'There is nothing outstanding on this sale to collect.',
            );
        }

        /*
         * The gateway call sits INSIDE the transaction on purpose. The two
         * failure directions are not equally bad: a rolled-back payment row
         * costs a reference number, whereas committing a Pending instalment for
         * a link that was never created leaves a sale showing money it will
         * never receive, with nothing to reconcile it against. The remaining
         * window - link created, commit fails - surfaces at the webhook as
         * "no matching link" with the full payload in `provider_webhook_logs`,
         * so the money is recoverable by hand rather than silently applied to
         * the wrong record.
         */
        return DB::transaction(function () use ($sale, $data, $amount, $actorId, $gateway) {
            $payment = $this->payments->record($sale, [
                'amount' => $amount,
                'product_id' => $data['product_id'] ?? null,
                'currency' => $data['currency'] ?? $sale->currency,
                'method' => 'gateway',
                'notes' => $data['description'] ?? null,
                'source' => 'gateway',
            ], $actorId, awaitingGateway: true);

            // Named now, id later: which gateway is holding this collection is
            // knowable from the moment the link is issued, and the `pay_...`
            // only exists once somebody pays.
            $this->payments->attachGatewayPayment($payment, $gateway->name(), null);

            $issued = $gateway->createLink($payment, [
                'description' => $data['description'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            $link = PaymentLink::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'payment_id' => $payment->id,
                'gateway' => $gateway->name(),
                'gateway_link_id' => $issued->id,
                'short_url' => $issued->url,
                'amount' => $amount,
                'currency' => $payment->currency,
                'status' => PaymentLink::STATUS_CREATED,
                'expires_at' => $issued->expiresAt,
                'created_by' => $actorId,
            ]);

            if ($sale->lead) {
                // On the lead timeline (FR-LEAD-09) because "we sent them a
                // link" is a contact event a telecaller needs to see before
                // chasing the same customer by phone.
                $this->leads->recordActivity(
                    $sale->lead,
                    $actorId,
                    'payment_link_created',
                    sprintf('Payment link issued for %s %s', $payment->currency, $amount),
                    [
                        'payment_id' => $payment->id,
                        'payment_link_id' => $link->id,
                        'gateway' => $gateway->name(),
                    ],
                );
            }

            return $link->fresh(['payment']);
        });
    }

    /**
     * Applies one gateway callback to the ledger.
     *
     * Returns an OUTCOME_* constant rather than throwing, because the caller is
     * a webhook endpoint answering a provider. An exception here would become a
     * 4xx/5xx that the gateway retries for hours over a state we have already
     * decided is not applicable - so a refusal is recorded and acknowledged
     * instead of argued with.
     */
    public function applyEvent(GatewayEvent $event, PaymentGateway $gateway): string
    {
        $link = $this->resolveLink($event, $gateway->name());
        $payment = $link?->payment;

        if ($link === null || $payment === null) {
            return self::OUTCOME_UNKNOWN_LINK;
        }

        return DB::transaction(fn () => match ($event->type) {
            GatewayEvent::PAID => $this->applyPaid($link, $payment, $event, $gateway),
            GatewayEvent::FAILED => $this->applyFailed($link, $payment, $event),
            GatewayEvent::REFUNDED => $this->applyRefunded($link, $payment, $event),
            GatewayEvent::CLOSED => $this->applyClosed($link),
            default => self::OUTCOME_REFUSED,
        });
    }

    /**
     * Money arrived.
     *
     * The target status is recomputed against the sale's balance NOW, not when
     * the link was issued: a link for part of a sale settles it only if nothing
     * else was collected in the meantime.
     */
    private function applyPaid(
        PaymentLink $link,
        Payment $payment,
        GatewayEvent $event,
        PaymentGateway $gateway,
    ): string {
        if ($payment->status->countsAsCollected()) {
            // Already collected. The link row is brought in line in case this
            // is the redelivery that follows a partially-applied first attempt.
            $link->update(['status' => PaymentLink::STATUS_PAID, 'paid_at' => $link->paid_at ?? now()]);

            return self::OUTCOME_DUPLICATE;
        }

        try {
            $this->payments->attachGatewayPayment($payment, $gateway->name(), $event->gatewayPaymentId);
        } catch (UniqueConstraintViolationException) {
            /*
             * The unique `(gateway, gateway_payment_id)` pair already holds this
             * `pay_...` on another row - the same money reaching us twice by a
             * route the event-id guard did not catch. The database refusing it
             * is the point; recording it a second time would double the sale's
             * collected total.
             */
            return self::OUTCOME_DUPLICATE;
        }

        $target = $this->payments->settlementStatusFor($payment);

        if (! $payment->status->canTransitionTo($target)) {
            // A payment already refunded or failed cannot silently become paid
            // again. Recorded as refused so it shows up in the webhook log
            // rather than being applied against the matrix.
            return self::OUTCOME_REFUSED;
        }

        $this->payments->transition($payment, $target, null, null, 'gateway');

        $link->update(['status' => PaymentLink::STATUS_PAID, 'paid_at' => now()]);

        $this->recordOnTimeline($payment, 'payment_gateway_paid', sprintf(
            'Payment %s collected online (%s)',
            $payment->reference,
            $event->providerEvent ?? 'gateway',
        ), $event);

        return self::OUTCOME_APPLIED;
    }

    /**
     * An attempt against the link failed.
     *
     * A failure never overrides money that already arrived - a customer whose
     * second card was declined after the first one worked has still paid.
     */
    private function applyFailed(PaymentLink $link, Payment $payment, GatewayEvent $event): string
    {
        if ($payment->status->countsAsCollected()) {
            return self::OUTCOME_DUPLICATE;
        }

        if (! $payment->status->canTransitionTo(PaymentStatus::Failed)) {
            return self::OUTCOME_REFUSED;
        }

        $this->payments->transition(
            $payment,
            PaymentStatus::Failed,
            null,
            $event->reason ?? 'The payment attempt failed at the gateway.',
            'gateway',
        );

        $link->update(['status' => PaymentLink::STATUS_FAILED]);

        $this->recordOnTimeline($payment, 'payment_gateway_failed', sprintf(
            'Online payment %s failed: %s',
            $payment->reference,
            $event->reason ?? 'no reason given',
        ), $event);

        return self::OUTCOME_APPLIED;
    }

    /**
     * Money went back out at the gateway.
     *
     * BR-PAY-02 makes Refund terminal and reachable only from Partial or Paid,
     * and `PaymentService::transition()` requires a reason - so a refund
     * initiated in the gateway dashboard lands in the ledger with the same
     * audit trail as one made here (SEC-AUD-02).
     */
    private function applyRefunded(PaymentLink $link, Payment $payment, GatewayEvent $event): string
    {
        if ($payment->status === PaymentStatus::Refund) {
            return self::OUTCOME_DUPLICATE;
        }

        if (! $payment->status->canTransitionTo(PaymentStatus::Refund)) {
            return self::OUTCOME_REFUSED;
        }

        $this->payments->transition(
            $payment,
            PaymentStatus::Refund,
            null,
            $event->reason ?? 'Refunded at the payment gateway.',
            'gateway',
        );

        $this->recordOnTimeline($payment, 'payment_gateway_refunded', sprintf(
            'Payment %s refunded at the gateway',
            $payment->reference,
        ), $event);

        return self::OUTCOME_APPLIED;
    }

    /**
     * The link lapsed or was cancelled at the gateway.
     *
     * The PAYMENT is left alone. An expired invitation is not a failed payment
     * - nothing was attempted and nothing was lost - so the row stays Pending
     * and the BR-PAY-06 sweep treats it like any other unpaid instalment. The
     * operator's remedy is to issue a new link, which creates a new payment row
     * and leaves both attempts visible.
     */
    private function applyClosed(PaymentLink $link): string
    {
        if ($link->status !== PaymentLink::STATUS_CREATED) {
            return self::OUTCOME_DUPLICATE;
        }

        $link->update(['status' => PaymentLink::STATUS_EXPIRED]);

        return self::OUTCOME_APPLIED;
    }

    /**
     * Finds the link an event is about.
     *
     * Three routes because the gateway does not always name the link: a
     * link-scoped event carries `plink_...`, a refund names only the payment it
     * reverses, and a failed first attempt names a `pay_...` we have never seen
     * - which is why the link's notes carry our own payment id (see
     * RazorpayGateway::createLink()).
     */
    private function resolveLink(GatewayEvent $event, string $gateway): ?PaymentLink
    {
        if ($event->linkId !== null) {
            $link = PaymentLink::with('payment')
                ->where('gateway', $gateway)
                ->where('gateway_link_id', $event->linkId)
                ->first();

            if ($link !== null) {
                return $link;
            }
        }

        if ($event->gatewayPaymentId !== null) {
            $payment = Payment::where('gateway', $gateway)
                ->where('gateway_payment_id', $event->gatewayPaymentId)
                ->first();

            if ($payment !== null) {
                return PaymentLink::with('payment')->where('payment_id', $payment->id)->first();
            }
        }

        if ($event->internalPaymentId !== null) {
            return PaymentLink::with('payment')->where('payment_id', $event->internalPaymentId)->first();
        }

        return null;
    }

    /** The lead timeline entry for a gateway-side money event (FR-LEAD-09). */
    private function recordOnTimeline(Payment $payment, string $type, string $title, GatewayEvent $event): void
    {
        $lead = $payment->lead;

        if ($lead === null) {
            return;
        }

        // Actor is null: nobody in this CRM did this, the gateway did. Naming a
        // user would put a person's name on an action they did not take.
        $this->leads->recordActivity($lead, null, $type, $title, [
            'payment_id' => $payment->id,
            'gateway_event' => $event->providerEvent,
            'gateway_payment_id' => $event->gatewayPaymentId,
        ]);
    }
}
