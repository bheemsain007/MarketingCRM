<?php

namespace App\Services\Messaging\Drivers;

use App\Contracts\MessageDriver;
use App\Models\Message;
use App\Services\Messaging\DeliveryResult;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Email via Mailercloud (FR-EMAIL-01).
 *
 * Credentials are resolved through `SettingsService`, so they come from
 * `config('providers.mailercloud.*')` - i.e. `.env` - unless an override has
 * been stored through the settings screen (SEC-CFG-01/04). No key is ever read
 * from `env()` here: once `config:cache` runs in production, `env()` returns
 * null and this would silently start sending unauthenticated.
 *
 * **The endpoint shape is provisional.** Mailercloud's transactional API
 * documentation has not been supplied (T-35), so the request body below is the
 * conventional shape and will need confirming against the real contract before
 * this is pointed at production. Everything around it - the gate, the queue,
 * the idempotency key, the status transitions - is independent of that detail.
 */
class MailercloudDriver implements MessageDriver
{
    private const ENDPOINT = 'https://cloudapi.mailercloud.com/v1/emails/send';

    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        return 'mailercloud';
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.mailercloud.api_key')
            && $this->settings->isConfigured('providers.mailercloud.from_email');
    }

    public function send(Message $message): DeliveryResult
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => (string) $this->settings->get('providers.mailercloud.api_key'),
                'Content-Type' => 'application/json',
            ])
                ->timeout(15)
                ->post(self::ENDPOINT, [
                    'from' => [
                        'email' => $this->settings->get('providers.mailercloud.from_email'),
                        'name' => $this->settings->get('providers.mailercloud.from_name'),
                    ],
                    'to' => [['email' => $message->recipient]],
                    'subject' => $message->subject,
                    'html' => $message->body,
                    // Echoed back on the delivery webhook so a status update can
                    // be matched to our row without trusting the recipient
                    // address, which is not unique over time.
                    'custom_id' => $message->idempotency_key,
                ]);
        } catch (Throwable $e) {
            // Connection-level failure: nothing was accepted, so it is safe and
            // correct to try again (FR-COMM-06).
            return DeliveryResult::failed('Could not reach Mailercloud: '.$e->getMessage());
        }

        if ($response->successful()) {
            return DeliveryResult::accepted(
                $response->json('id') ?? $response->json('message_id'),
            );
        }

        // 4xx is the provider telling us the request itself is wrong - a bad
        // address, a rejected sender. Retrying sends the same bad request 3
        // more times and ends in the same place, so it is rejected outright.
        if ($response->clientError()) {
            return DeliveryResult::rejected(
                'Mailercloud rejected the message ('.$response->status().'): '
                .mb_substr((string) $response->body(), 0, 180),
            );
        }

        return DeliveryResult::failed('Mailercloud returned '.$response->status().'.');
    }
}
