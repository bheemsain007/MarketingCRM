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
 * SMS via BhashSMS (Phase 15, FR-SMS-01, FR-COMM-01..06).
 *
 * Phase 15 is one driver class - the gate, queue, idempotency and status
 * transitions were all built in Phase 13 and are channel-agnostic. So these
 * tests focus on what is genuinely SMS-specific: the number format on the wire,
 * the gateway's habit of reporting business failures with HTTP 200, and the
 * fact that credentials must not travel over plaintext HTTP.
 */
class SmsSendTest extends TestCase
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

    private function configureBhash(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('providers.bhashsms.user', 'crm-account');
        $settings->set('providers.bhashsms.password', 'sms-password-value');
        $settings->set('providers.bhashsms.sender_id', 'NIVIYO');
    }

    private function runJob(Message $message): void
    {
        (new SendMessage($message->id))->handle(
            app(MessageDriverManager::class),
            app(DncService::class),
        );
    }

    private function queueSms(Lead $lead): Message
    {
        Queue::fake();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Sms->value,
            'body' => 'Your quote is ready.',
        ])->assertStatus(202);

        return Message::where('lead_id', $lead->id)->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // The wire format
    // -----------------------------------------------------------------------

    #[Test]
    public function the_number_is_sent_national_not_e164(): void
    {
        $this->actingAsAdmin();
        $this->configureBhash();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['bhashsms.com/*' => Http::response('S.99887766', 200)]);

        $this->runJob($this->queueSms($lead));

        Http::assertSent(function ($request) {
            // Aggregators take 10 digits. Sending E.164 is silently accepted by
            // some gateways and delivered to nobody.
            return $request['phone'] === '9876543210'
                && $request['sender'] === 'NIVIYO';
        });
    }

    #[Test]
    public function credentials_never_travel_over_plaintext_http(): void
    {
        $this->actingAsAdmin();
        $this->configureBhash();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['bhashsms.com/*' => Http::response('S.1', 200)]);

        $this->runJob($this->queueSms($lead));

        // The provider documents this endpoint over HTTP with the password in
        // the query string. Over TLS that is merely bad; over plaintext it puts
        // working credentials in every intermediary's logs (T-55).
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://'));
    }

    #[Test]
    public function a_non_indian_number_is_refused_rather_than_truncated(): void
    {
        $this->actingAsAdmin();
        $this->configureBhash();

        $lead = Lead::factory()->create(['phone_e164' => '+14155550123']);
        $message = $this->queueSms($lead);

        // No HTTP fake registered: if the driver tried to send, the
        // preventStrayRequests guard in setUp would fail this test.
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('Indian mobile numbers', (string) $message->failure_reason);
    }

    // -----------------------------------------------------------------------
    // The gateway's 200-means-anything habit
    // -----------------------------------------------------------------------

    #[Test]
    public function a_success_response_marks_the_message_sent(): void
    {
        $this->actingAsAdmin();
        $this->configureBhash();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['bhashsms.com/*' => Http::response('S.4455667788', 200)]);

        $message = $this->queueSms($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertSame('bhashsms', $message->provider);
        $this->assertSame('4455667788', $message->provider_message_id);
    }

    #[Test]
    public function a_200_with_an_error_body_is_a_rejection_not_a_success(): void
    {
        $this->actingAsAdmin();
        $this->configureBhash();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // The gateway answers 200 for business-level refusals - wrong password,
        // unregistered sender ID. Trusting the status code would mark every one
        // of those "sent" and nobody would ever know why the SMS never arrived.
        Http::fake(['bhashsms.com/*' => Http::response('E.Invalid Sender ID', 200)]);

        $message = $this->queueSms($lead);
        $this->runJob($message);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('Invalid Sender ID', (string) $message->failure_reason);
    }

    #[Test]
    public function a_gateway_outage_is_retried(): void
    {
        $this->actingAsAdmin();
        $this->configureBhash();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        Http::fake(['bhashsms.com/*' => Http::response('gateway down', 502)]);

        $message = $this->queueSms($lead);

        // Transport-level failure, unlike the 200-with-error case above.
        $this->expectException(\RuntimeException::class);
        $this->runJob($message);
    }

    // -----------------------------------------------------------------------
    // Inherited behaviour, asserted for this channel specifically
    // -----------------------------------------------------------------------

    #[Test]
    public function a_suppressed_lead_is_refused_on_sms_too(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        // The gate is shared, but a channel that quietly bypassed it would be
        // the worst defect in the system - so each one is proved, not assumed
        // (TESTING §4.1).
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Sms->value,
            'body' => 'x',
        ])->assertStatus(403);

        $this->assertDatabaseHas('messages', [
            'lead_id' => $lead->id,
            'channel' => 'sms',
            'status' => 'skipped',
        ]);
    }

    #[Test]
    public function sms_does_not_accept_a_subject(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        // Accepting one would silently discard it at send time.
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Sms->value,
            'subject' => 'Nope',
            'body' => 'x',
        ])->assertStatus(422)->assertJsonPath('errors.0.field', 'subject');
    }

    #[Test]
    public function sms_falls_back_to_the_log_driver_until_it_is_keyed(): void
    {
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['phone_e164' => '+919876543210']);

        $message = $this->queueSms($lead);
        $this->runJob($message);

        // No credentials set, so nothing is sent and the row says so.
        $this->assertSame('log', $message->fresh()->provider);
    }
}
