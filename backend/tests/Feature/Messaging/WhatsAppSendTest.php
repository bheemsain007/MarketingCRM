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
 * WhatsApp via the Meta Cloud API (Phase 14, FR-WA-01, FR-COMM-01..06).
 *
 * Phase 14 is one driver class - the gate, queue, idempotency and status
 * transitions were built in Phase 13 and are channel-agnostic. So these tests
 * focus on what is genuinely WhatsApp-specific: the number goes on the wire as
 * international digits with no '+', the wamid is what a delivery webhook keys on,
 * and a Cloud API 4xx (a 24-hour-window or template refusal) must not be
 * retried. The shared DNC gate is proved for this channel too, because a send
 * path that could reach a suppressed lead is the worst defect in the system
 * (TESTING §4.1).
 *
 * `queueWhatsApp()` seeds a recent inbound message for the lead before every
 * send, so the wire-format/response tests below are all inside the 24-hour
 * customer-service window and exercise the unchanged `type: text` path
 * (FR-WA-01 regression). The window gate itself - text inside, template or
 * refusal outside - is `WhatsAppTemplateWindowTest`.
 */
class WhatsAppSendTest extends TestCase
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

    private function configureWhatsApp(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.whatsapp.token', 'EAAG-cloud-api-token');
        $settings->set('providers.whatsapp.phone_number_id', '10987654321');
    }

    private function runJob(Message $message): void
    {
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );
    }

    /** Opens the 24-hour customer-service window (FR-WA-01): the lead messaged in recently. */
    private function openWindow(Lead $lead): void
    {
        Message::factory()->create([
            'lead_id' => $lead->id,
            'channel' => Channel::WhatsApp->value,
            'direction' => 'inbound',
            'status' => 'received',
            'recipient' => $lead->phone_e164,
            'body' => 'Sure, tell me more.',
            'sent_at' => now()->subHours(2),
        ]);
    }

    private function queueWhatsApp(Lead $lead): Message
    {
        Queue::fake();
        $this->openWindow($lead);

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'body' => 'Your order has shipped.',
        ])->assertStatus(202);

        return Message::where('lead_id', $lead->id)->where('direction', 'outbound')->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // The wire format
    // -----------------------------------------------------------------------

    #[Test]
    public function the_number_goes_on_the_wire_as_international_digits_without_a_plus(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);

        $this->runJob($this->queueWhatsApp($lead));

        Http::assertSent(function ($request) {
            // The Cloud API rejects a leading '+'; storage is E.164, so exactly
            // that character is stripped and nothing else.
            return $request['to'] === '919876543210'
                && $request['messaging_product'] === 'whatsapp'
                && $request['text']['body'] === 'Your order has shipped.'
                // Posted to the configured phone-number id, not a hardcoded one.
                && str_contains($request->url(), '/10987654321/messages');
        });
    }

    // -----------------------------------------------------------------------
    // Provider responses
    // -----------------------------------------------------------------------

    #[Test]
    public function a_success_response_marks_the_message_sent_and_records_the_wamid(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HBgLMTI=']]], 200)]);

        $message = $this->queueWhatsApp($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('whatsapp_cloud', $message->provider);
        // The wamid is echoed back on the delivery webhook, so it is what status
        // updates are matched on later.
        $this->assertSame('wamid.HBgLMTI=', $message->provider_message_id);
        $this->assertNull($message->delivered_at);   // "sent" is not "delivered".
    }

    #[Test]
    public function a_cloud_api_rejection_is_not_retried(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // A 4xx is Meta refusing the request itself - here the 24-hour customer
        // service window has closed and only a template would be accepted.
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Message failed to send because more than 24 hours have passed.'],
        ], 400)]);

        $message = $this->queueWhatsApp($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('24 hours', (string) $message->failure_reason);
    }

    #[Test]
    public function a_graph_outage_is_retried(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['graph.facebook.com/*' => Http::response('service unavailable', 503)]);

        $message = $this->queueWhatsApp($lead);

        // 5xx is transport-level and worth another attempt with backoff, unlike
        // the 4xx refusal above.
        $this->expectException(\RuntimeException::class);
        $this->runJob($message);
    }

    // -----------------------------------------------------------------------
    // Inherited behaviour, asserted for this channel specifically
    // -----------------------------------------------------------------------

    #[Test]
    public function a_suppressed_lead_is_refused_on_whatsapp_too(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'body' => 'x',
        ])->assertStatus(403);

        $this->assertDatabaseHas('messages', [
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'status' => 'skipped',
        ]);
    }

    #[Test]
    public function whatsapp_does_not_accept_a_subject(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // Only email carries a subject; accepting one here would silently drop
        // it at send time.
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value,
            'subject' => 'Nope',
            'body' => 'x',
        ])->assertStatus(422)->assertJsonPath('errors.0.field', 'subject');
    }

    #[Test]
    public function whatsapp_falls_back_to_the_log_driver_until_it_is_keyed(): void
    {
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $message = $this->queueWhatsApp($lead);
        $this->runJob($message);

        // No credentials set, so nothing is sent and the row says so rather than
        // implying delivery.
        $this->assertSame('log', $message->fresh()->provider);
    }
}
