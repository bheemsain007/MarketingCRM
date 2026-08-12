<?php

namespace App\Services\Calls\AiCalling;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * AI calling through Vaaad (Phase 24, FR-AI-01).
 *
 * Optional by design, exactly like the message channels: with no `api_key`
 * configured `isConfigured()` is false and the feature simply refuses rather
 * than half-working. Add the credential in Settings and it starts placing
 * calls - no code change, no deploy (SEC-CFG-04). The credential itself already
 * lives in `config/providers.php` and the settings registry.
 *
 * **The wire format is provisional** (T-35): Vaaad's API documentation has not
 * been supplied, so the request below is the conventional shape and needs
 * confirming before production. Everything around it - the DNC and calling-hours
 * gate, the call record, the outcome webhook - is independent of that detail.
 */
class VaaadClient
{
    private const ENDPOINT = 'https://api.vaaad.ai/v1/calls';

    public function __construct(private readonly SettingsService $settings) {}

    /** Whether a Vaaad key is configured - the switch the whole feature hangs on. */
    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.vaaad.api_key');
    }

    /**
     * Places an AI call and returns Vaaad's call id, which is echoed back on the
     * result webhook so the outcome can be matched to our record.
     *
     * @throws ApiException when Vaaad is unreachable or refuses the request -
     *                      the caller has already gated the lead, so a failure
     *                      here is the provider's, and it is surfaced rather than
     *                      swallowed into a silent no-op.
     */
    public function placeCall(Lead $lead, ?string $script = null): ?string
    {
        try {
            $response = Http::withToken((string) $this->settings->get('providers.vaaad.api_key'))
                ->timeout(15)
                ->post(self::ENDPOINT, array_filter([
                    'to' => $lead->phone_e164,
                    // Echoed back on the webhook so the outcome maps to our lead
                    // and call without trusting the number.
                    'reference' => (string) $lead->id,
                    'script' => $script,
                    'webhook_url' => route('api.v1.webhooks.vaaad'),
                ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            throw new ApiException(
                ErrorCode::ProviderUnavailable,
                'Could not reach the AI calling provider: '.$e->getMessage(),
            );
        }

        if (! $response->successful()) {
            throw new ApiException(
                ErrorCode::ProviderUnavailable,
                'The AI calling provider rejected the request ('.$response->status().').',
            );
        }

        return $response->json('call_id') ?? $response->json('id');
    }
}
