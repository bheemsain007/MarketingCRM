<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaLead;
use App\Models\ProviderWebhookLog;
use App\Services\Settings\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Facebook / Instagram Lead Ads (Phase 12, FR-META-01..03, SEC-WH-01..05).
 *
 * Two endpoints on one path, as Meta requires: a GET that answers the
 * subscription challenge, and a POST that receives leadgen notifications.
 *
 * SEC-WH-05 is the shape of this class: the endpoint is unauthenticated by
 * necessity, so it does NO privileged work inline. It verifies, persists the
 * raw delivery, enqueues, and acknowledges. Creating a lead - which means
 * hitting the Graph API, running duplicate detection and auto-assignment -
 * happens in a job where a failure can be retried and cannot hold Meta's
 * connection open.
 */
class MetaWebhookController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Subscription verification (SEC-WH-02).
     *
     * Meta calls this once when the webhook is registered and expects the
     * challenge echoed back as bare text - not JSON, not wrapped in the
     * envelope, or the subscription fails.
     */
    public function verify(Request $request): Response
    {
        $expected = $this->settings->get('providers.meta.verify_token');

        $matches = is_string($expected)
            && $expected !== ''
            && hash_equals($expected, (string) $request->query('hub_verify_token'));

        if ($request->query('hub_mode') !== 'subscribe' || ! $matches) {
            return new Response('Verification failed.', SymfonyResponse::HTTP_FORBIDDEN);
        }

        return new Response((string) $request->query('hub_challenge'), SymfonyResponse::HTTP_OK);
    }

    /**
     * Leadgen delivery.
     *
     * A single POST can carry many leads across several pages, so each leadgen
     * gets its own log row keyed on its own id. That is what makes replay
     * protection exact (SEC-WH-03): re-delivering one lead is absorbed by the
     * unique index whatever envelope it arrives in, and a delivery containing
     * one new lead and one repeat processes only the new one.
     */
    public function receive(Request $request): Response
    {
        // Verified BEFORE anything is parsed as business data (SEC-WH-01).
        if (! $this->signatureIsValid($request)) {
            ProviderWebhookLog::create([
                'provider' => 'meta',
                'event_type' => 'rejected',
                'payload' => $this->redact($request->all()),
                'headers' => ['user-agent' => $request->userAgent()],
                'signature_valid' => false,
                'processed_at' => now(),
                'processing_error' => 'Invalid or missing X-Hub-Signature-256.',
            ]);

            return new Response('Invalid signature.', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        foreach ($this->leadgenEvents($request) as $event) {
            try {
                $log = ProviderWebhookLog::create([
                    'provider' => 'meta',
                    'event_type' => 'leadgen',
                    // Keyed on the leadgen, not the delivery.
                    'provider_event_id' => 'leadgen:'.$event['leadgen_id'],
                    'payload' => $event,
                    'headers' => ['user-agent' => $request->userAgent()],
                    'signature_valid' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Already seen. FR-META-03: a replayed delivery creates no
                // duplicate lead, enforced by the database rather than a check
                // a race could defeat.
                continue;
            }

            ProcessMetaLead::dispatch($log->id)->onQueue('webhooks');
        }

        // ACK means received, not processed (API_DOCUMENTATION §10).
        return new Response('', SymfonyResponse::HTTP_OK);
    }

    /**
     * Flattens Meta's entry/changes nesting into the leadgen events we care
     * about, ignoring subscriptions to other fields on the same app.
     *
     * @return array<int, array<string, mixed>>
     */
    private function leadgenEvents(Request $request): array
    {
        $events = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                if (($change['field'] ?? null) !== 'leadgen' || empty($value['leadgen_id'])) {
                    continue;
                }

                $events[] = [
                    'leadgen_id' => (string) $value['leadgen_id'],
                    'form_id' => isset($value['form_id']) ? (string) $value['form_id'] : null,
                    'page_id' => isset($value['page_id']) ? (string) $value['page_id'] : null,
                    'ad_id' => isset($value['ad_id']) ? (string) $value['ad_id'] : null,
                    // Meta sends this on Instagram-sourced leads; absent means
                    // Facebook.
                    'platform' => (string) ($value['platform'] ?? 'facebook'),
                    'created_time' => $value['created_time'] ?? null,
                ];
            }
        }

        return $events;
    }

    /**
     * HMAC-SHA256 over the RAW body (SEC-WH-02).
     *
     * Raw, not the parsed array: re-encoding changes key order and whitespace,
     * and the digest would never match. This is the whole protection on an
     * endpoint anyone can POST to.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = $this->settings->get('providers.meta.app_secret');

        // Unconfigured means not in service. Accepting everything in that
        // state would make a fresh install an open lead-injection endpoint.
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
     * A rejected payload is still stored for audit (SEC-WH-04), but a forged
     * body is attacker-controlled content we are about to keep - so only the
     * structural fields are retained, not whatever they chose to send.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        return [
            'object' => $payload['object'] ?? null,
            'entry_count' => is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0,
        ];
    }
}
