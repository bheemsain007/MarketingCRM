<?php

namespace App\Services\Payments\Gateways;

/**
 * A gateway callback, reduced to what the ledger can act on (FR-PAY-02).
 *
 * Deliberately a tiny vocabulary. Every gateway has its own event zoo -
 * Razorpay alone emits a dozen `payment_link.*` and `payment.*` events - and
 * translating them here means `PaymentLinkService` applies BR-PAY-02 to four
 * cases rather than to whatever the provider invented last quarter. Anything
 * unrecognised parses to null and is acknowledged-but-not-applied, because a
 * guessed money event is worse than an ignored one.
 */
class GatewayEvent
{
    /** Money arrived for the link. */
    public const PAID = 'paid';

    /** An attempt against the link failed - the link itself may still be payable. */
    public const FAILED = 'failed';

    /** Money went back out. */
    public const REFUNDED = 'refunded';

    /** The link is no longer payable (expired or cancelled at the gateway). */
    public const CLOSED = 'closed';

    public function __construct(
        /** One of the four constants above. */
        public readonly string $type,
        /**
         * The gateway's LINK id (`plink_...`) - what maps the event to our row.
         *
         * Nullable because a refund is an event about a PAYMENT, not about the
         * link that produced it: Razorpay's `refund.*` payload carries only
         * `payment_id`. The service resolves by whichever id arrived.
         */
        public readonly ?string $linkId,
        /** The gateway's PAYMENT id (`pay_...`), present once somebody paid. */
        public readonly ?string $gatewayPaymentId = null,
        /** Major units (rupees), converted from whatever minor unit the provider uses. */
        public readonly ?float $amount = null,
        public readonly ?string $reason = null,
        /** The provider's own event name, kept so the log says what actually arrived. */
        public readonly ?string $providerEvent = null,
        /**
         * OUR payment id, when the gateway echoed back the reference we planted
         * at link creation. It is the last resort for events that name neither
         * the link nor a payment id we have seen before - a first attempt that
         * fails, for instance, produces a `pay_...` we have never stored.
         */
        public readonly ?int $internalPaymentId = null,
    ) {}
}
