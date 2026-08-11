<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\ProviderWebhookLog;
use App\Services\Dnc\DncService;
use App\Services\Settings\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * Inbound provider webhooks (FR-COMM-03, SEC-WH-*).
 *
 * Unauthenticated by necessity - a provider has no session - so the payload is
 * treated as hostile: every request is logged before it is acted on, a status
 * is only ever applied to a message we already know about, and nothing in the
 * body may create a record.
 *
 * **The signature scheme is provisional.** Mailercloud's webhook signing
 * documentation has not been supplied (T-35), so verification currently uses a
 * shared secret compared in constant time. That is real protection, but it is
 * not the provider's own scheme - confirm before production (T-53).
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly DncService $dnc,
    ) {}

    public function mailercloud(Request $request): JsonResponse
    {
        $eventId = $request->input('event_id') ?? $request->input('id');

        try {
            $log = ProviderWebhookLog::create([
                'provider' => 'mailercloud',
                'event_type' => (string) $request->input('event', 'unknown'),
                'provider_event_id' => $eventId,
                'payload' => $request->all(),
                // Only what identifies the caller. The full header bag carries
                // the shared secret, and this row is long-lived (SEC-CFG-05).
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // (provider, provider_event_id) is unique, so a redelivery of an
            // event we already handled is absorbed by the database rather than
            // applied twice.
            return Response::json(['success' => true, 'message' => 'Already processed.']);
        }

        if (! $this->signatureIsValid($request)) {
            $log->update([
                'processed_at' => now(),
                'processing_error' => 'Invalid or missing signature.',
            ]);

            // 401 rather than 200, so a provider retries if our secret was
            // merely misconfigured rather than the request being forged.
            return Response::json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $log->update(['signature_valid' => true]);

        $message = $this->resolveMessage($request);

        if ($message === null) {
            // Acknowledged, not errored: an unknown id is usually an event for
            // another environment sharing the provider account, and making the
            // provider retry it forever helps nobody.
            $log->update(['processed_at' => now(), 'processing_error' => 'No matching message.']);

            return Response::json(['success' => true, 'message' => 'No matching message.']);
        }

        $applied = $this->applyStatus($message, (string) $request->input('event'), $request->all());

        $log->update([
            'processed_at' => now(),
            // An unrecognised event is recorded as such rather than being
            // guessed at - that is how a bounce silently becomes a delivery
            // (FR-COMM-03: unknown states are visible, not swallowed).
            'processing_error' => $applied ? null : 'Unrecognised event type.',
        ]);

        return Response::json(['success' => true, 'message' => 'Processed.']);
    }

    private function resolveMessage(Request $request): ?Message
    {
        if ($key = $request->input('custom_id')) {
            $message = Message::where('idempotency_key', $key)->first();

            if ($message !== null) {
                return $message;
            }
        }

        if ($providerId = $request->input('message_id')) {
            return Message::where('provider', 'mailercloud')
                ->where('provider_message_id', $providerId)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool whether the event was recognised
     */
    private function applyStatus(Message $message, string $event, array $payload): bool
    {
        $hardBounce = $this->isHardBounce($event, $payload);

        $changes = match ($event) {
            'delivered' => ['status' => 'delivered', 'delivered_at' => now()],
            'open', 'opened' => ['status' => 'read', 'read_at' => now()],
            'click', 'clicked' => ['read_at' => $message->read_at ?? now()],
            'bounce', 'bounced', 'hard_bounce' => [
                'status' => 'bounced',
                'failed_at' => now(),
                // Which kind of bounce it was decides whether the address is
                // still usable, so the record says which one it was rather
                // than "bounced" for both.
                'failure_reason' => $hardBounce
                    ? 'Hard bounce reported by the provider; email address suppressed.'
                    : 'Bounce reported by the provider, not confirmed hard - address left contactable.',
            ],
            'spam', 'complaint', 'unsubscribe' => [
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => 'Recipient complaint or unsubscribe: '.$event,
            ],
            default => null,
        };

        if ($changes === null) {
            return false;
        }

        $message->update($changes);
        $this->applySuppression($message, $event, $hardBounce);

        return true;
    }

    /**
     * Automatic suppression from provider feedback (BR-DNC-07, T-54).
     *
     * Routed through DncService rather than writing `dnc_entries` here - a
     * webhook controller keeping its own suppression list is exactly the
     * per-module DNC that ADR-E exists to prevent, and `suppress()` is
     * idempotent, so a provider redelivering an event cannot stack rows.
     */
    private function applySuppression(Message $message, string $event, bool $hardBounce): void
    {
        $lead = $message->lead;

        if ($lead === null) {
            return;
        }

        if ($hardBounce) {
            /*
             * Channel is left null so the REASON decides what is blocked
             * (BR-DNC-02): a dead mailbox says nothing about the phone number,
             * and BouncedEmail blocks email alone.
             */
            $this->dnc->suppress(
                $lead,
                DncReason::BouncedEmail,
                channel: null,
                source: 'webhook',
                actorId: null,
                note: sprintf('Hard bounce reported for message #%d.', $message->id),
            );

            return;
        }

        if (! in_array($event, ['spam', 'complaint', 'unsubscribe'], true)) {
            return;
        }

        /*
         * Someone who unsubscribes from email has told us to stop emailing
         * them, and continuing is not a thing we get to decide.
         *
         * Scoped to Email ON PURPOSE. `OptedOut` is an absolute reason - with a
         * null channel it would block every channel, and silently ending all
         * phone contact because somebody clicked "unsubscribe" in a newsletter
         * infers far more than the click actually said. A lead who wants no
         * contact at all is `DoNotContact`, which is a deliberate act.
         */
        $this->dnc->suppress(
            $lead,
            DncReason::OptedOut,
            channel: Channel::Email,
            source: 'webhook',
            actorId: null,
            note: sprintf('Provider reported "%s" for message #%d.', $event, $message->id),
        );
    }

    /**
     * Whether the provider is reporting a *permanent* failure.
     *
     * The asymmetry here is the whole design. Failing to suppress a hard bounce
     * costs us sender reputation; suppressing a SOFT bounce - a full mailbox, a
     * server having a bad afternoon - permanently stops email to a real
     * customer, and lifting it needs Manager+ (BR-DNC-06). So a generic
     * "bounce" suppresses only when the payload actually says it was hard.
     *
     * Mailercloud's payload contract is still unconfirmed (T-53), which is
     * another reason to require the explicit signal rather than assume it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isHardBounce(string $event, array $payload): bool
    {
        if ($event === 'hard_bounce') {
            return true;
        }

        if (! in_array($event, ['bounce', 'bounced'], true)) {
            return false;
        }

        $type = strtolower((string) ($payload['bounce_type'] ?? $payload['type'] ?? ''));

        return str_contains($type, 'hard') || str_contains($type, 'permanent');
    }

    /** Constant-time, so a wrong secret cannot be found by timing responses. */
    private function signatureIsValid(Request $request): bool
    {
        $expected = $this->settings->get('providers.mailercloud.webhook_secret');

        // No secret configured means the endpoint is not in service. Accepting
        // everything in that state would make an unconfigured install an open
        // write endpoint.
        if (! is_string($expected) || $expected === '') {
            return false;
        }

        $provided = (string) ($request->header('X-Webhook-Token') ?? $request->input('token', ''));

        return hash_equals($expected, $provided);
    }
}
