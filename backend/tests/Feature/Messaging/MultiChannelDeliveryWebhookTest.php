<?php

namespace Tests\Feature\Messaging;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Dnc\DncService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Delivery status on the non-email channels (FR-COMM-03).
 *
 * Only Mailercloud could ever report back, so a message sent over SMS, WhatsApp,
 * RCS or Voice reached `sent` and stopped there for ever: `delivered_at` and
 * `read_at` were unreachable, and a number the carrier says does not exist went
 * on being messaged for ever because nothing could tell us it had failed. Four
 * of the five message channels had a delivery pipeline with no return path.
 *
 * The endpoint is unauthenticated by necessity, so - exactly as for Mailercloud -
 * what is worth testing is that it treats its input as hostile: forged calls
 * refused, unknown ids acknowledged rather than acted on, redeliveries absorbed,
 * unrecognised events recorded rather than guessed at, and suppression only ever
 * on a signal that actually says the failure is permanent.
 *
 * Time is frozen: these assertions compare timestamps, and a test that only
 * agrees with itself within the same second is a flake waiting to happen.
 */
class MultiChannelDeliveryWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'delivery-webhook-secret';

    /** Every message channel that is not email - the whole point of the bug. */
    private const REPORTING_CHANNELS = ['sms', 'whatsapp', 'rcs', 'voice'];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configureSecret(): void
    {
        app(SettingsService::class)->set('providers.delivery.webhook_secret', self::SECRET);
    }

    private function callHook(array $payload, ?string $token = self::SECRET)
    {
        return $this->withHeaders($token === null ? [] : ['X-Webhook-Token' => $token])
            ->postJson('/api/v1/webhooks/delivery', $payload);
    }

    private function message(string $channel = 'sms', array $attributes = []): Message
    {
        $lead = Lead::factory()->create(['email' => 'reachable@example.test']);

        return Message::factory()->create(array_merge([
            'lead_id' => $lead->id,
            'channel' => $channel,
            'status' => 'sent',
            'provider' => 'bhashsms',
            'provider_message_id' => 'p-'.Str::random(10),
            'recipient' => $lead->phone_e164,
            'sent_at' => now()->subMinutes(5),
        ], $attributes));
    }

    // -----------------------------------------------------------------------
    // Hostile input (SEC-WH-*)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_request_without_the_shared_secret_is_refused(): void
    {
        $this->configureSecret();
        $message = $this->message();

        $this->callHook([
            'channel' => 'sms',
            'event' => 'delivered',
            'custom_id' => $message->idempotency_key,
        ], token: null)->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function a_request_with_the_wrong_secret_is_refused(): void
    {
        $this->configureSecret();
        $message = $this->message();

        $this->callHook([
            'channel' => 'sms',
            'event' => 'delivered',
            'custom_id' => $message->idempotency_key,
        ], token: 'guess')->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function an_unconfigured_endpoint_refuses_everything(): void
    {
        // No secret set. An open write endpoint that can mark any message
        // delivered - or suppress any lead - is not an acceptable default state
        // for a fresh install (SEC-CFG-04).
        $message = $this->message();

        $this->callHook([
            'channel' => 'sms',
            'event' => 'delivered',
            'custom_id' => $message->idempotency_key,
        ])->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function every_call_is_logged_before_it_is_acted_on(): void
    {
        $this->configureSecret();

        $this->callHook(['channel' => 'sms', 'event' => 'delivered', 'custom_id' => 'nope'], token: 'wrong')
            ->assertStatus(401);

        // A forged call is exactly the one worth having a record of.
        $this->assertDatabaseHas('provider_webhook_logs', ['signature_valid' => false]);
    }

    #[Test]
    public function the_shared_secret_is_never_stored_in_the_webhook_log(): void
    {
        $this->configureSecret();
        $message = $this->message();

        $this->callHook([
            'channel' => 'sms',
            'event' => 'delivered',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        // The log row is long-lived and read by more people than the settings
        // screen is (SEC-CFG-05).
        $rows = \DB::table('provider_webhook_logs')->get();
        $dump = $rows->pluck('headers')->implode(' ').$rows->pluck('payload')->implode(' ');

        $this->assertStringNotContainsString(self::SECRET, $dump);
    }

    // -----------------------------------------------------------------------
    // The bug itself: status can move past `sent` on the other channels
    // -----------------------------------------------------------------------

    #[Test]
    public function every_non_email_channel_can_report_a_delivery(): void
    {
        $this->configureSecret();

        foreach (self::REPORTING_CHANNELS as $channel) {
            $message = $this->message($channel);

            $this->callHook([
                'channel' => $channel,
                'event' => 'delivered',
                'event_id' => 'delivered-'.$channel,
                'custom_id' => $message->idempotency_key,
            ])->assertOk();

            $message->refresh();

            // Before this endpoint existed, all four sat at `sent` for ever and
            // "was it delivered?" had no answer anywhere in the system.
            $this->assertSame('delivered', $message->status, $channel.' should reach delivered');
            $this->assertNotNull($message->delivered_at, $channel.' should record when');
        }
    }

    #[Test]
    public function a_read_receipt_records_when_the_message_was_seen(): void
    {
        $this->configureSecret();
        $message = $this->message('whatsapp');

        $this->callHook([
            'channel' => 'whatsapp',
            'event' => 'read',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $message->refresh();

        // WhatsApp and RCS carry a genuine read receipt, which is a stronger
        // engagement signal than delivery and belongs on the message.
        $this->assertSame('read', $message->status);
        $this->assertTrue(now()->equalTo($message->read_at));
    }

    #[Test]
    public function a_failure_report_moves_the_message_off_sent_and_says_why(): void
    {
        $this->configureSecret();
        $message = $this->message('sms');

        $this->callHook([
            'channel' => 'sms',
            'event' => 'failed',
            'reason' => 'Carrier rejected the route',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $message->refresh();

        $this->assertSame('failed', $message->status);
        $this->assertNotNull($message->failed_at);
        // The provider's own words, kept: "failed" with no reason is the state
        // that makes a support ticket unanswerable.
        $this->assertStringContainsString('Carrier rejected the route', (string) $message->failure_reason);
    }

    #[Test]
    public function a_message_can_be_resolved_by_the_provider_id_as_well_as_our_own_key(): void
    {
        $this->configureSecret();
        $message = $this->message('rcs', ['provider_message_id' => 'rcs-abc-123']);

        // Not every provider echoes a custom id back; most only quote their own.
        $this->callHook([
            'channel' => 'rcs',
            'event' => 'delivered',
            'message_id' => 'rcs-abc-123',
        ])->assertOk();

        $this->assertSame('delivered', $message->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Nothing in the payload may reach further than it should
    // -----------------------------------------------------------------------

    #[Test]
    public function an_event_cannot_move_a_message_on_a_different_channel(): void
    {
        $this->configureSecret();
        $message = $this->message('sms');

        // The channel in the body is attacker-controlled, and so is the id. If
        // lookup ignored the channel, an SMS callback could mark an email read -
        // or reach a channel this endpoint is not meant to serve at all.
        $this->callHook([
            'channel' => 'whatsapp',
            'event' => 'delivered',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function an_email_event_is_refused_here_and_left_to_its_own_provider(): void
    {
        $this->configureSecret();
        $message = $this->message('email', ['provider' => 'mailercloud']);

        // Email has a signed, provider-specific endpoint of its own. Accepting
        // email here would mean the same status could be written by whichever
        // of two secrets an attacker managed to obtain.
        $this->callHook([
            'channel' => 'email',
            'event' => 'delivered',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertDatabaseHas('provider_webhook_logs', ['processing_error' => 'Unsupported channel.']);
    }

    #[Test]
    public function an_unknown_message_id_is_acknowledged_without_creating_anything(): void
    {
        $this->configureSecret();

        // Usually an event for another environment sharing the provider
        // account. Making the provider retry it forever helps nobody, and
        // nothing in a payload may create a record.
        $this->callHook(['channel' => 'sms', 'event' => 'delivered', 'custom_id' => 'not-ours'])->assertOk();

        $this->assertDatabaseCount('messages', 0);
    }

    #[Test]
    public function an_unrecognised_event_is_recorded_rather_than_guessed_at(): void
    {
        $this->configureSecret();
        $message = $this->message('voice');

        $this->callHook([
            'channel' => 'voice',
            'event' => 'quantum_entangled',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        // FR-COMM-03: unknown states are visible, not swallowed. Guessing is
        // how a failure silently becomes a delivery.
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertDatabaseHas('provider_webhook_logs', ['processing_error' => 'Unrecognised event type.']);
    }

    // -----------------------------------------------------------------------
    // Idempotency against provider redelivery (SEC-WH-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_redelivered_event_is_absorbed_by_the_database(): void
    {
        $this->configureSecret();
        $message = $this->message('sms');

        $payload = [
            'channel' => 'sms',
            'event' => 'delivered',
            'event_id' => 'evt-123',
            'custom_id' => $message->idempotency_key,
        ];

        $this->callHook($payload)->assertOk();
        $this->callHook($payload)->assertOk();

        // (provider, provider_event_id) is unique, so the duplicate never
        // reaches the handler at all.
        $this->assertSame(1, \DB::table('provider_webhook_logs')->count());
    }

    #[Test]
    public function a_redelivery_carrying_no_event_id_still_leaves_the_timestamp_alone(): void
    {
        $this->configureSecret();
        $message = $this->message('sms');

        $this->callHook(['channel' => 'sms', 'event' => 'delivered', 'custom_id' => $message->idempotency_key])
            ->assertOk();

        $firstDelivery = $message->fresh()->delivered_at;

        // Not every provider sends an event id, so the unique index cannot
        // catch this one - the handler itself has to be idempotent. Time moves
        // on between the two calls precisely so a re-stamp would show up.
        Carbon::setTestNow(now()->addHour());

        $this->callHook(['channel' => 'sms', 'event' => 'delivered', 'custom_id' => $message->idempotency_key])
            ->assertOk();

        $this->assertTrue(
            $firstDelivery->equalTo($message->fresh()->delivered_at),
            'A redelivery must not rewrite when the message was delivered.',
        );
    }

    #[Test]
    public function a_late_delivery_receipt_does_not_undo_a_read_receipt(): void
    {
        $this->configureSecret();
        $message = $this->message('whatsapp');

        $this->callHook([
            'channel' => 'whatsapp', 'event' => 'read', 'event_id' => 'r1',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        // Providers do not guarantee ordering, and a retried `delivered` can
        // easily land after a `read`. Letting it win would quietly walk the
        // message backwards and understate engagement in every report.
        $this->callHook([
            'channel' => 'whatsapp', 'event' => 'delivered', 'event_id' => 'd1',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $message->refresh();

        $this->assertSame('read', $message->status);
        // The delivery fact is still worth keeping - only the regression is refused.
        $this->assertNotNull($message->delivered_at);
    }

    // -----------------------------------------------------------------------
    // Suppression from provider feedback (BR-DNC-07, ADR-E)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_number_the_carrier_says_does_not_exist_is_suppressed_on_the_phone_channels(): void
    {
        $this->configureSecret();
        $message = $this->message('sms');
        $lead = $message->lead;

        $this->callHook([
            'channel' => 'sms',
            'event' => 'failed',
            'error_code' => 'UNKNOWN_SUBSCRIBER',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $dnc = app(DncService::class);
        $lead->refresh();

        // BR-DNC-07: a number that does not exist is not going to start
        // existing, and every future send to it is spend with no chance of
        // arriving.
        $this->assertFalse($dnc->canContact($lead, Channel::Sms));
        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'reason' => DncReason::InvalidNumber->value,
            'source' => 'webhook',
        ]);

        // BR-DNC-02: the REASON decides the scope. A dead phone number says
        // nothing about the email address, and blocking it would cost the sales
        // team a contactable customer.
        $this->assertFalse($dnc->canContact($lead, Channel::Call));
        $this->assertTrue($dnc->canContact($lead, Channel::Email));
    }

    #[Test]
    public function a_failure_that_is_not_confirmed_permanent_suppresses_nothing(): void
    {
        $this->configureSecret();
        $message = $this->message('sms');

        $this->callHook([
            'channel' => 'sms',
            'event' => 'failed',
            'reason' => 'Handset unreachable',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        /*
         * The same asymmetry the email bounce handling is built on. A phone off
         * in a tunnel is a temporary failure; suppressing on it permanently
         * stops contact with a real customer and lifting it needs Manager+
         * (BR-DNC-06). So an unqualified failure is recorded and left
         * contactable.
         */
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertDatabaseCount('dnc_entries', 0);
        $this->assertTrue(app(DncService::class)->canContact($message->lead->fresh(), Channel::Sms));
    }

    #[Test]
    public function a_recipient_who_blocks_us_stops_that_channel_and_not_the_others(): void
    {
        $this->configureSecret();
        $message = $this->message('whatsapp');

        $this->callHook([
            'channel' => 'whatsapp',
            'event' => 'blocked',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $lead = $message->lead->fresh();
        $dnc = app(DncService::class);

        $this->assertFalse($dnc->canContact($lead, Channel::WhatsApp));

        /*
         * Scoped deliberately, for the reason an email unsubscribe is scoped to
         * email: "stop messaging me here" is not "never call me". `OptedOut` is
         * an absolute reason, so an entry with no channel would silence every
         * channel at once off a signal that said far less than that.
         */
        $this->assertTrue($dnc->canContact($lead, Channel::Call));
        $this->assertTrue($dnc->canContact($lead, Channel::Sms));
    }

    #[Test]
    public function a_redelivered_opt_out_does_not_stack_suppression_rows(): void
    {
        $this->configureSecret();
        $message = $this->message('sms');

        // Distinct event ids, so both reach the handler rather than being
        // stopped by the unique constraint on the log.
        foreach (['evt-a', 'evt-b'] as $eventId) {
            $this->callHook([
                'channel' => 'sms',
                'event' => 'unsubscribe',
                'event_id' => $eventId,
                'custom_id' => $message->idempotency_key,
            ])->assertOk();
        }

        // Routed through DncService, which is idempotent - a controller keeping
        // its own suppression list is exactly what ADR-E exists to prevent, and
        // a list that grows a row per provider retry is one nobody can audit.
        $this->assertDatabaseCount('dnc_entries', 1);
    }
}
