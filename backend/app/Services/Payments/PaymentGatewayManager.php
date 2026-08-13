<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Payments\Gateways\RazorpayGateway;
use App\Services\Settings\SettingsService;

/**
 * Resolves the configured payment gateway (FR-PAY-02, T-34).
 *
 * The same shape as `MessageDriverManager` with the one decision reversed: it
 * REFUSES rather than falling back. `MessageDriverManager` routes an unkeyed
 * channel to `LogDriver` because eight phases were blocked on credentials
 * nobody had, and a logged message is a visible no-op. There is no acceptable
 * equivalent for money. A "log driver" for payments would hand the customer a
 * link that collects nothing, or - worse, since the ledger cannot tell the
 * difference - mark a sale settled against a payment that never happened. So
 * an unkeyed install gets a 503 naming the missing credential, and adding the
 * key in Settings turns collection on with no deploy (SEC-CFG-04).
 *
 * Razorpay is the default (T-34). Selecting a gateway we have not implemented
 * is also a refusal rather than a silent fall back to Razorpay: quietly
 * collecting through a different provider than the operator chose is a
 * reconciliation problem nobody would think to look for.
 */
class PaymentGatewayManager
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * The gateway named in settings, configured or not, or null when the name
     * has no implementation.
     *
     * Returns the unconfigured object rather than null so callers that only
     * need the wire format - webhook signature verification, event parsing -
     * do not each have to re-derive which gateway is in play.
     */
    public function gateway(): ?PaymentGateway
    {
        return match ($this->selected()) {
            'razorpay' => new RazorpayGateway($this->settings),

            // payu / stripe / other are settable in the registry but not built.
            // Naming one is a deliberate operator choice, so it is honoured by
            // refusing, not by substituting.
            default => null,
        };
    }

    /**
     * The gateway, ready to take money - or an ApiException that says exactly
     * which credential is missing.
     *
     * @throws ApiException (503) when no usable gateway is configured
     */
    public function resolve(): PaymentGateway
    {
        $gateway = $this->gateway();

        if ($gateway === null) {
            throw new ApiException(
                ErrorCode::ProviderUnavailable,
                sprintf(
                    'The payment gateway "%s" is selected but not supported. Set providers.payment.gateway to "razorpay" in Settings.',
                    $this->selected(),
                ),
            );
        }

        if (! $gateway->isConfigured()) {
            // Names the field, not just the fact. "Not configured" with no clue
            // which value is missing is a support ticket, and the operator
            // fixing it is looking at the Settings screen, which is keyed on
            // exactly this string.
            throw new ApiException(
                ErrorCode::ProviderUnavailable,
                sprintf(
                    'Online payment collection is not configured. Add %s in Settings to enable it.',
                    $gateway->missingCredential() ?? 'the gateway credentials',
                ),
            );
        }

        return $gateway;
    }

    /** Whether links can be created right now - for the UI, not for a gate. */
    public function isLive(): bool
    {
        return $this->gateway()?->isConfigured() ?? false;
    }

    /**
     * The configured gateway name, lowercased.
     *
     * Falls back to the config default rather than to null so that a fresh
     * install has a gateway CHOSEN (Razorpay, T-34) even though it has no
     * credentials - "which gateway" and "is it keyed" are separate questions
     * and conflating them produces the wrong error message for both.
     */
    private function selected(): string
    {
        return strtolower(trim((string) ($this->settings->get('providers.payment.gateway') ?: 'razorpay')));
    }
}
