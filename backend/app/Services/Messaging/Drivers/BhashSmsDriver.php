<?php

namespace App\Services\Messaging\Drivers;

use App\Contracts\MessageDriver;
use App\Models\Message;
use App\Services\Messaging\DeliveryResult;
use App\Services\Settings\SettingsService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SMS via BhashSMS (FR-SMS-01).
 *
 * **HTTPS, not the documented HTTP endpoint.** BhashSMS publishes its API over
 * plain HTTP with the account username and password as query parameters, which
 * would put working credentials into every proxy log, reverse-proxy access log
 * and network capture between here and them. TLS is forced instead. Moving them
 * out of the query string entirely needs the provider to support it - raised as
 * T-55.
 *
 * **The wire format is provisional** (T-35): this is the conventional
 * `sendmsg.php` shape, and the success convention below - a body beginning
 * `S.` - is how their gateway is commonly documented. Confirm against the real
 * contract before production. Nothing outside this class depends on it.
 */
class BhashSmsDriver implements MessageDriver
{
    private const ENDPOINT = 'https://bhashsms.com/api/sendmsg.php';

    public function __construct(private readonly SettingsService $settings) {}

    public function name(): string
    {
        return 'bhashsms';
    }

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured('providers.bhashsms.user')
            && $this->settings->isConfigured('providers.bhashsms.password')
            && $this->settings->isConfigured('providers.bhashsms.sender_id');
    }

    public function send(Message $message): DeliveryResult
    {
        // Aggregators take a bare 10-digit number; storage is E.164. Refusing
        // rather than truncating - a mangled number reaches a real person who
        // did not ask to hear from us.
        $national = PhoneNumber::national($message->recipient);

        if ($national === null) {
            return DeliveryResult::rejected(
                'BhashSMS only accepts Indian mobile numbers; '.$message->recipient.' is not one.',
            );
        }

        try {
            $response = Http::timeout(15)->get(self::ENDPOINT, [
                'user' => $this->settings->get('providers.bhashsms.user'),
                'pass' => $this->settings->get('providers.bhashsms.password'),
                // DLT-registered header. Indian carriers reject anything else.
                'sender' => $this->settings->get('providers.bhashsms.sender_id'),
                'phone' => $national,
                'text' => (string) $message->body,
                'priority' => 'ndnd',
                'stype' => 'normal',
            ]);
        } catch (Throwable $e) {
            return DeliveryResult::failed('Could not reach BhashSMS: '.$e->getMessage());
        }

        if (! $response->successful()) {
            // The gateway answers 200 for business-level rejections too, so a
            // non-200 here is genuinely transport-level and worth retrying.
            return DeliveryResult::failed('BhashSMS returned '.$response->status().'.');
        }

        $body = trim((string) $response->body());

        if (str_starts_with($body, 'S.')) {
            return DeliveryResult::accepted(substr($body, 2) ?: null);
        }

        /*
         * A 200 with a non-`S.` body is the gateway refusing the message -
         * wrong credentials, an unregistered sender ID, a template that does
         * not match DLT registration. None of those change on a retry, so this
         * is a rejection rather than a failure.
         */
        return DeliveryResult::rejected(
            'BhashSMS rejected the message: '.mb_substr($body, 0, 180),
        );
    }
}
