<?php

namespace App\Services\Payments\Gateways;

use App\Contracts\PaymentGateway;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Razorpay payment links (Phase 23, FR-PAY-02, T-34).
 *
 * Razorpay is the default choice for T-34 and, unlike the channel drivers this
 * class is modelled on, its wire format is NOT provisional: the Payment Links
 * REST API and the HMAC-SHA256 webhook signature are publicly documented, so
 * this is written to the real contract rather than to a conventional shape.
 * What remains unverified is only the account-level configuration - which events
 * are subscribed in the Razorpay dashboard - and that is an operator step, not
 * a code one.
 *
 * Unkeyed-optional in the same sense as `VaaadClient`: no credential means the
 * feature refuses, loudly, naming what is missing (SEC-CFG-04). It never
 * degrades to a stub, because a stubbed payment link is a customer paying into
 * nowhere.
 */
class RazorpayGateway implements PaymentGateway
{
    private const ENDPOINT = 'https://api.razorpay.com/v1/payment_links';

    /**
     * Razorpay quotes money in the currency's MINOR unit - paise for INR. Every
     * amount crossing this boundary is converted in exactly one place, because
     * a factor-of-100 error in a payments module is not a rounding bug, it is a
     * hundredfold charge.
     */
    private const MINOR_UNITS = 100;

    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        return 'razorpay';
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.payment.key_id')
            && $this->settings->isConfigured('providers.payment.key_secret');
    }

    public function webhookIsConfigured(): bool
    {
        return $this->settings->isConfigured('providers.payment.webhook_secret');
    }

    public function missingCredential(): ?string
    {
        return match (true) {
            ! $this->settings->isConfigured('providers.payment.key_id') => 'providers.payment.key_id',
            ! $this->settings->isConfigured('providers.payment.key_secret') => 'providers.payment.key_secret',
            default => null,
        };
    }

    public function signatureHeader(): string
    {
        return 'X-Razorpay-Signature';
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ApiException when Razorpay is unreachable or refuses the request
     */
    public function createLink(Payment $payment, array $options = []): GatewayLink
    {
        $customer = $payment->customer;

        $body = array_filter([
            'amount' => (int) round((float) $payment->amount * self::MINOR_UNITS),
            'currency' => $payment->currency,
            // Partial collection is OFF: the ledger already models instalments
            // as separate payment rows through BR-PAY-02, and letting the
            // gateway split one row as well would give the same money two
            // representations that drift.
            'accept_partial' => false,
            'description' => $options['description'] ?? sprintf('Payment %s', $payment->reference),
            // Our own receipt number, echoed back on every event - the human
            // handle when somebody reconciles a Razorpay statement by eye.
            'reference_id' => $payment->reference,
            'expire_by' => isset($options['expires_at'])
                ? Carbon::parse($options['expires_at'])->getTimestamp()
                : null,
            'customer' => array_filter([
                'name' => $customer?->name,
                'email' => $customer?->email,
                'contact' => $customer?->phone_e164,
            ], fn ($v) => $v !== null && $v !== ''),
            'notify' => [
                'sms' => (bool) ($options['notify_sms'] ?? true),
                'email' => (bool) ($options['notify_email'] ?? true),
            ],
            /*
             * Razorpay copies link notes onto the payment it produces. That is
             * what lets `payment.failed` - an event about a payment, which
             * carries no link entity - still be traced back to the link that
             * caused it. Without this, a failed attempt would be unattributable.
             */
            'notes' => [
                'payment_link_id' => (string) $payment->id,
                'payment_reference' => $payment->reference,
            ],
        ], fn ($v) => $v !== null);

        try {
            $response = Http::withBasicAuth(
                (string) $this->settings->get('providers.payment.key_id'),
                (string) $this->settings->get('providers.payment.key_secret'),
            )->timeout(15)->post(self::ENDPOINT, $body);
        } catch (Throwable $e) {
            throw new ApiException(
                ErrorCode::ProviderUnavailable,
                'Could not reach the payment gateway: '.$e->getMessage(),
            );
        }

        if (! $response->successful()) {
            /*
             * The gateway's own description is surfaced rather than swallowed.
             * "Payment link failed" tells the person at the counter nothing;
             * "amount exceeds maximum" tells them what to change. Razorpay's
             * error bodies carry no credential material.
             */
            throw new ApiException(
                ErrorCode::ProviderRejected,
                sprintf(
                    'The payment gateway rejected the request (%d): %s',
                    $response->status(),
                    (string) ($response->json('error.description') ?? 'no reason given'),
                ),
            );
        }

        $id = $response->json('id');
        $url = $response->json('short_url');

        if (! is_string($id) || $id === '' || ! is_string($url) || $url === '') {
            // A 200 with no link in it is not a link. Storing a half-record here
            // would leave a payment row that can never be paid or reconciled.
            throw new ApiException(
                ErrorCode::ProviderRejected,
                'The payment gateway accepted the request but returned no payment link.',
            );
        }

        $expiry = $response->json('expire_by');

        return new GatewayLink(
            id: $id,
            url: $url,
            expiresAt: is_numeric($expiry) ? Carbon::createFromTimestamp((int) $expiry) : null,
            raw: is_array($response->json()) ? $response->json() : [],
        );
    }

    /**
     * Razorpay signs the raw body with the webhook secret, HMAC-SHA256, hex.
     *
     * `hash_equals`, so a wrong signature cannot be found by timing responses,
     * and an absent secret means the endpoint is out of service rather than
     * open - the same failure direction as every other webhook here.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->settings->get('providers.payment.webhook_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    /**
     * Translates Razorpay's event zoo into the four outcomes the ledger acts on.
     *
     * Only the events that MOVE MONEY or close a link are recognised. Everything
     * else - `payment_link.partially_paid`, order and settlement events, the
     * account webhooks - parses to null and is logged without being applied.
     * That asymmetry is deliberate: an unhandled event costs a manual
     * reconciliation, whereas a guessed one silently rewrites the ledger.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseEvent(array $payload): ?GatewayEvent
    {
        $event = (string) ($payload['event'] ?? '');
        $link = Arr::get($payload, 'payload.payment_link.entity', []);
        $payment = Arr::get($payload, 'payload.payment.entity', []);
        $refund = Arr::get($payload, 'payload.refund.entity', []);

        $linkId = Arr::get($link, 'id');
        $paymentId = Arr::get($payment, 'id');

        return match ($event) {
            'payment_link.paid' => new GatewayEvent(
                type: GatewayEvent::PAID,
                linkId: is_string($linkId) ? $linkId : null,
                gatewayPaymentId: is_string($paymentId) ? $paymentId : null,
                amount: $this->toMajorUnits(Arr::get($payment, 'amount') ?? Arr::get($link, 'amount_paid')),
                providerEvent: $event,
            ),

            'payment_link.expired', 'payment_link.cancelled' => new GatewayEvent(
                type: GatewayEvent::CLOSED,
                linkId: is_string($linkId) ? $linkId : null,
                providerEvent: $event,
            ),

            'payment.failed' => new GatewayEvent(
                type: GatewayEvent::FAILED,
                // No link entity on this event - only the note we planted when
                // the link was created (see createLink()).
                linkId: null,
                gatewayPaymentId: is_string($paymentId) ? $paymentId : null,
                amount: $this->toMajorUnits(Arr::get($payment, 'amount')),
                reason: $this->failureReason($payment),
                providerEvent: $event,
                internalPaymentId: $this->internalPaymentIdFrom($payment),
            ),

            'refund.processed', 'refund.created' => new GatewayEvent(
                type: GatewayEvent::REFUNDED,
                linkId: null,
                // A refund names the payment it reverses, never the link.
                gatewayPaymentId: is_string($id = Arr::get($refund, 'payment_id')) ? $id : null,
                amount: $this->toMajorUnits(Arr::get($refund, 'amount')),
                reason: (string) (Arr::get($refund, 'notes.reason') ?? 'Refunded at the payment gateway.'),
                providerEvent: $event,
            ),

            default => null,
        };
    }

    /**
     * Our own payment id, planted in the link's notes and copied by Razorpay
     * onto the payment - the only handle a `payment.failed` event gives us.
     *
     * @param  mixed  $paymentEntity
     */
    private function internalPaymentIdFrom($paymentEntity): ?int
    {
        $id = Arr::get($paymentEntity, 'notes.payment_link_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** @param mixed $paymentEntity */
    private function failureReason($paymentEntity): string
    {
        $description = Arr::get($paymentEntity, 'error_description');

        return is_string($description) && $description !== ''
            ? $description
            : 'The payment attempt failed at the gateway.';
    }

    /** @param mixed $minor */
    private function toMajorUnits($minor): ?float
    {
        return is_numeric($minor) ? round(((float) $minor) / self::MINOR_UNITS, 2) : null;
    }
}
