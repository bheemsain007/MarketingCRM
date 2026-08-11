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
use App\Services\Messaging\OutboundMessageService;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Email sending (Phase 13, FR-COMM-01..06, FR-EMAIL-01).
 *
 * The DNC assertions here are the mandatory ones (TESTING §4.1): a send path
 * that can reach a suppressed lead is the single worst defect this system can
 * have, and it has to be proved at BOTH the queueing point and the dispatch
 * point, because BR-DNC-03 requires the second check specifically.
 */
class EmailSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Http::preventStrayRequests();
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = $this->user($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function leadWithEmail(?User $owner = null): Lead
    {
        return Lead::factory()->create([
            'email' => 'lead@example.com',
            'assigned_to' => $owner?->id,
        ]);
    }

    private function configureMailercloud(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.mailercloud.api_key', 'test-key-123456789');
        $settings->set('providers.mailercloud.from_email', 'crm@example.com');
    }

    // -----------------------------------------------------------------------
    // The DNC gate (FR-COMM-04, BR-DNC-01/03/05) - mandatory coverage
    // -----------------------------------------------------------------------

    #[Test]
    public function a_suppressed_lead_is_refused_and_the_skip_is_recorded(): void
    {
        Queue::fake();
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);

        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value,
            'subject' => 'Hello',
            'body' => 'Body',
        ])->assertStatus(403);

        // Both halves matter: the caller is told, AND the refusal is on record
        // rather than being a silent nothing (BR-DNC-05).
        $this->assertDatabaseHas('messages', [
            'lead_id' => $lead->id,
            'status' => 'skipped',
            'skip_reason' => 'suppressed',
        ]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_lead_suppressed_after_queueing_is_still_not_sent(): void
    {
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);
        $this->configureMailercloud();

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value,
            'body' => 'Body',
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->firstOrFail();

        // They opt out while the message sits in the queue. BR-DNC-03 exists
        // for exactly this window.
        DncEntry::factory()->for($lead)->reason(DncReason::OptedOut)->create();

        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );

        $message->refresh();
        $this->assertSame('skipped', $message->status);
        $this->assertSame('suppressed_after_queueing', $message->skip_reason);
        $this->assertNull($message->sent_at);
    }

    #[Test]
    public function a_bounced_email_suppression_does_not_block_a_phone_channel(): void
    {
        Queue::fake();
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);

        DncEntry::factory()->for($lead)->reason(DncReason::BouncedEmail)->create();

        // BR-DNC-02: a hard bounce says nothing about the phone number. Email
        // is refused; SMS is not.
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'x',
        ])->assertStatus(403);

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Sms->value, 'body' => 'x',
        ])->assertStatus(202);
    }

    // -----------------------------------------------------------------------
    // Queueing (FR-COMM-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_send_is_queued_and_never_dispatched_inline(): void
    {
        Queue::fake();
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value,
            'subject' => 'Quote',
            'body' => 'Here it is',
        ])->assertStatus(202)->assertJsonPath('data.status', 'queued');

        // A provider timeout must never become the user's timeout.
        Queue::assertPushed(SendMessage::class);
    }

    #[Test]
    public function a_lead_without_an_email_address_is_refused(): void
    {
        Queue::fake();
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['email' => null, 'assigned_to' => $me->id]);

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'x',
        ])->assertStatus(422);

        $this->assertDatabaseCount('messages', 0);
    }

    #[Test]
    public function a_telecaller_cannot_message_a_lead_outside_their_scope(): void
    {
        Queue::fake();
        $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadWithEmail($this->user(RoleName::Telecaller));

        // The same IDOR guard as every other per-lead route (SEC-AUTHZ-04).
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'x',
        ])->assertStatus(403);
    }

    #[Test]
    public function a_read_only_role_cannot_send(): void
    {
        Queue::fake();
        $this->actingAsRole(RoleName::Accounts);   // holds no messages.send
        $lead = $this->leadWithEmail();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'x',
        ])->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Delivery through the driver
    // -----------------------------------------------------------------------

    #[Test]
    public function an_unconfigured_channel_records_the_message_without_pretending_to_send(): void
    {
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);

        Queue::fake();
        $response = $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'x',
        ])->assertStatus(202);

        // The whole point of the fallback: the path works with no credentials,
        // and it says so rather than implying delivery.
        $this->assertStringContainsString('No provider is configured', $response->json('message'));

        $message = Message::firstOrFail();
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );

        // `provider = log` is what makes "why did nobody receive this?"
        // answerable from the row.
        $this->assertSame('log', $message->fresh()->provider);
    }

    #[Test]
    public function a_configured_provider_marks_the_message_sent(): void
    {
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);
        $this->configureMailercloud();

        Http::fake(['cloudapi.mailercloud.com/*' => Http::response(['id' => 'mc-123'], 200)]);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'subject' => 'S', 'body' => 'B',
        ])->assertStatus(202);

        $message = Message::firstOrFail();
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('mailercloud', $message->provider);
        $this->assertSame('mc-123', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);

        // "Sent" is not "delivered" - that fact arrives later by webhook.
        $this->assertNull($message->delivered_at);
    }

    #[Test]
    public function a_provider_rejection_is_not_retried(): void
    {
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);
        $this->configureMailercloud();

        Http::fake(['cloudapi.mailercloud.com/*' => Http::response(['error' => 'bad address'], 422)]);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'B',
        ])->assertStatus(202);

        $message = Message::firstOrFail();
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );

        // A 4xx means the request itself is wrong. Three more identical
        // attempts end in the same place.
        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('rejected', (string) $message->failure_reason);
    }

    #[Test]
    public function a_transient_provider_failure_is_retried(): void
    {
        $me = $this->actingAsRole(RoleName::Admin);
        $lead = $this->leadWithEmail($me);
        $this->configureMailercloud();

        Http::fake(['cloudapi.mailercloud.com/*' => Http::response('upstream down', 503)]);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'B',
        ])->assertStatus(202);

        $message = Message::firstOrFail();

        // 5xx is worth another attempt with backoff (FR-COMM-06), so the job
        // throws rather than burying the message as failed on attempt one.
        $this->expectException(\RuntimeException::class);

        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );
    }

    // -----------------------------------------------------------------------
    // Templates (FR-COMM-02)
    // -----------------------------------------------------------------------

    #[Test]
    public function template_placeholders_are_substituted_and_never_executed(): void
    {
        $lead = Lead::factory()->create(['name' => 'Ramesh Kumar', 'email' => 'r@example.com']);

        $rendered = app(OutboundMessageService::class)->render($lead, [
            // A template body is operator-supplied text. Rendering it as Blade
            // would make the template editor a remote code execution primitive
            // (SEC-IN-06).
            'body' => 'Hi {{ lead_name }}, {{ 2+2 }} {{ config("app.key") }}',
        ]);

        $this->assertStringContainsString('Hi Ramesh Kumar', $rendered['body']);
        $this->assertStringContainsString('{{ 2+2 }}', $rendered['body']);
        $this->assertStringNotContainsString('base64:', $rendered['body']);
    }

    // -----------------------------------------------------------------------
    // History
    // -----------------------------------------------------------------------

    #[Test]
    public function message_history_is_scoped_to_the_callers_leads(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $mine = $this->leadWithEmail($me);
        $theirs = $this->leadWithEmail($this->user(RoleName::Telecaller));

        Message::factory()->for($mine)->create();
        Message::factory()->for($theirs)->create();

        $this->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }
}
