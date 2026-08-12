<?php

namespace App\Services\Messaging\Drivers;

use App\Contracts\MessageDriver;
use App\Models\Message;
use App\Services\Messaging\DeliveryResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Voice / voice-SMS blast (Phase 17, FR-VOICE-01, FR-COMM-01..06).
 *
 * This is the *outbound message* voice channel - a pre-recorded or
 * text-to-speech announcement placed as an automated call - not the interactive
 * human/AI dialling of `Channel::Call`/`AiCall`, which have their own consent,
 * calling-hours and single-assignment rules and never come through here
 * (`Channel::isVoiceCall()` deliberately excludes Voice). So it is one more
 * driver on the same channel-agnostic pipeline as SMS and Email.
 *
 * **The vendor is not chosen (T-33),** so the endpoint and request body are
 * provisional (T-35). Indian voice providers expose a REST API keyed by a single
 * token that takes a destination number and either a TTS script or an audio id -
 * the shape `providers.voice.{provider,api_key}` describes, and the shape here.
 * When a vendor is named this becomes their contract; nothing else moves.
 */
class VoiceDriver implements MessageDriver
{
    private const ENDPOINT = 'https://voice.example-provider.com/v1/calls';

    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        // Vendor unknown (T-33): record the configured provider, falling back to
        // the channel name so the row is never blank.
        return (string) ($this->settings->get('providers.voice.provider') ?: 'voice');
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.voice.provider')
            && $this->settings->isConfigured('providers.voice.api_key');
    }

    public function send(Message $message): DeliveryResult
    {
        try {
            $response = Http::withToken((string) $this->settings->get('providers.voice.api_key'))
                ->timeout(15)
                ->post(self::ENDPOINT, [
                    // Voice providers dial an E.164 number, so the stored value
                    // is sent as-is rather than reduced to the national form the
                    // SMS aggregator needs.
                    'to' => (string) $message->recipient,
                    // The body is the announcement, read out by text-to-speech.
                    'text' => (string) $message->body,
                    // Echoed back on the delivery webhook so a status update maps
                    // to our row without trusting the number, which is not unique
                    // over time.
                    'reference' => $message->idempotency_key,
                ]);
        } catch (Throwable $e) {
            return DeliveryResult::failed('Could not reach the voice provider: '.$e->getMessage());
        }

        if ($response->successful()) {
            return DeliveryResult::accepted(
                $response->json('call_id') ?? $response->json('id'),
            );
        }

        // 4xx is the provider refusing the request - an unreachable number, an
        // empty script. The same request retried is the same refusal.
        if ($response->clientError()) {
            return DeliveryResult::rejected(
                'The voice provider rejected the message ('.$response->status().'): '
                .mb_substr((string) $response->body(), 0, 180),
            );
        }

        return DeliveryResult::failed('The voice provider returned '.$response->status().'.');
    }
}
