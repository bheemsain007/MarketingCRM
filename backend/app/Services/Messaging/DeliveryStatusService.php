<?php

namespace App\Services\Messaging;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Models\Message;
use App\Services\Dnc\DncService;

/**
 * Delivery-status ingestion for the non-email channels (FR-COMM-03).
 *
 * Mailercloud could report back and nothing else could, so an SMS, WhatsApp,
 * RCS or Voice message reached `sent` and stayed there for ever: `delivered_at`
 * and `read_at` were unreachable, and a number the carrier says does not exist
 * kept being messaged because nothing could tell us it had failed. Four of the
 * five message channels had a delivery pipeline with no return path.
 *
 * **One vocabulary, not four.** Every provider spells a delivery receipt
 * differently and three of the four vendors are not chosen yet (T-31 WhatsApp
 * BSP, T-32 RCS, T-33 Voice), so writing four vendor-specific parsers would mean
 * writing them against payloads nobody has seen and rewriting them when the
 * vendors are picked. Instead this maps the small set of states the CRM actually
 * has - delivered, read, failed, opted out - onto the words those states are
 * commonly reported under, exactly as the inbound STOP endpoint takes one
 * generic shape across the text channels. A vendor whose word is missing lands
 * in `default` and is RECORDED as unrecognised rather than guessed at, which is
 * the failure mode we want: visible, and one line to fix.
 *
 * The logic lives here rather than in WebhookController because deciding what a
 * provider's word means to a message - and whether it is grounds for permanently
 * suppressing a lead - is business logic, and the controller's job is to
 * authenticate a hostile request and log it.
 */
class DeliveryStatusService
{
    /**
     * The channels this endpoint serves.
     *
     * Email is absent deliberately: it has a signed, provider-specific webhook
     * of its own, and accepting it here would mean the same status could be
     * written by whichever of two secrets an attacker got hold of first. Call
     * and AiCall are absent because they are not messages - an AI call's outcome
     * arrives on the Vaaad webhook.
     */
    private const REPORTING_CHANNELS = [Channel::Sms, Channel::WhatsApp, Channel::Rcs, Channel::Voice];

    /** A voice broadcast that was answered and played is that channel's "delivered". */
    private const DELIVERED_EVENTS = ['delivered', 'delivery', 'delivered_to_handset', 'answered', 'completed'];

    /** WhatsApp and RCS carry a real read receipt; SMS and Voice never will. */
    private const READ_EVENTS = ['read', 'seen', 'displayed'];

    private const FAILED_EVENTS = ['failed', 'failure', 'undelivered', 'rejected', 'expired', 'no_answer', 'busy'];

    /** The recipient telling us to stop, in the words providers report it. */
    private const OPT_OUT_EVENTS = ['blocked', 'block', 'opt_out', 'opted_out', 'unsubscribe', 'complaint', 'spam'];

    /** Events that are a permanent recipient failure by name, with no qualifier needed. */
    private const INVALID_RECIPIENT_EVENTS = ['invalid_number', 'unknown_subscriber', 'number_not_in_service'];

    /**
     * Substrings in a failure payload that mean the number will never work.
     *
     * Note what is NOT here: "absent subscriber", "unreachable", "handset off".
     * Those are a phone in a tunnel, and they resolve themselves.
     */
    private const PERMANENT_MARKERS = ['invalid', 'unknown_subscriber', 'not_in_service', 'unallocated', 'permanent'];

