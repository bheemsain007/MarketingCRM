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
 * RCS via a messaging aggregator (Phase 16, FR-RCS-01, FR-COMM-01..06).
 *
 * One driver class on the channel-agnostic pipeline from Phase 13. The vendor is
 * not chosen (T-32), so what these tests pin is the shape that does not depend
 * on the vendor: the address goes out as E.164 (unlike the national form SMS
 * needs), the provider actually recorded on the row is whatever is configured,
 * a 4xx refusal is not retried, and the shared DNC gate refuses a suppressed
 * lead here too (TESTING §4.1).
 */
class RcsSendTest extends TestCase
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

    private function configureRcs(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.rcs.provider', 'jio_rcs');
        $settings->set('providers.rcs.api_key', 'rcs-api-key-value');
    }

    private function runJob(Message $message): void
    {
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );
    }

    private function queueRcs(Lead $lead): Message
    {
        Queue::fake();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Rcs->value,
            'body' => 'Tap to see your offer.',
        ])->assertStatus(202);

        return Message::where('lead_id', $lead->id)->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // The wire format
    // -----------------------------------------------------------------------

    #[Test]
    public function the_address_is_sent_e164_not_national(): void
    {
        $this->actingAsAdmin();
        $this->configureRcs();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['rcs.googleapis.com/*' => Http::response(['messageId' => 'rcs-1'], 200)]);

        $this->runJob($this->queueRcs($lead));

        Http::assertSent(function ($request) {
            // RCS addresses are E.164 - the stored value goes as-is, with the
            // leading '+', unlike the 10-digit form the SMS aggregator wants.
            return $request['to'] === '+919876543210'
                && $request['contentMessage']['text'] === 'Tap to see your offer.';
        });
    }

    // -----------------------------------------------------------------------
    // Provider responses
    // -----------------------------------------------------------------------

    #[Test]
    public function a_success_response_marks_the_message_sent_under_the_configured_provider(): void
    {
        $this->actingAsAdmin();
        $this->configureRcs();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['rcs.googleapis.com/*' => Http::response(['messageId' => 'rcs-77'], 200)]);

        $message = $this->queueRcs($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        // The vendor is unknown until chosen, so the row records whichever
        // provider is configured - not a hardcoded name.
        $this->assertSame('jio_rcs', $message->provider);
        $this->assertSame('rcs-77', $message->provider_message_id);
    }

    #[Test]
    public function a_provider_rejection_is_not_retried(): void
    {
        $this->actingAsAdmin();
        $this->configureRcs();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['rcs.googleapis.com/*' => Http::response(['error' => 'no RCS capability'], 422)]);

        $message = $this->queueRcs($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('rejected', (string) $message->failure_reason);
    }

    #[Test]
    public function a_provider_outage_is_retried(): void
    {
        $this->actingAsAdmin();
        $this->configureRcs();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['rcs.googleapis.com/*' => Http::response('gateway down', 502)]);

        $message = $this->queueRcs($lead);

        $this->expectException(\RuntimeException::class);
        $this->runJob($message);
    }

    // -----------------------------------------------------------------------
    // Inherited behaviour, asserted for this channel specifically
    // -----------------------------------------------------------------------

    #[Test]
    public function a_suppressed_lead_is_refused_on_rcs_too(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Rcs->value,
            'body' => 'x',
        ])->assertStatus(403);

        $this->assertDatabaseHas('messages', [
            'lead_id' => $lead->id,
            'channel' => 'rcs',
            'status' => 'skipped',
        ]);
    }

    #[Test]
    public function rcs_falls_back_to_the_log_driver_until_it_is_keyed(): void
    {
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $message = $this->queueRcs($lead);
        $this->runJob($message);

        $this->assertSame('log', $message->fresh()->provider);
    }
}
