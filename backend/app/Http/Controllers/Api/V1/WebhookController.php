<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Lead;
use App\Models\Message;
use App\Models\ProviderWebhookLog;
use App\Services\Calls\AiCallService;
use App\Services\Dnc\DncService;
use App\Services\Messaging\DeliveryStatusService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentLinkService;
use App\Services\Settings\SettingsService;
use App\Support\OptOutKeyword;
use App\Support\PhoneNumber;
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

    /**
     * Delivery status for the non-email channels (FR-COMM-03).
     *
     * `mailercloud()` above was the only way a status could ever come back, so
     * an SMS, WhatsApp, RCS or Voice message reached `sent` and stopped there
     * for ever - `delivered_at`, `read_at` and failure handling were unreachable
     * for four of the five message channels, and a number the carrier says does
     * not exist went on being messaged because nothing could report it.
     *
     * One generic endpoint rather than four, and the channel travels in the
     * body - the same shape `inbound()` takes, for the same reason: three of the
     * four vendors are not chosen (T-31, T-32, T-33), and four vendor-specific
     * endpoints would be four guesses at payloads nobody has seen. What the
     * words MEAN is DeliveryStatusService's problem; this method's job is the
     * hostile-input handling that every webhook here does identically.
     *
     * The channel is read before the log row is written so the replay guard can
     * key on it: `(provider, provider_event_id)` is unique, and one shared
     * `delivery` provider name would let an SMS event id collide with a WhatsApp
     * one and silently swallow the second. It is parsed, not acted on - an
     * unrecognised channel just labels the row `delivery`.
     */
    public function delivery(Request $request, DeliveryStatusService $statuses): JsonResponse
    {
        $channel = $statuses->channelFor($request->input('channel'));

        try {
            $log = ProviderWebhookLog::create([
                'provider' => 'delivery'.($channel === null ? '' : ':'.$channel->value),
                'event_type' => (string) $request->input('event', 'unknown'),
                'provider_event_id' => $request->input('event_id') ?? $request->input('id'),
                'payload' => $request->all(),
                // Only what identifies the caller. The full header bag carries
                // the shared secret, and this row is long-lived (SEC-CFG-05).
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A redelivery of an event already handled, absorbed by the database
            // rather than applied twice (SEC-WH-03).
            return Response::json(['success' => true, 'message' => 'Already processed.']);
        }

        if (! $this->sharedSecretIsValid($request, 'providers.delivery.webhook_secret')) {
            $log->update(['processed_at' => now(), 'processing_error' => 'Invalid or missing signature.']);

            // 401 rather than 200, so a provider retries if our secret was
            // merely misconfigured rather than the request being forged.
            return Response::json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $log->update(['signature_valid' => true]);

        if ($channel === null) {
            // Acknowledged, not errored, and nothing is applied. Email has its
            // own signed endpoint; accepting it here would mean the same status
            // could be written by whichever of two secrets leaked first.
            $log->update(['processed_at' => now(), 'processing_error' => 'Unsupported channel.']);

            return Response::json(['success' => true, 'message' => 'Unsupported channel.']);
        }

        $message = $statuses->resolve(
            $channel,
            $request->input('custom_id'),
            $request->input('message_id'),
        );

        if ($message === null) {
            // Usually an event for another environment sharing the provider
            // account. Making the provider retry it forever helps nobody.
            $log->update(['processed_at' => now(), 'processing_error' => 'No matching message.']);

            return Response::json(['success' => true, 'message' => 'No matching message.']);
        }

        $applied = $statuses->ingest($message, (string) $request->input('event'), $request->all());

        $log->update([
            'processed_at' => now(),
            // An unrecognised event is recorded as such rather than guessed at -
            // that is how a failure silently becomes a delivery (FR-COMM-03:
            // unknown states are visible, not swallowed).
            'processing_error' => $applied ? null : 'Unrecognised event type.',
        ]);

        return Response::json(['success' => true, 'message' => 'Processed.']);
    }

    /**
     * Inbound keyword opt-out (Phase 19, BR-DNC-05/07).
     *
     * The other half of consent: a lead who replies STOP has told us to stop,
     * and honouring it is not optional. A single generic endpoint takes the
     * inbound reply from any text channel; if the message is an opt-out keyword,
     * the lead is suppressed - routed through DncService like every other
     * trigger, never writing dnc_entries here (ADR-E).
     *
     * Like the delivery webhook this is unauthenticated-by-necessity and treats
     * the body as hostile: logged before it is acted on, a shared-secret check
     * gates it, and it will never create a lead - only match an existing one.
     *
     * The opt-out is scoped to the channel the STOP arrived on, for the same
     * reason `applySuppression()` scopes an email unsubscribe to email: "stop
     * texting me" is not "never call me", and inferring the stronger, harder-to-
     * lift block from the weaker signal is not ours to do (BR-DNC-06).
     */
    public function inbound(Request $request): JsonResponse
    {
        try {
            $log = ProviderWebhookLog::create([
                'provider' => 'inbound',
                'event_type' => (string) $request->input('channel', 'unknown'),
                'provider_event_id' => $request->input('message_id'),
                'payload' => $request->all(),
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Response::json(['success' => true, 'message' => 'Already processed.']);
        }

        if (! $this->sharedSecretIsValid($request, 'providers.inbound.webhook_secret')) {
            $log->update(['processed_at' => now(), 'processing_error' => 'Invalid or missing signature.']);

            return Response::json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $log->update(['signature_valid' => true]);

        $channel = $this->inboundChannel($request);
        $from = PhoneNumber::normalise((string) ($request->input('from') ?? $request->input('phone') ?? ''));
        $text = (string) ($request->input('text') ?? $request->input('body') ?? '');

        if ($channel === null || $from === null || ! OptOutKeyword::matches($text)) {
            // A normal inbound reply that is not an opt-out is acknowledged and
            // dropped - this endpoint exists to catch STOP, not to store chat.
            $log->update(['processed_at' => now(), 'processing_error' => 'No opt-out keyword.']);

            return Response::json(['success' => true, 'message' => 'No action.']);
        }

        $lead = Lead::where('phone_e164', $from)->first();

        if ($lead === null) {
            // No lead owns this number - acknowledged, not errored, so the
            // provider does not retry an opt-out we can do nothing with.
            $log->update(['processed_at' => now(), 'processing_error' => 'No matching lead.']);

            return Response::json(['success' => true, 'message' => 'No matching lead.']);
        }

        $this->dnc->suppress(
            $lead,
            DncReason::OptedOut,
            channel: $channel,
            source: 'inbound',
            actorId: null,
            note: sprintf('Inbound "%s" received on %s.', mb_strtoupper(trim($text)), $channel->label()),
        );

        $log->update(['processed_at' => now()]);

        return Response::json(['success' => true, 'message' => 'Opt-out recorded.']);
    }

    /**
     * Vaaad AI-call result (Phase 25, FR-AI-01, BR-INT-04).
     *
     * Carries the call outcome and, when the model heard interest, a confidence
     * score. The outcome is written once to the call record; the interest signal
     * is handed to the engine, which applies the BR-INT-04 threshold. Like every
     * webhook this is logged before it is acted on and gated by a shared secret.
     */
    public function vaaad(Request $request, AiCallService $ai): JsonResponse
    {
        try {
            $log = ProviderWebhookLog::create([
                'provider' => 'vaaad',
                'event_type' => (string) $request->input('event', 'call_result'),
                'provider_event_id' => $request->input('event_id') ?? $request->input('call_id'),
                'payload' => $request->all(),
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Response::json(['success' => true, 'message' => 'Already processed.']);
        }

        if (! $this->sharedSecretIsValid($request, 'providers.vaaad.webhook_secret')) {
            $log->update(['processed_at' => now(), 'processing_error' => 'Invalid or missing signature.']);

            return Response::json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $log->update(['signature_valid' => true]);

        $call = Call::where('external_call_id', $request->input('call_id') ?? $request->input('id'))->first();

        if ($call === null) {
            $log->update(['processed_at' => now(), 'processing_error' => 'No matching call.']);

            return Response::json(['success' => true, 'message' => 'No matching call.']);
        }

        $ai->ingestResult($call, $request->all());

        $log->update(['processed_at' => now()]);

        return Response::json(['success' => true, 'message' => 'Processed.']);
    }

    /**
     * Gateway collection callback (Phase 23, FR-PAY-02, T-59).
     *
     * The one webhook here whose signature scheme is NOT provisional: Razorpay
     * documents a hex HMAC-SHA256 of the raw body under `X-Razorpay-Signature`,
     * and the gateway class verifies exactly that. Everything else follows the
     * house pattern - logged before it is acted on, unique
     * `(provider, provider_event_id)` absorbing redeliveries, nothing in the
     * payload permitted to create a record.
     *
     * The stakes are higher than for a delivery receipt, so the failure
     * directions are chosen accordingly. No secret configured means every
     * request is rejected, because an unsigned endpoint that can mark payments
     * paid is a way to settle any sale in the system by POSTing to it. And an
     * event that does not match a link we issued is acknowledged rather than
     * guessed at: the full payload is in `provider_webhook_logs` for a human to
     * reconcile, which is recoverable, whereas applying money to the wrong
     * payment is not.
     */
    public function payment(Request $request, PaymentGatewayManager $gateways, PaymentLinkService $links): JsonResponse
    {
        $gateway = $gateways->gateway();
        $provider = $gateway?->name() ?? 'payment';

        try {
            $log = ProviderWebhookLog::create([
                'provider' => $provider,
                'event_type' => (string) $request->input('event', 'unknown'),
                // Razorpay's own event id header; the body id is the fallback so
                // the replay guard still has something to key on.
                'provider_event_id' => $request->header('X-Razorpay-Event-Id') ?? $request->input('id'),
                'payload' => $request->all(),
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // SEC-WH-03: this delivery has been seen. Money is applied once.
            return Response::json(['success' => true, 'message' => 'Already processed.']);
        }

        // The null check is first so the gateway itself names the header it
        // signs with: a scheme is a property of the provider, not of this
        // controller, and hard-coding one here would silently verify nothing
        // the day T-34 is revisited.
        if ($gateway === null || ! $gateway->verifySignature(
            $request->getContent(),
            $request->header($gateway->signatureHeader()),
        )) {
            $log->update([
                'processed_at' => now(),
                'processing_error' => 'Invalid or missing signature.',
            ]);

            return Response::json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $log->update(['signature_valid' => true]);

        $event = $gateway->parseEvent($request->all());

        if ($event === null) {
            // Recognised as ours, but not an event that moves money. Recorded as
            // unhandled rather than guessed at - a misread gateway event
            // rewrites the ledger, and BR-PAY-02 is not a place to improvise.
            $log->update(['processed_at' => now(), 'processing_error' => 'Unhandled event type.']);

            return Response::json(['success' => true, 'message' => 'No action.']);
        }

        $outcome = $links->applyEvent($event, $gateway);

        $log->update([
            'processed_at' => now(),
            'processing_error' => match ($outcome) {
                PaymentLinkService::OUTCOME_APPLIED => null,
                PaymentLinkService::OUTCOME_DUPLICATE => 'Already applied.',
                PaymentLinkService::OUTCOME_UNKNOWN_LINK => 'No matching payment link.',
                default => 'Refused by the payment status matrix.',
            },
        ]);

        // Always 200 once the signature is good. A gateway that gets an error
        // retries for hours over a state we have already decided about, and a
        // retry storm is how a "no matching link" becomes an outage.
        return Response::json([
            'success' => true,
            'message' => $outcome === PaymentLinkService::OUTCOME_APPLIED ? 'Processed.' : 'No action.',
        ]);
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

    /**
     * The inbound text channels that carry a STOP reply. Email opts out through
     * its own unsubscribe event, and a call is not a text - so this is SMS,
     * WhatsApp and RCS only, never a channel where a typed keyword is meaningless.
     */
    private function inboundChannel(Request $request): ?Channel
    {
        $channel = Channel::tryFrom((string) $request->input('channel', ''));

        return in_array($channel, [Channel::Sms, Channel::WhatsApp, Channel::Rcs], true)
            ? $channel
            : null;
    }

    /**
     * Shared-secret check for the webhooks whose provider signing scheme is not
     * yet documented (inbound keywords, Vaaad - T-53). Constant-time, so a wrong
     * secret cannot be found by timing responses, and a missing secret means the
     * endpoint is out of service rather than open.
     */
    private function sharedSecretIsValid(Request $request, string $settingKey): bool
    {
        $expected = $this->settings->get($settingKey);

        if (! is_string($expected) || $expected === '') {
            return false;
        }

        $provided = (string) ($request->header('X-Webhook-Token') ?? $request->input('token', ''));

        return hash_equals($expected, $provided);
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