    /**
     * The forward progression of a message's life.
     *
     * Providers do not guarantee ordering and they retry, so a `delivered`
     * receipt can arrive after a `read` one. Ranking the states lets a late
     * event record its fact without walking the message backwards - which would
     * quietly understate engagement in every report that counts reads.
     */
    private const PROGRESSION = ['queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'replied' => 5];

    public function __construct(private readonly DncService $dnc) {}

    /**
     * The channel this callback is about, or null if it is not one we serve.
     *
     * The channel arrives in the body because the vendors are unchosen; that
     * makes it attacker-controlled, which is precisely why it is validated
     * against an allowlist here and then used to SCOPE the message lookup.
     */
    public function channelFor(?string $value): ?Channel
    {
        $channel = Channel::tryFrom(strtolower(trim((string) $value)));

        return in_array($channel, self::REPORTING_CHANNELS, true) ? $channel : null;
    }

    /**
     * Finds the message this event is about, or null.
     *
     * Our own `idempotency_key` first because it is unique across the whole
     * table and unguessable; the provider's id second, since most providers only
     * quote their own. Both lookups are scoped to the channel the caller claimed
     * so that a callback cannot reach a message on a channel this endpoint does
     * not serve.
     *
     * Deliberately NOT scoped by `provider` the way the Mailercloud handler is.
     * Three of these vendors are unchosen and the driver records whatever name
     * the operator configured (`providers.rcs.provider` and friends), so matching
     * on it would silently stop resolving anything the day somebody renames it.
     * The channel is the stable fact.
     */
    public function resolve(Channel $channel, ?string $idempotencyKey, ?string $providerMessageId): ?Message
    {
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $message = Message::where('channel', $channel->value)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($message !== null) {
                return $message;
            }
        }

        if (is_string($providerMessageId) && $providerMessageId !== '') {
            return Message::where('channel', $channel->value)
                ->where('provider_message_id', $providerMessageId)
                ->first();
        }

        return null;
    }

    /**
     * Applies one provider event to one message.
     *
     * @param  array<string, mixed>  $payload
     * @return bool whether the event was recognised
     */
    public function ingest(Message $message, string $event, array $payload): bool
    {
        $event = strtolower(trim($event));
        $permanent = $this->isPermanentFailure($event, $payload);

        if (in_array($event, self::DELIVERED_EVENTS, true)) {
            // `?? now()` rather than `now()`: not every provider sends an event
            // id, so the unique index on the log cannot absorb those
            // redeliveries and the handler has to be idempotent itself.
            // Re-stamping would move the delivery time on every retry.
            $this->apply($message, 'delivered', ['delivered_at' => $message->delivered_at ?? now()]);

            return true;
        }

        if (in_array($event, self::READ_EVENTS, true)) {
            $this->apply($message, 'read', ['read_at' => $message->read_at ?? now()]);

            return true;
        }

        if ($permanent || in_array($event, self::FAILED_EVENTS, true)) {
            $this->apply($message, 'failed', [
                'failed_at' => $message->failed_at ?? now(),
                'failure_reason' => $this->failureReason($event, $payload, $permanent),
            ]);

            if ($permanent) {
                $this->suppressInvalidNumber($message);
            }

            return true;
        }

        if (in_array($event, self::OPT_OUT_EVENTS, true)) {
            $this->apply($message, 'failed', [
                'failed_at' => $message->failed_at ?? now(),
                'failure_reason' => 'Recipient opt-out or block reported by the provider: '.$event,
            ]);

            $this->suppressOptOut($message, $event);

            return true;
        }

        return false;
    }

    /**
     * Writes the change, refusing only a BACKWARDS move along the progression.
     *
     * The timestamps are always recorded - a late delivery receipt is still true
     * - but it may not demote a message that has since been read.
     *
     * @param  array<string, mixed>  $changes
     */
    private function apply(Message $message, string $status, array $changes): void
    {
        if ($this->advances($message->status, $status)) {
            $changes['status'] = $status;
        }

        $message->update($changes);
    }

    private function advances(?string $current, string $next): bool
    {
        $from = self::PROGRESSION[(string) $current] ?? null;
        $to = self::PROGRESSION[$next] ?? null;

        // A status outside the progression - `failed`, `skipped` - is not a step
        // along it, so ordering says nothing and the provider's report stands.
        if ($from === null || $to === null) {
            return true;
        }

        return $to > $from;
    }

