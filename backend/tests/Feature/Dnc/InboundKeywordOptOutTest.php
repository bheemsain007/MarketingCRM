<?php

namespace Tests\Feature\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Models\Lead;
use App\Services\Dnc\DncService;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inbound keyword opt-out (Phase 19, BR-DNC-05/07).
 *
 * The other half of consent: a lead who replies STOP has told us to stop. These
 * tests prove the reply suppresses the lead, that it is scoped to the channel
 * the STOP arrived on (not every channel), that a keyword buried mid-sentence is
 * not treated as a command, and that the endpoint is gated by a shared secret
 * like every other unauthenticated webhook.
 */
class InboundKeywordOptOutTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'inbound-secret-value';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('providers.inbound.webhook_secret', self::SECRET);
    }

    /** @param array<string, mixed> $body */
    private function postInbound(array $body, bool $signed = true)
    {
        return $this->withHeaders($signed ? ['X-Webhook-Token' => self::SECRET] : [])
            ->postJson('/api/v1/webhooks/inbound', $body);
    }

    // -----------------------------------------------------------------------
    // Opt-out
    // -----------------------------------------------------------------------

    #[Test]
    public function a_stop_reply_suppresses_the_lead_on_that_channel(): void
    {
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->postInbound([
            'channel' => Channel::Sms->value,
            'from' => '+919876543210',
            'text' => 'STOP',
        ])->assertOk()->assertJsonPath('message', 'Opt-out recorded.');

        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'reason' => DncReason::OptedOut->value,
            'channel' => Channel::Sms->value,
            'source' => 'inbound',
            'active' => true,
        ]);

        $this->assertTrue($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function the_opt_out_is_scoped_to_the_reply_channel_only(): void
    {
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->postInbound([
            'channel' => Channel::Sms->value,
            'from' => '+919876543210',
            'text' => 'unsubscribe',
        ])->assertOk();

        // "Stop texting me" is not "never call me": SMS is blocked, WhatsApp is
        // not. Inferring the harder-to-lift blanket block from one channel's
        // STOP is not ours to do (BR-DNC-06).
        $dnc = app(DncService::class);
        $this->assertFalse($dnc->canContact($lead->fresh(), Channel::Sms));
        $this->assertTrue($dnc->canContact($lead->fresh(), Channel::WhatsApp));
    }

    #[Test]
    public function the_keyword_matches_case_insensitively_as_the_first_word(): void
    {
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->postInbound([
            'channel' => Channel::WhatsApp->value,
            'from' => '+919876543210',
            'text' => 'Stop please, no more messages',
        ])->assertOk()->assertJsonPath('message', 'Opt-out recorded.');

        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'channel' => Channel::WhatsApp->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Non-actions
    // -----------------------------------------------------------------------

    #[Test]
    public function a_reply_that_only_mentions_stop_mid_sentence_does_not_opt_out(): void
    {
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // A keyword buried in a sentence is not a command - suppressing here
        // would silence a customer who was still talking to us.
        $this->postInbound([
            'channel' => Channel::Sms->value,
            'from' => '+919876543210',
            'text' => "Please don't stop sending me offers",
        ])->assertOk()->assertJsonPath('message', 'No action.');

        $this->assertDatabaseCount('dnc_entries', 0);
        $this->assertFalse($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function an_unknown_number_is_acknowledged_without_suppressing(): void
    {
        // No lead owns this number - acknowledged so the provider does not retry,
        // but nothing is created (the webhook never mints a lead).
        $this->postInbound([
            'channel' => Channel::Sms->value,
            'from' => '+919999999999',
            'text' => 'STOP',
        ])->assertOk()->assertJsonPath('message', 'No matching lead.');

        $this->assertDatabaseCount('dnc_entries', 0);
    }

    #[Test]
    public function an_email_channel_reply_is_not_treated_as_a_text_opt_out(): void
    {
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // Email opts out through its own unsubscribe event, not here.
        $this->postInbound([
            'channel' => Channel::Email->value,
            'from' => '+919876543210',
            'text' => 'STOP',
        ])->assertOk()->assertJsonPath('message', 'No action.');

        $this->assertDatabaseCount('dnc_entries', 0);
    }

    // -----------------------------------------------------------------------
    // The shared-secret gate
    // -----------------------------------------------------------------------

    #[Test]
    public function a_request_without_the_secret_is_rejected_and_suppresses_nothing(): void
    {
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->postInbound([
            'channel' => Channel::Sms->value,
            'from' => '+919876543210',
            'text' => 'STOP',
        ], signed: false)->assertStatus(401);

        // The forged request must not have suppressed anyone.
        $this->assertDatabaseCount('dnc_entries', 0);
        $this->assertFalse($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function every_inbound_request_is_logged_before_it_is_acted_on(): void
    {
        $this->postInbound([
            'channel' => Channel::Sms->value,
            'from' => '+919876543210',
            'text' => 'STOP',
        ], signed: false)->assertStatus(401);

        // Logged even though it was rejected - an unauthenticated endpoint that
        // did not record forged traffic would be blind to an attack (SEC-WH-*).
        $this->assertDatabaseHas('provider_webhook_logs', [
            'provider' => 'inbound',
            'signature_valid' => false,
        ]);
    }
}
