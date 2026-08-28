<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Models\ProviderWebhookLog;
use App\Services\Messaging\DeliveryStatusService;
use App\Services\Settings\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * WhatsApp Cloud API callbacks (Phase 14, FR-WA-01, FR-COMM-03, SEC-WH-01..05).
 *
 * `providers.whatsapp.webhook_verify_token` and `providers.whatsapp.app_secret`
 * were declared in config, documented in `.env.example`/DEPLOYMENT.md and offered
 * as Super-Admin credential fields, but no code read either one: the only way a
 * WhatsApp receipt could arrive was the generic `/webhooks/delivery` endpoint,
 * which authenticates with an `X-Webhook-Token` shared secret. That works for a
 * BSP, which can be told to send an arbitrary header - but `WhatsAppDriver` sends
 * against the **Meta Cloud API direct**, and Meta cannot be configured to send
 * that header. It signs with `X-Hub-Signature-256` and answers a `hub.*` GET
 * handshake. So on the exact shape the driver implements there was no reachable
 * return path at all, and two documented credentials that did nothing.
 *
 * This is deliberately the same scheme as `MetaWebhookController` rather than a
 * new one - it is the same vendor and the same contract - and it does no
 * privileged work inline (SEC-WH-05): it verifies, logs the raw delivery, hands
 * each status to `DeliveryStatusService` (the one place that decides what a
 * provider's word means to a message), and acknowledges.
 *
 * Scope is status receipts only. Inbound message bodies are recorded and NOT
 * acted on - storing conversations is unbuilt work, not something to improvise
 * inside a webhook (see the FR-WA-01 gap list).
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * The status Meta reports when it has accepted the message - which is the
     * same fact `WhatsAppDriver::send()` already recorded when the Cloud API
     * returned the wamid. Acknowledged as a no-op rather than passed on, so a
     * receipt that tells us nothing new is not filed as an unrecognised event.
     */
    private const ALREADY_KNOWN_STATUSES = ['sent', 'accepted'];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Subscription verification (SEC-WH-02).
     *
     * Meta calls this once when the webhook is registered and expects the
     * challenge echoed back as bare text - not JSON, not the envelope, or the
     * subscription fails. The query keys are `hub_*` because PHP rewrites the
     * dots in Meta's `hub.mode` / `hub.verify_token` / `hub.challenge`.
     */
    public function verify(Request $request): Response
    {
        $expected = $this->settings->get('providers.whatsapp.webhook_verify_token');

        $matches = is_string($expected)
            && $expected !== ''
            && hash_equals($expected, (string) $request->query('hub_verify_token'));

        if ($request->query('hub_mode') !== 'subscribe' || ! $matches) {
            return new Response('Verification failed.', SymfonyResponse::HTTP_FORBIDDEN);
        }

        return new Response((string) $request->query('hub_challenge'), SymfonyResponse::HTTP_OK);
    }

    /**
     * Delivery and read receipts.
     *
     * One POST can carry several statuses across several messages, so each gets
     * its own log row keyed on its own `(wamid, status)`. That is what makes
     * replay protection exact (SEC-WH-03): a redelivery is absorbed by the
     * unique index whatever envelope it arrives in, while the three statuses of
     * one message - sent, delivered, read - stay distinct events.
     */
    public function receive(Request $request, DeliveryStatusService $statuses): Response
    {
        // Verified BEFORE anything is parsed as business data (SEC-WH-01).
        if (! $this->signatureIsValid($request)) {
            ProviderWebhookLog::create([
                'provider' => 'whatsapp',
                'event_type' => 'rejected',
                'payload' => $this->redact($request->all()),
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => false,
                'processed_at' => now(),
                'processing_error' => 'Invalid or missing X-Hub-Signature-256.',
            ]);

            return new Response('Invalid signature.', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        foreach ($this->statusEvents($request) as $event) {
            $this->ingestStatus($request, $event, $statuses);
        }

        $this->recordInboundMessages($request);

        // ACK means received, not processed (API_DOCUMENTATION §10). Meta
        // retries anything that is not a 200 for hours, and a retry storm over a
        // receipt we have already decided about is how this becomes an outage.
        return new Response('', SymfonyResponse::HTTP_OK);
    }

    /**
     * Logs one receipt, then hands it to the service that decides what the word
     * means. The log row is written FIRST so a bug here can be fixed and the
     * event replayed rather than the delivery being lost.
     *
     * `event_type` is truncated because the column is varchar(100) under strict
     * mode and the value comes from outside: an over-long one would 500 the
     * endpoint and make Meta retry the whole delivery for hours.
     *
     * @param  array{wamid: string, status: string, recipient_id: string|null, error: string|null, raw: array<string, mixed>}  $event
     */
    private function ingestStatus(Request $request, array $event, DeliveryStatusService $statuses): void
    {
        try {
            $log = ProviderWebhookLog::create([
                'provider' => 'whatsapp',
                'event_type' => mb_substr($event['status'], 0, 100),
                // Keyed on the message AND the status: one wamid legitimately
                // reports three times, so keying on the wamid alone would
                // swallow `delivered` and `read` as replays of `sent`.
                'provider_event_id' => mb_substr('status:'.$event['wamid'].':'.$event['status'], 0, 190),
                'payload' => $event['raw'],
                // Only what identifies the caller. The full header bag carries
                // the signature, and this row is long-lived (SEC-CFG-05).
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already seen (SEC-WH-03).
            return;
        }

        if (in_array($event['status'], self::ALREADY_KNOWN_STATUSES, true)) {
            $log->update(['processed_at' => now(), 'processing_error' => 'Already recorded at send time.']);

            return;
        }

        $message = $statuses->resolve(Channel::WhatsApp, null, $event['wamid']);

        if ($message === null) {
            // Usually a receipt for another environment sharing the WhatsApp
            // number. Acknowledged, not errored - making Meta retry it for ever
            // helps nobody.
            $log->update(['processed_at' => now(), 'processing_error' => 'No matching message.']);

            return;
        }

        $applied = $statuses->ingest($message, $event['status'], $this->detailFor($event));

        $log->update([
            'processed_at' => now(),
            // An unrecognised status is recorded as such rather than guessed at -
            // that is how a failure silently becomes a delivery (FR-COMM-03).
            'processing_error' => $applied ? null : 'Unrecognised status.',
        ]);
    }

    /**
     * The payload handed to `DeliveryStatusService`, carrying Meta's own words
     * under `error` ONLY.
     *
     * That key is deliberate. `isPermanentFailure()` scans `error_code`,
     * `failure_type`, `type`, `reason`, `description` and `status` for words like
     * "invalid" and permanently suppresses the lead across every phone channel
     * when it finds one - and Meta puts "Invalid parameter" (our own malformed
     * request) in the same field as a genuinely dead number. Lifting a
     * suppression needs Manager+ (BR-DNC-06), so a WhatsApp failure records the
     * reason and never infers a dead handset from it.
     *
     * @param  array{wamid: string, status: string, recipient_id: string|null, error: string|null, raw: array<string, mixed>}  $event
     * @return array<string, mixed>
     */
    private function detailFor(array $event): array
    {
        return $event['error'] === null ? [] : ['error' => $event['error']];
    }

    /**
     * Flattens Meta's entry/changes/value nesting into the status receipts we
     * act on, ignoring subscriptions to other fields on the same app.
     *
     * @return array<int, array{wamid: string, status: string, recipient_id: string|null, error: string|null, raw: array<string, mixed>}>
     */
    private function statusEvents(Request $request): array
    {
        $events = [];

        foreach ($this->messageValues($request) as $value) {
            foreach ((array) ($value['statuses'] ?? []) as $status) {
                if (! is_array($status) || ! is_scalar($status['id'] ?? null) || ! is_scalar($status['status'] ?? null)) {
                    continue;
                }

                $events[] = [
                    'wamid' => (string) $status['id'],
                    'status' => strtolower(trim((string) $status['status'])),
                    'recipient_id' => is_scalar($status['recipient_id'] ?? null)
                        ? (string) $status['recipient_id']
                        : null,
                    'error' => $this->errorText($status),
                    'raw' => $status,
                ];
            }
        }

        return $events;
    }

    /**
     * Meta's failure detail, as `code title` - both are scalars, and a nested
     * array cast to string is a fatal error, which on an endpoint anyone can
     * POST to is a way to 500 us at will.
     *
     * @param  array<string, mixed>  $status
     */
    private function errorText(array $status): ?string
    {
        $error = ((array) ($status['errors'] ?? []))[0] ?? null;

        if (! is_array($error)) {
            return null;
        }

        $parts = array_filter(
            [$error['code'] ?? null, $error['title'] ?? null, $error['message'] ?? null],
            fn ($part) => is_scalar($part) && trim((string) $part) !== '',
        );

        $text = trim(implode(' ', array_map(fn ($part) => trim((string) $part), $parts)));

        return $text === '' ? null : mb_substr($text, 0, 190);
    }

    /**
     * Inbound replies are OUT OF SCOPE, and are logged as unhandled rather than
     * dropped (FR-WA-01 gap, SEC-WH-04).
     *
     * The row exists so "the customer says they replied" is answerable and so the
     * delivery is replayable the day conversations are actually built - the whole
     * reason payloads are stored before processing. Nothing is written against
     * the lead and no opt-out is honoured here: the STOP path is
     * `/webhooks/inbound`, and quietly growing a second one inside a delivery
     * webhook is how a channel ends up with two suppression implementations
     * (ADR-E).
     */
    private function recordInboundMessages(Request $request): void
    {
        foreach ($this->messageValues($request) as $value) {
            foreach ((array) ($value['messages'] ?? []) as $inbound) {
                if (! is_array($inbound) || ! is_scalar($inbound['id'] ?? null)) {
                    continue;
                }

                try {
                    ProviderWebhookLog::create([
                        'provider' => 'whatsapp',
                        'event_type' => 'inbound',
                        'provider_event_id' => mb_substr('inbound:'.$inbound['id'], 0, 190),
                        'payload' => $inbound,
                        'headers' => ['user-agent' => $request->userAgent()],
                        'signature_valid' => true,
                        'processed_at' => now(),
                        'processing_error' => 'Inbound replies are not implemented (FR-WA-01).',
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Already seen (SEC-WH-03).
                }
            }
        }
    }

    /**
     * The `value` bag of every `messages`-field change in the delivery.
     *
     * @return array<int, array<string, mixed>>
     */
    private function messageValues(Request $request): array
    {
        $values = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) (is_array($entry) ? ($entry['changes'] ?? []) : []) as $change) {
                if (! is_array($change) || ($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                if (is_array($change['value'] ?? null)) {
                    $values[] = $change['value'];
                }
            }
        }

        return $values;
    }

    /**
     * HMAC-SHA256 over the RAW body (SEC-WH-02), exactly as Meta signs it.
     *
     * Raw, not the parsed array: re-encoding changes key order and whitespace and
     * the digest would never match. This is the whole protection on an endpoint
     * anyone can POST to.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = $this->settings->get('providers.whatsapp.app_secret');

        // Unconfigured means not in service, as on every other webhook here. A
        // receipt endpoint that accepts everything can mark any message read and
        // fail any message - so a fresh install refuses rather than opens.
        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * A rejected payload is still stored for audit (SEC-WH-04), but a forged body
     * is attacker-controlled content we are about to keep - so only the
     * structural fields are retained, not whatever they chose to send.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        return [
            'object' => is_scalar($payload['object'] ?? null) ? $payload['object'] : null,
            'entry_count' => is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0,
        ];
    }
}
