<?php

namespace App\Services\Messaging\Drivers;

use App\Contracts\MessageDriver;
use App\Models\Message;
use App\Services\Messaging\DeliveryResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp via the Meta Cloud API (Phase 14, FR-WA-01, FR-COMM-01..06).
 *
 * Like the other channels this is one driver class - the gate, queue,
 * idempotency and status transitions were built in Phase 13 and are
 * channel-agnostic. The whole channel is one arm in `MessageDriverManager` and
 * one credential set.
 *
 * **The BSP is not chosen yet (T-31).** This implements the *Meta direct* shape
 * because the credential names Phase 1 settled on - `phone_number_id`, a bearer
 * `token`, `app_secret` - are the Cloud API's own, so an operator who fills in
 * the documented `.env` keys gets a working channel. If a BSP (Gupshup,
 * Interakt) is chosen instead, that is a different endpoint and request body,
 * i.e. another driver class here - nothing outside this file changes.
 *
 * **The wire format is provisional** until the account is live (T-35): this is
 * the conventional `/{phone_number_id}/messages` text-message shape.
 */
class WhatsAppDriver implements MessageDriver
{
    /**
     * Graph API host and version. The phone-number id is per-account and comes
     * from settings, so only the version is pinned here - Meta deprecates old
     * versions on a schedule, and a silently-floating version is a channel that
     * breaks without a deploy.
     */
    private const GRAPH = 'https://graph.facebook.com/v21.0';

    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        // Records which integration actually sent the row. Defaults to the Meta
        // Cloud API label; if a BSP is configured its name is recorded instead,
        // so "who sent this?" stays answerable once the vendor is chosen.
        return (string) ($this->settings->get('providers.whatsapp.driver') ?: 'whatsapp_cloud');
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.whatsapp.token')
            && $this->settings->isConfigured('providers.whatsapp.phone_number_id');
    }

    public function send(Message $message): DeliveryResult
    {
        // The Cloud API wants the number in international format, digits only -
        // no leading '+'. Storage is E.164, so the difference is exactly that
        // character.
        $to = ltrim((string) $message->recipient, '+');

        try {
            $response = Http::withToken((string) $this->settings->get('providers.whatsapp.token'))
                ->timeout(15)
                ->post(self::GRAPH.'/'.$this->settings->get('providers.whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => (string) $message->body],
                ]);
        } catch (Throwable $e) {
            // Connection-level failure: nothing was accepted, so retrying is
            // safe and correct (FR-COMM-06).
            return DeliveryResult::failed('Could not reach WhatsApp: '.$e->getMessage());
        }

        if ($response->successful()) {
            // { "messages": [ { "id": "wamid.XXX" } ] } - the wamid is echoed
            // back on the delivery webhook, so it is what we key status on.
            return DeliveryResult::accepted($response->json('messages.0.id'));
        }

        // 4xx is Meta refusing the request itself - an unregistered number, a
        // 24-hour-window violation, a bad template. The same request retried is
        // the same refusal, so it is rejected rather than failed.
        if ($response->clientError()) {
            return DeliveryResult::rejected(
                'WhatsApp rejected the message ('.$response->status().'): '
                .$this->errorFrom($response).'.',
            );
        }

        return DeliveryResult::failed('WhatsApp returned '.$response->status().'.');
    }

    /**
     * The Graph API reports the reason in `error.message`; fall back to the raw
     * body so a shape we did not expect is still recorded rather than swallowed.
     */
    private function errorFrom($response): string
    {
        return (string) ($response->json('error.message')
            ?? mb_substr((string) $response->body(), 0, 180));
    }
}
