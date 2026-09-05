<?php

namespace Tests\Feature\Webhooks;

use App\Enums\Channel;
use App\Enums\InterestSignalType;
use App\Enums\RoleName;
use App\Models\DncEntry;
use App\Models\InterestSignal;
use App\Models\Lead;
use App\Models\Message;
use App\Models\ProviderWebhookLog;
use App\Models\Role;
use App\Models\User;
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
    // Inbound replies (FR-WA-01)
    // -----------------------------------------------------------------------

    /** One `messages` entry wrapped in Meta's entry/changes/value nesting. */
    private function inboundPayload(array $inbound): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => ['messages' => [$inbound]],
                ]],
            ]],
        ];
    }

    #[Test]
    public function an_inbound_reply_is_stored_against_the_lead_and_appears_in_its_message_history(): void
    {
        $this->configureWhatsApp();
        $this->actingAsRole();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->deliver($this->inboundPayload([
            'id' => 'wamid.inbound-1',
            'from' => '919876543210',
            'type' => 'text',
            'timestamp' => (string) Carbon::parse('2026-08-10 10:45:00', 'Asia/Kolkata')->timestamp,
            'text' => ['body' => 'Yes, please send the brochure.'],
        ]))->assertOk();

        $message = Message::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('whatsapp', $message->channel->value);
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('wamid.inbound-1', $message->provider_message_id);
        $this->assertSame('Yes, please send the brochure.', $message->body);
        // The moment Meta says the LEAD sent it, not the moment the webhook ran -
        // WhatsAppDriver's window check reads this column.
        $this->assertTrue(Carbon::parse('2026-08-10 10:45:00', 'Asia/Kolkata')->equalTo($message->sent_at));

        $log = ProviderWebhookLog::where('event_type', 'inbound')->firstOrFail();
        $this->assertNull($log->processing_error);

        // The exact screen this exists for: the lead's Messages tab.
        $this->getJson("/api/v1/leads/{$lead->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.items.0.direction', 'inbound')
            ->assertJsonPath('data.items.0.body', 'Yes, please send the brochure.');
    }

    #[Test]
    public function an_inbound_reply_is_scored_as_interest_through_the_shared_engine(): void
    {
        // BR-INT-01: every channel routes interest through one engine. FR-WA-01
        // wiring this up means the InboundReply signal that already existed
        // (BR-SCORE-01: +15) finally fires for a real WhatsApp reply.
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->deliver($this->inboundPayload([
            'id' => 'wamid.inbound-2',
            'from' => '919876543210',
            'type' => 'text',
            'text' => ['body' => 'Sounds good, go ahead.'],
        ]))->assertOk();

        $message = Message::where('lead_id', $lead->id)->firstOrFail();

        $this->assertDatabaseHas('interest_signals', [
            'lead_id' => $lead->id,
            'type' => InterestSignalType::InboundReply->value,
            'channel' => Channel::WhatsApp->value,
            'evidence_type' => Message::class,
            'evidence_id' => $message->id,
        ]);
        $this->assertGreaterThan(0, $lead->fresh()->score);
    }

    #[Test]
    public function a_non_text_inbound_message_still_becomes_a_readable_row(): void
    {
        // A customer who sends a photo must not look like they never replied -
        // the media itself is not fetched, but the row exists.
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->deliver($this->inboundPayload([
            'id' => 'wamid.inbound-3',
            'from' => '919876543210',
            'type' => 'image',
            'image' => ['id' => 'media-1'],
        ]))->assertOk();

        $message = Message::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('[image message]', $message->body);
    }

    #[Test]
    public function a_reply_from_an_unrecognised_number_is_acknowledged_and_stores_nothing(): void
    {
        $this->configureWhatsApp();

        $this->deliver($this->inboundPayload([
            'id' => 'wamid.inbound-4',
            'from' => '919999999999',
            'type' => 'text',
            'text' => ['body' => 'Hello?'],
        ]))->assertOk();

        $log = ProviderWebhookLog::where('event_type', 'inbound')->firstOrFail();
        $this->assertSame('No matching lead.', $log->processing_error);
        $this->assertSame(0, Message::count());
    }

    #[Test]
    public function a_redelivered_inbound_reply_does_not_file_or_score_twice(): void
    {
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);
        $payload = $this->inboundPayload([
            'id' => 'wamid.inbound-5',
            'from' => '919876543210',
            'type' => 'text',
            'text' => ['body' => 'Repeat me not.'],
        ]);

        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertOk();

        $this->assertSame(1, Message::where('lead_id', $lead->id)->count());
        $this->assertSame(
            1,
            InterestSignal::where('lead_id', $lead->id)
                ->where('type', InterestSignalType::InboundReply->value)
                ->count(),
        );
    }

    #[Test]
    public function an_inbound_stop_keyword_is_left_to_the_separate_opt_out_path(): void
    {
        // The DNC STOP-keyword path is /webhooks/inbound (WebhookController),
        // matched on the first word - not duplicated here. This endpoint must
        // not file a STOP as a conversation message or score it as +15
        // interest, and must not suppress the lead itself (ADR-E).
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->deliver($this->inboundPayload([
            'id' => 'wamid.inbound-stop',
            'from' => '919876543210',
            'type' => 'text',
            'text' => ['body' => 'STOP'],
        ]))->assertOk();

        $this->assertSame(0, Message::where('lead_id', $lead->id)->count());
        $this->assertSame(0, DncEntry::where('lead_id', $lead->id)->count());

        $log = ProviderWebhookLog::where('event_type', 'inbound')->firstOrFail();
        $this->assertStringContainsString('/webhooks/inbound', (string) $log->processing_error);

        // A STOP buried mid-sentence is not a command, exactly as the shared
        // keyword matcher defines it - so this one still gets filed normally.
        $this->deliver($this->inboundPayload([
            'id' => 'wamid.inbound-not-stop',
            'from' => '919876543210',
            'type' => 'text',
            'text' => ['body' => "please don't stop the offers"],
        ]))->assertOk();

        $this->assertSame(1, Message::where('lead_id', $lead->id)->count());
    }

    #[Test]
    public function a_malformed_inbound_message_cannot_crash_the_endpoint(): void
    {
        $this->configureWhatsApp();

        foreach ([
            ['entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => 'not-an-array']]]]]],
            ['entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => [['id' => ['nested']]]]]]]]],
            ['entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => [['id' => 'w1', 'from' => ['nested']]]]]]]]],
            ['entry' => [['changes' => [['field' => 'messages', 'value' => ['messages' => [['id' => 'w1', 'from' => '919876543210', 'text' => 'not-an-array']]]]]]]],
        ] as $payload) {
            $this->deliver($payload)->assertOk();
        }
    }

    private function actingAsRole(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Admin->value)->first());
        $this->actingAs($user->fresh(), 'sanctum');
    }
}
