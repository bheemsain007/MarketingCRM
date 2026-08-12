<?php

namespace Tests\Feature\Messaging;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Jobs\SendMessage;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\Dnc\DncService;
use App\Services\Messaging\MessageDriverManager;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Voice / voice-SMS blast (Phase 17, FR-VOICE-01, FR-COMM-01..06).
 *
 * This is the outbound *message* voice channel - an automated announcement -
 * not the interactive human/AI dialling of Channel::Call, which never comes
 * through the message pipeline. One driver class on the same channel-agnostic
 * path as SMS. The vendor is not chosen (T-33), so these tests pin the
 * vendor-independent shape: the announcement body is what goes out, the
 * configured provider is what the row records, a 4xx is not retried, and the
 * shared DNC gate refuses a suppressed lead here too (TESTING §4.1).
 */
class VoiceSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Admin->value)->first());
        $this->actingAs($user->fresh(), 'sanctum');

        return $user->fresh();
    }

    private function configureVoice(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.voice.provider', 'exotel');
        $settings->set('providers.voice.api_key', 'voice-api-key-value');
    }

    private function runJob(Message $message): void
    {
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );
    }

    private function queueVoice(Lead $lead): Message
    {
        Queue::fake();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Voice->value,
            'body' => 'Your appointment is confirmed for tomorrow.',
        ])->assertStatus(202);

        return Message::where('lead_id', $lead->id)->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // The wire format
    // -----------------------------------------------------------------------

    #[Test]
    public function the_announcement_and_e164_number_are_sent(): void
    {
        $this->actingAsAdmin();
        $this->configureVoice();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['voice.example-provider.com/*' => Http::response(['call_id' => 'call-1'], 200)]);

        $this->runJob($this->queueVoice($lead));

        Http::assertSent(function ($request) {
            // A voice provider dials an E.164 number and reads the body out by
            // text-to-speech, so both go on the wire unchanged.
            return $request['to'] === '+919876543210'
                && $request['text'] === 'Your appointment is confirmed for tomorrow.';
        });
    }

    // -----------------------------------------------------------------------
    // Provider responses
    // -----------------------------------------------------------------------

    #[Test]
    public function a_success_response_marks_the_message_sent_under_the_configured_provider(): void
    {
        $this->actingAsAdmin();
        $this->configureVoice();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['voice.example-provider.com/*' => Http::response(['call_id' => 'call-9911'], 200)]);

        $message = $this->queueVoice($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('exotel', $message->provider);
        $this->assertSame('call-9911', $message->provider_message_id);
    }

    #[Test]
    public function a_provider_rejection_is_not_retried(): void
    {
        $this->actingAsAdmin();
        $this->configureVoice();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['voice.example-provider.com/*' => Http::response(['error' => 'unreachable number'], 400)]);

        $message = $this->queueVoice($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('rejected', (string) $message->failure_reason);
    }

    #[Test]
    public function a_provider_outage_is_retried(): void
    {
        $this->actingAsAdmin();
        $this->configureVoice();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['voice.example-provider.com/*' => Http::response('upstream down', 503)]);

        $message = $this->queueVoice($lead);

        $this->expectException(\RuntimeException::class);
        $this->runJob($message);
    }

    // -----------------------------------------------------------------------
    // Inherited behaviour, asserted for this channel specifically
    // -----------------------------------------------------------------------

    #[Test]
    public function a_suppressed_lead_is_refused_on_voice_too(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Voice->value,
            'body' => 'x',
        ])->assertStatus(403);

        $this->assertDatabaseHas('messages', [
            'lead_id' => $lead->id,
            'channel' => 'voice',
            'status' => 'skipped',
        ]);
    }

    #[Test]
    public function voice_falls_back_to_the_log_driver_until_it_is_keyed(): void
    {
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $message = $this->queueVoice($lead);
        $this->runJob($message);

        $this->assertSame('log', $message->fresh()->provider);
    }
}
