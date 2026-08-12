<?php

namespace App\Services\Messaging\Drivers;

use App\Contracts\MessageDriver;
use App\Models\Message;
use App\Services\Messaging\DeliveryResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * RCS via a messaging aggregator (Phase 16, FR-RCS-01, FR-COMM-01..06).
 *
 * One driver class - everything else was already channel-agnostic from Phase 13.
 *
 * **The vendor is not chosen (T-32),** so both the endpoint and the request body
 * are provisional (T-35). RCS Business Messaging is reached in practice through
 * a CPaaS aggregator exposing a REST API keyed by a single token, which is the
 * shape the `providers.rcs.{provider,api_key}` credentials describe, and the
 * shape modelled here. When a vendor is named this becomes their endpoint and
 * body - another driver class at most; the gate, queue and status machinery do
 * not move.
 *
 * RCS degrades to SMS on the carrier side when the handset has no RCS profile;
 * that fallback is the aggregator's job, not ours, so this sends the RCS text
 * and records what the aggregator accepted.
 */
class RcsDriver implements MessageDriver
{
    private const ENDPOINT = 'https://rcs.googleapis.com/v1/messages';

    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        // The concrete vendor is unknown (T-32); record whatever provider is
        // configured, falling back to the channel name so the row is never blank.
        return (string) ($this->settings->get('providers.rcs.provider') ?: 'rcs');
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.rcs.provider')
            && $this->settings->isConfigured('providers.rcs.api_key');
    }

    public function send(Message $message): DeliveryResult
    {
        try {
            $response = Http::withToken((string) $this->settings->get('providers.rcs.api_key'))
                ->timeout(15)
                ->post(self::ENDPOINT, [
                    // RCS addresses are E.164, unlike the national form the SMS
                    // aggregator wants - so the stored value is sent as-is.
                    'to' => (string) $message->recipient,
                    'contentMessage' => ['text' => (string) $message->body],
                    // Echoed back on the delivery webhook so a status update maps
                    // to our row without trusting the address, which is not
                    // unique over time.
                    'messageId' => $message->idempotency_key,
                ]);
        } catch (Throwable $e) {
            return DeliveryResult::failed('Could not reach the RCS provider: '.$e->getMessage());
        }

        if ($response->successful()) {
            return DeliveryResult::accepted(
                $response->json('messageId') ?? $response->json('name'),
            );
        }

        // 4xx is the provider refusing the request - an agent not launched, a
        // number with no RCS capability the aggregator will not fall back for.
        // Retrying repeats the refusal.
        if ($response->clientError()) {
            return DeliveryResult::rejected(
                'The RCS provider rejected the message ('.$response->status().'): '
                .mb_substr((string) $response->body(), 0, 180),
            );
        }

        return DeliveryResult::failed('The RCS provider returned '.$response->status().'.');
    }
}
