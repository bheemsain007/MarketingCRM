<?php

namespace App\Contracts;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Payments\Gateways\GatewayEvent;
use App\Services\Payments\Gateways\GatewayLink;

/**
 * One online payment gateway (FR-PAY-02, T-34).
 *
 * Shaped like `MessageDriver` on purpose - one class owns one provider's wire
 * format, so swapping Razorpay for PayU is a class, not a search through the
 * payments module. The resemblance stops at the fallback: there is no
 * `LogDriver` equivalent here and there never will be. A message that quietly
 * went to a log file is an annoyance; a PAYMENT that quietly went to a log file
 * is a customer who believes they have paid and a ledger that says they have
 * not. `PaymentGatewayManager` refuses instead (SEC-CFG-04).
 *
 * A gateway does exactly two things: turn a Pending payment into a link the
 * customer can pay, and translate the provider's webhook into something this
 * application already understands. It never decides what a paid link MEANS to
 * the ledger - that is `PaymentService`'s BR-PAY-02 matrix and nothing else's.
 */
interface PaymentGateway
{
    /** Provider name recorded on the payment and the link, e.g. "razorpay". */
    public function name(): string;

    /**
     * Whether the credentials needed to CREATE a link are present.
     *
     * Separate from `webhookIsConfigured()` because the two halves fail
     * differently: without an API key we cannot ask for a link at all, whereas
     * without a signing secret we could still receive callbacks - and must not
     * act on them.
     */
    public function isConfigured(): bool;

    /** Whether the signing secret needed to TRUST a callback is present. */
    public function webhookIsConfigured(): bool;

    /**
     * Names the credential an operator has to supply, for the refusal message.
     * "Not configured" with no clue which field is missing is a support ticket.
     */
    public function missingCredential(): ?string;

    /**
     * Asks the gateway for a payment link covering this (Pending) payment.
     *
     * @param  array<string, mixed>  $options  description, expires_at, notify
     *
     * @throws ApiException when the gateway is unreachable or refuses
     */
    public function createLink(Payment $payment, array $options = []): GatewayLink;

    /** The header the provider puts its signature in, e.g. "X-Razorpay-Signature". */
    public function signatureHeader(): string;

    /**
     * Verifies a callback against the RAW body.
     *
     * Raw, not the parsed array: re-encoding a decoded payload changes key
     * order and numeric formatting, and the digest is over the bytes the
     * provider actually sent (SEC-WH-01).
     */
    public function verifySignature(string $rawBody, ?string $signature): bool;

    /**
     * Translates a provider payload into the three outcomes the ledger cares
     * about, or null when the event is one we do not act on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseEvent(array $payload): ?GatewayEvent;
}
