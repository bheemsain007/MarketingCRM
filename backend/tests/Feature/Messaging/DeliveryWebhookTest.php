<?php

namespace Tests\Feature\Messaging;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Models\Message;
use App\Services\Dnc\DncService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Delivery-status webhooks (FR-COMM-03, SEC-WH-*).
 *
 * The endpoint is unauthenticated by necessity, so what is worth testing is
 * that it treats its input as hostile: forged calls refused, unknown ids
 * acknowledged rather than acted on, redeliveries absorbed, and unrecognised
 * events recorded rather than guessed at.
 */
class DeliveryWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-value';

    private function configureSecret(): void
    {
        app(SettingsService::class)->set('providers.mailercloud.webhook_secret', self::SECRET);
    }

    private function callHook(array $payload, ?string $token = self::SECRET)
    {
        return $this->withHeaders($token === null ? [] : ['X-Webhook-Token' => $token])
            ->postJson('/api/v1/webhooks/mailercloud', $payload);
    }

    #[Test]
    public function a_request_without_the_shared_secret_is_refused(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'delivered', 'custom_id' => $message->idempotency_key], token: null)
            ->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function a_request_with_the_wrong_secret_is_refused(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'delivered', 'custom_id' => $message->idempotency_key], token: 'guess')
            ->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function an_unconfigured_endpoint_refuses_everything(): void
    {
        // No secret set. Accepting anything in that state would make a fresh
        // install an open write endpoint.
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'delivered', 'custom_id' => $message->idempotency_key])
            ->assertStatus(401);

        $this->assertSame('sent', $message->fresh()->status);
    }

    #[Test]
    public function every_call_is_logged_before_it_is_acted_on(): void
    {
        $this->configureSecret();

        $this->callHook(['event' => 'delivered', 'custom_id' => 'nope'], token: 'wrong')->assertStatus(401);

        // Logged even though it was rejected - a forged call is exactly the one
        // worth having a record of.
        $this->assertDatabaseHas('provider_webhook_logs', [
            'provider' => 'mailercloud',
            'signature_valid' => false,
        ]);
    }

    #[Test]
    public function the_shared_secret_is_never_stored_in_the_webhook_log(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'delivered', 'custom_id' => $message->idempotency_key])->assertOk();

        // The log row is long-lived and read by more people than the settings
        // screen is (SEC-CFG-05).
        $rows = \DB::table('provider_webhook_logs')->get();
        $dump = $rows->pluck('headers')->implode(' ').$rows->pluck('payload')->implode(' ');

        $this->assertStringNotContainsString(self::SECRET, $dump);
    }

    #[Test]
    public function a_delivery_event_advances_the_message(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'delivered', 'custom_id' => $message->idempotency_key])->assertOk();

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    #[Test]
    public function a_bounce_is_recorded_as_a_bounce_and_not_a_failure_to_send(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'bounced', 'custom_id' => $message->idempotency_key])->assertOk();

        // Distinct from `failed`, which means we could not hand it over.
        // Reporting conflating the two would hide list quality problems.
        $this->assertSame('bounced', $message->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Automatic suppression from provider feedback (BR-DNC-07, T-54)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_hard_bounce_suppresses_the_email_address_and_leaves_the_phone_alone(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'hard_bounce', 'custom_id' => $message->idempotency_key])->assertOk();

        $lead = $message->lead->fresh();
        $dnc = app(DncService::class);

        // BR-DNC-07: the address is dead, so we stop emailing it without
        // anybody having to remember to tick a box.
        $this->assertFalse($dnc->canContact($lead, Channel::Email));
        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'reason' => DncReason::BouncedEmail->value,
            'source' => 'webhook',
        ]);

        // BR-DNC-02: a dead mailbox says nothing about the phone number.
        // Suppressing the lead outright here would silently cost the sales
        // team every call to a customer whose email merely changed.
        $this->assertTrue($dnc->canContact($lead, Channel::Call));
    }

    #[Test]
    public function a_bounce_not_confirmed_hard_is_recorded_without_suppressing(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'bounced', 'custom_id' => $message->idempotency_key])->assertOk();

        /*
         * A full mailbox is a soft bounce. Suppressing on it permanently stops
         * email to a real customer and lifting it needs Manager+ (BR-DNC-06),
         * so an unqualified "bounce" is recorded and left contactable.
         */
        $this->assertSame('bounced', $message->fresh()->status);
        $this->assertDatabaseCount('dnc_entries', 0);
        $this->assertTrue(app(DncService::class)->canContact($message->lead->fresh(), Channel::Email));
    }

    #[Test]
    public function a_bounce_the_payload_marks_hard_does_suppress(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook([
            'event' => 'bounced',
            'bounce_type' => 'Hard',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        $this->assertFalse(app(DncService::class)->canContact($message->lead->fresh(), Channel::Email));
    }

    #[Test]
    public function an_unsubscribe_stops_email_without_stopping_the_phone(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook(['event' => 'unsubscribe', 'custom_id' => $message->idempotency_key])->assertOk();

        $lead = $message->lead->fresh();
        $dnc = app(DncService::class);

        $this->assertFalse($dnc->canContact($lead, Channel::Email));

        /*
         * Scoped deliberately. `OptedOut` is an absolute reason, so an entry
         * with no channel would block every channel - and reading "never call
         * me again" into a newsletter unsubscribe infers far more than the
         * click said. Total silence is `DoNotContact`, a deliberate act.
         */
        $this->assertTrue($dnc->canContact($lead, Channel::Call));
        $this->assertTrue($dnc->canContact($lead, Channel::Sms));
    }

    #[Test]
    public function a_redelivered_bounce_does_not_stack_suppression_rows(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        // Distinct event ids, so both reach the handler rather than being
        // stopped by the unique constraint on the log.
        foreach (['evt-a', 'evt-b'] as $eventId) {
            $this->callHook([
                'event' => 'hard_bounce',
                'event_id' => $eventId,
                'custom_id' => $message->idempotency_key,
            ])->assertOk();
        }

        // A suppression list that grows a row per provider retry is one
        // nobody can audit.
        $this->assertDatabaseCount('dnc_entries', 1);
    }

    #[Test]
    public function an_unknown_message_id_is_acknowledged_without_creating_anything(): void
    {
        $this->configureSecret();

        // Usually an event for another environment sharing the provider
        // account. Making the provider retry it forever helps nobody.
        $this->callHook(['event' => 'delivered', 'custom_id' => 'not-ours'])->assertOk();

        $this->assertDatabaseCount('messages', 0);
    }

    #[Test]
    public function an_unrecognised_event_is_recorded_rather_than_guessed_at(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $this->callHook([
            'event' => 'quantum_entangled',
            'custom_id' => $message->idempotency_key,
        ])->assertOk();

        // FR-COMM-03: unknown states are visible, not swallowed. Guessing is
        // how a bounce silently becomes a delivery.
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertDatabaseHas('provider_webhook_logs', [
            'processing_error' => 'Unrecognised event type.',
        ]);
    }

    #[Test]
    public function a_redelivered_event_is_absorbed_by_the_database(): void
    {
        $this->configureSecret();
        $message = Message::factory()->sent()->create();

        $payload = [
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
}