    /**
     * Whether the provider is reporting a failure that will never resolve.
     *
     * The same asymmetry the email bounce handling is built on, and it matters
     * more here. Failing to suppress a dead number costs us the price of
     * messages nobody receives; suppressing a live one permanently stops contact
     * with a real customer and lifting it needs Manager+ (BR-DNC-06). So an
     * unqualified `failed` never suppresses - the payload has to actually say
     * the recipient does not exist.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isPermanentFailure(string $event, array $payload): bool
    {
        if (in_array($event, self::INVALID_RECIPIENT_EVENTS, true)) {
            return true;
        }

        if (! in_array($event, self::FAILED_EVENTS, true)) {
            return false;
        }

        $signal = strtolower($this->detail($payload, ['error_code', 'failure_type', 'type', 'reason', 'description', 'status']));

        foreach (self::PERMANENT_MARKERS as $marker) {
            if (str_contains($signal, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the provider's own words. "Failed" with no reason is the state that
     * makes a support ticket unanswerable six weeks later.
     *
     * Truncated because `messages.failure_reason` is varchar(255) under MySQL
     * strict mode and this text comes from outside: an over-long value would
     * turn a delivery receipt into a 500 and make the provider retry it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function failureReason(string $event, array $payload, bool $permanent): string
    {
        $detail = $this->detail($payload, ['reason', 'error', 'error_code', 'description']);

        return mb_substr(trim(sprintf(
            '%s reported by the provider (%s).%s',
            $permanent
                ? 'Permanent delivery failure; recipient suppressed'
                : 'Delivery failure, not confirmed permanent - lead left contactable',
            $event,
            $detail === '' ? '' : ' '.$detail,
        )), 0, 255);
    }

    /**
     * First non-empty scalar among the given keys, space-joined.
     *
     * Scalars only: a payload is hostile input and `(string)` on a nested array
     * is a fatal error, which would hand an attacker a way to 500 the endpoint.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function detail(array $payload, array $keys): string
    {
        $parts = [];

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }

        return implode(' ', $parts);
    }

    /**
     * A number that does not exist is not going to start existing (BR-DNC-07).
     *
     * Channel is left NULL so the REASON decides what is blocked (BR-DNC-02):
     * `InvalidNumber` stops every phone channel and leaves email alone, which is
     * right - a dead handset says nothing about the mailbox.
     *
     * Routed through DncService, never writing `dnc_entries` here. A controller
     * or a channel module keeping its own suppression list is exactly the
     * per-module DNC that ADR-E exists to prevent, and `suppress()` is
     * idempotent, so a provider redelivering cannot stack rows.
     */
    private function suppressInvalidNumber(Message $message): void
    {
        $lead = $message->lead;

        if ($lead === null) {
            return;
        }

        $this->dnc->suppress(
            $lead,
            DncReason::InvalidNumber,
            channel: null,
            source: 'webhook',
            actorId: null,
            note: sprintf(
                'Provider reported the number as permanently undeliverable for message #%d (%s).',
                $message->id,
                $message->channel->label(),
            ),
        );
    }

    /**
     * Someone who blocks us has told us to stop, and honouring it is not
     * optional (BR-DNC-05/07).
     *
     * Scoped to the channel it arrived on, for the same reason an email
     * unsubscribe is scoped to email: "stop messaging me here" is not "never
     * call me". `OptedOut` is an absolute reason, so a null channel would
     * silence every channel at once off a signal that said far less than that.
     * A lead who wants no contact at all is `DoNotContact`, a deliberate act.
     */
    private function suppressOptOut(Message $message, string $event): void
    {
        $lead = $message->lead;

        if ($lead === null) {
            return;
        }

        $this->dnc->suppress(
            $lead,
            DncReason::OptedOut,
            channel: $message->channel,
            source: 'webhook',
            actorId: null,
            note: sprintf('Provider reported "%s" for message #%d.', $event, $message->id),
        );
    }
}
