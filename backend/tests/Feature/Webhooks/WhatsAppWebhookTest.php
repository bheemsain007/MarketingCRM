<?php

namespace Tests\Feature\Webhooks;

use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Message;
use App\Models\ProviderWebhookLog;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WhatsApp Cloud API callbacks (Phase 14, FR-WA-01, FR-COMM-03, SEC-WH-01..05).
 *
 * `providers.whatsapp.app_secret` and `providers.whatsapp.webhook_verify_token`
 * were declared, documented and offered in the settings screen while no code
 * read either: the Cloud API shape `WhatsAppDriver` sends against had no
 * reachable return path, because the generic `/webhooks/delivery` endpoint
 * authenticates with a header Meta cannot be told to send.
 *
 * The signature tests are the load-bearing ones. This endpoint is open to the
 * internet and can mark any message read or failed; without them, anyone who
 * finds the URL can rewrite delivery history in a paying customer's CRM.
 *
 * Time is frozen: these assertions compare timestamps, and a test that only
 * agrees with itself within the same second is a flake waiting to happen.
 */
class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'whatsapp-app-secret-value';

    private const VERIFY_TOKEN = 'whatsapp-verify-token-value';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configureWhatsApp(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.whatsapp.app_secret', self::APP_SECRET);
        $settings->set('providers.whatsapp.webhook_verify_token', self::VERIFY_TOKEN);
    }

    /** One `statuses` entry wrapped in Meta's entry/changes/value nesting. */
    private function statusPayload(string $wamid, string $status, array $extra = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => array_merge([
                        'messaging_product' => 'whatsapp',
                        'statuses' => [array_merge([
                            'id' => $wamid,
                            'status' => $status,
                            'timestamp' => '1786000000',
                            'recipient_id' => '919876543210',
                        ], $extra)],
                    ], []),
                ]],
            ]],
        ];
    }

    /** Signs exactly as Meta does: HMAC-SHA256 over the raw body. */
    private function deliver(array $payload, ?string $secret = self::APP_SECRET)
    {
        $raw = json_encode($payload);

        // Server vars rather than withHeaders(): `call()` does not apply the
        // default header bag, and the signature has to be computed over this
        // exact byte sequence.
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], $server, $raw);
    }

    private function message(array $attributes = []): Message
    {
        $lead = Lead::factory()->create();

        return Message::factory()->create(array_merge([
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'status' => 'sent',
            'provider' => 'whatsapp_cloud',
            'provider_message_id' => 'wamid.'.Str::random(12),
            'recipient' => $lead->phone_e164,
            'sent_at' => now()->subMinutes(5),
        ], $attributes));
    }

    // -----------------------------------------------------------------------
    // Subscription verification (SEC-WH-02)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_subscription_challenge_is_echoed_back_as_bare_text(): void
    {
        $this->configureWhatsApp();

        // Not JSON and not the envelope - Meta rejects the subscription if the
        // body is anything but the challenge itself.
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token='
            .self::VERIFY_TOKEN.'&hub_challenge=wa-challenge-123')
            ->assertOk()
            ->assertSee('wa-challenge-123');
    }

    #[Test]
    public function a_wrong_verify_token_does_not_complete_the_subscription(): void
    {
        $this->configureWhatsApp();

        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=guess&hub_challenge=abc')
            ->assertStatus(403);
    }

    #[Test]
    public function the_handshake_is_refused_while_no_verify_token_is_configured(): void
    {
        // Nothing configured. Echoing the challenge back to anyone who asks
        // would let a stranger subscribe their own app to this endpoint.
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=&hub_challenge=abc')
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Hostile input (SEC-WH-01/04)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_forged_signature_is_refused_and_changes_nothing(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver(
            $this->statusPayload($message->provider_message_id, 'delivered'),
            secret: 'attacker-guess',
        )->assertStatus(401);

        // The whole point: an attacker who finds the URL cannot rewrite delivery
        // history.
        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertNull($message->delivered_at);
    }

    #[Test]
    public function a_request_with_no_signature_header_at_all_is_refused(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver($this->statusPayload($message->provider_message_id, 'read'), secret: null)
            ->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function an_unconfigured_app_secret_refuses_everything(): void
    {
        // No secret set. A receipt endpoint that accepts everything can mark any
        // message read and fail any message, so a fresh install refuses rather
        // than opens (SEC-CFG-04).
        $message = $this->message();

        $this->deliver($this->statusPayload($message->provider_message_id, 'delivered'))
            ->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function a_rejected_delivery_is_logged_without_the_attackers_payload(): void
    {
        $this->configureWhatsApp();

        $this->deliver(
            $this->statusPayload('wamid.forged', 'delivered', ['note' => 'attacker-controlled-text']),
            secret: 'wrong',
        )->assertStatus(401);

        // A forged call is exactly the one worth having a record of (SEC-WH-04),
        // but the body is attacker content we are about to keep, so only the
        // structural fields survive.
        $log = ProviderWebhookLog::where('provider', 'whatsapp')->firstOrFail();
        $this->assertFalse($log->signature_valid);
        $this->assertStringNotContainsString('attacker-controlled-text', json_encode($log->payload));
    }

    #[Test]
    public function the_app_secret_is_never_stored_in_the_webhook_log(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver($this->statusPayload($message->provider_message_id, 'delivered'))->assertOk();

        // The log row is long-lived and read by more people than the settings
        // screen is (SEC-CFG-05).
        $logs = ProviderWebhookLog::all();
        $dump = json_encode([$logs->pluck('headers'), $logs->pluck('payload')]);

        $this->assertStringNotContainsString(self::APP_SECRET, $dump);
    }

    // -----------------------------------------------------------------------
    // A correctly signed receipt is accepted (FR-COMM-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_correctly_signed_delivery_receipt_moves_the_message_off_sent(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver($this->statusPayload($message->provider_message_id, 'delivered'))->assertOk();

        $message->refresh();

        // Before this endpoint existed a Cloud API message sat at `sent` for
        // ever, because Meta cannot present the X-Webhook-Token that
        // /webhooks/delivery requires.
        $this->assertSame('delivered', $message->status);
        $this->assertTrue(now()->equalTo($message->delivered_at));
    }

    #[Test]
    public function a_read_receipt_records_when_the_message_was_seen(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver($this->statusPayload($message->provider_message_id, 'read'))->assertOk();

        $message->refresh();

        $this->assertSame('read', $message->status);
        $this->assertTrue(now()->equalTo($message->read_at));
    }

    #[Test]
    public function a_failed_status_records_metas_own_words_but_never_suppresses_the_lead(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver($this->statusPayload($message->provider_message_id, 'failed', [
            'errors' => [['code' => 131009, 'title' => 'Invalid parameter']],
        ]))->assertOk();

        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('Invalid parameter', (string) $message->failure_reason);

        /*
         * The reason is recorded, never inferred from. Meta puts "Invalid
         * parameter" - our own malformed request - in the same field as a
         * genuinely dead number, and permanent suppression blocks every phone
         * channel and needs Manager+ to lift (BR-DNC-06).
         */
        $this->assertSame(0, DncEntry::where('lead_id', $message->lead_id)->count());
    }

    #[Test]
    public function the_sent_status_is_acknowledged_rather_than_filed_as_unrecognised(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver($this->statusPayload($message->provider_message_id, 'sent'))->assertOk();

        // `sent` is the fact the driver already recorded when the Cloud API
        // returned the wamid; re-reporting it is not new information and must not
        // walk the message backwards from `delivered`.
        $this->assertSame('sent', $message->fresh()->status);

        $log = ProviderWebhookLog::where('event_type', 'sent')->firstOrFail();
        $this->assertSame('Already recorded at send time.', $log->processing_error);
    }

    #[Test]
    public function a_receipt_for_a_message_we_never_sent_is_acknowledged_not_errored(): void
    {
        $this->configureWhatsApp();

        // Usually a receipt for another environment sharing the WhatsApp number.
        // Making Meta retry it for ever helps nobody.
        $this->deliver($this->statusPayload('wamid.unknown-to-us', 'delivered'))->assertOk();

        $log = ProviderWebhookLog::where('provider', 'whatsapp')->firstOrFail();
        $this->assertSame('No matching message.', $log->processing_error);
    }

    #[Test]
    public function a_redelivered_receipt_is_absorbed_but_a_later_status_still_lands(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();
        $wamid = $message->provider_message_id;

        $this->deliver($this->statusPayload($wamid, 'delivered'))->assertOk();
        $this->deliver($this->statusPayload($wamid, 'delivered'))->assertOk();

        // One wamid legitimately reports three times, so the replay guard keys on
        // (wamid, status) - keying on the wamid alone would swallow `read` as a
        // replay of `delivered`.
        $this->assertSame(1, ProviderWebhookLog::where('event_type', 'delivered')->count());

        $this->deliver($this->statusPayload($wamid, 'read'))->assertOk();

        $this->assertSame('read', $message->fresh()->status);
    }

    #[Test]
    public function several_statuses_in_one_delivery_are_each_applied(): void
    {
        $this->configureWhatsApp();
        $first = $this->message();
        $second = $this->message();

        $this->deliver([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'statuses' => [
                            ['id' => $first->provider_message_id, 'status' => 'delivered'],
                            ['id' => $second->provider_message_id, 'status' => 'read'],
                        ],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame('delivered', $first->fresh()->status);
        $this->assertSame('read', $second->fresh()->status);
    }

    #[Test]
    public function a_change_for_another_subscribed_field_is_ignored(): void
    {
        $this->configureWhatsApp();
        $message = $this->message();

        $this->deliver([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    // Same app, different subscription. Nothing here is a receipt.
                    'field' => 'account_review_update',
                    'value' => ['statuses' => [['id' => $message->provider_message_id, 'status' => 'delivered']]],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertSame(0, ProviderWebhookLog::count());
    }

    #[Test]
    public function a_malformed_payload_cannot_crash_the_endpoint(): void
    {
        $this->configureWhatsApp();

        // Every one of these is a shape a hostile caller can send, and a 500 on
        // an open endpoint is a denial-of-service primitive.
        foreach ([
            ['entry' => 'not-an-array'],
            ['entry' => [['changes' => 'not-an-array']]],
            ['entry' => [['changes' => [['field' => 'messages', 'value' => 'not-an-array']]]]],
            ['entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => ['nested']]]]]]]]],
            ['entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => 'w1', 'status' => 'failed', 'errors' => 'nope']]]]]]]],
        ] as $payload) {
            $this->deliver($payload)->assertOk();
        }
    }

    // -----------------------------------------------------------------------
    // Inbound replies are NOT implemented (FR-WA-01 gap)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_inbound_reply_is_recorded_as_unimplemented_and_nothing_is_stored_against_the_lead(): void
    {
        $this->configureWhatsApp();
        $lead = Lead::factory()->create();

        $this->deliver([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.inbound-1',
                            'from' => '919876543210',
                            'type' => 'text',
                            'text' => ['body' => 'Yes please'],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        // Logged so the delivery is replayable the day conversations are built,
        // but no Message row is created - storing conversations is unbuilt work,
        // not something to improvise inside a webhook.
        $log = ProviderWebhookLog::where('event_type', 'inbound')->firstOrFail();
        $this->assertSame('Inbound replies are not implemented (FR-WA-01).', $log->processing_error);
        $this->assertSame(0, Message::where('lead_id', $lead->id)->count());
    }
}
