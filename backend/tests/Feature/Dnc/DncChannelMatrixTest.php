<?php

namespace Tests\Feature\Dnc;

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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mandatory DNC suite (TESTING section 4.1, FR-DNC-01, BR-DNC-01/02/05).
 *
 * TESTING calls this "the highest-priority suite" and asks for one test **per
 * channel**, not one test for the gate. The distinction matters: the gate is
 * shared, but a channel that quietly bypassed it would be the single worst
 * defect this system can have, and "it uses the same service" is an argument,
 * not evidence.
 *
 * Covered here: Email, SMS, WhatsApp, RCS, Voice and AI Calling through the
 * send path. Human calling and the auto dialer are covered in
 * `Feature\Calls\*`. **Scheduled campaigns are not covered anywhere** - Phase
 * 18 does not exist yet, so that row of section 4.1 is genuinely outstanding
 * rather than assumed (T-61).
 */
class DncChannelMatrixTest extends TestCase
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

    /** Every channel a message can be sent on. */
    public static function messageChannels(): array
    {
        return [
            'email' => [Channel::Email],
            'sms' => [Channel::Sms],
            'whatsapp' => [Channel::WhatsApp],
            'rcs' => [Channel::Rcs],
            'voice' => [Channel::Voice],
            'ai call' => [Channel::AiCall],
        ];
    }

    // -----------------------------------------------------------------------
    // One test per channel (FR-DNC-01)
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('messageChannels')]
    public function a_suppressed_lead_is_refused_on_every_channel(Channel $channel): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $lead = Lead::factory()->create([
            'phone_e164' => '+919876500001',
            'email' => 'lead@example.com',
        ]);

        // Do Not Contact blocks everything, in every context.
        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => $channel->value,
            'body' => 'Hello',
        ])->assertStatus(403);

        // BR-DNC-05: the refusal is on record, not a silent nothing.
        $this->assertDatabaseHas('messages', [
            'lead_id' => $lead->id,
            'channel' => $channel->value,
            'status' => 'skipped',
            'skip_reason' => 'suppressed',
        ]);

        Queue::assertNothingPushed();
    }

    #[Test]
    #[DataProvider('messageChannels')]
    public function an_unsuppressed_lead_is_allowed_on_every_channel(Channel $channel): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $lead = Lead::factory()->create([
            'phone_e164' => '+919876500002',
            'email' => 'lead@example.com',
        ]);

        // The control case. Without it, a gate that refused EVERYTHING would
        // pass every test above and look like excellent compliance.
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => $channel->value,
            'body' => 'Hello',
        ])->assertStatus(202);

        Queue::assertPushed(SendMessage::class);
    }

    // -----------------------------------------------------------------------
    // Reason x channel, at the send path rather than in the enum (BR-DNC-02)
    // -----------------------------------------------------------------------

    /** @return array<string, array{DncReason, array<int, Channel>, array<int, Channel>}> */
    public static function reasonMatrix(): array
    {
        $phone = [Channel::Call, Channel::AiCall, Channel::Sms, Channel::WhatsApp, Channel::Rcs, Channel::Voice];

        return [
            // A wrong phone number says nothing about the email address.
            'wrong number blocks phone, not email' => [
                DncReason::WrongNumber, $phone, [Channel::Email],
            ],
            'invalid number blocks phone, not email' => [
                DncReason::InvalidNumber, $phone, [Channel::Email],
            ],
            // A hard bounce says nothing about the phone number.
            'bounced email blocks email only' => [
                DncReason::BouncedEmail, [Channel::Email], $phone,
            ],
            'not interested blocks everything' => [
                DncReason::NotInterested, array_merge($phone, [Channel::Email]), [],
            ],
            'opted out blocks everything' => [
                DncReason::OptedOut, array_merge($phone, [Channel::Email]), [],
            ],
        ];
    }

    /**
     * @param  array<int, Channel>  $blocked
     * @param  array<int, Channel>  $allowed
     */
    #[Test]
    #[DataProvider('reasonMatrix')]
    public function the_reason_channel_matrix_holds_at_the_gate(
        DncReason $reason,
        array $blocked,
        array $allowed,
    ): void {
        $lead = Lead::factory()->create([
            'phone_e164' => '+919876500003',
            'email' => 'lead@example.com',
        ]);

        DncEntry::factory()->for($lead)->reason($reason)->create();

        $dnc = app(DncService::class);

        // Asked of the service directly rather than through six HTTP calls:
        // this is the rule itself, and every outbound path routes through it
        // (BR-DNC-01, ADR-E).
        foreach ($blocked as $channel) {
            $this->assertFalse(
                $dnc->canContact($lead->fresh(), $channel),
                sprintf('%s should block %s', $reason->value, $channel->value),
            );
        }

        foreach ($allowed as $channel) {
            $this->assertTrue(
                $dnc->canContact($lead->fresh(), $channel),
                sprintf('%s should NOT block %s', $reason->value, $channel->value),
            );
        }
    }

    #[Test]
    public function a_channel_specific_opt_out_blocks_only_that_channel(): void
    {
        $lead = Lead::factory()->create();

        // An SMS opt-out is not a WhatsApp opt-out. Treating it as one loses
        // reachable customers; treating a global opt-out as channel-specific
        // contacts people who said stop.
        DncEntry::factory()->for($lead)->forChannel(Channel::Sms)->create();

        $dnc = app(DncService::class);

        $this->assertFalse($dnc->canContact($lead, Channel::Sms));
        $this->assertTrue($dnc->canContact($lead, Channel::WhatsApp));
        $this->assertTrue($dnc->canContact($lead, Channel::Email));
    }

    #[Test]
    public function suppression_on_one_channel_leaves_the_others_sendable(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $lead = Lead::factory()->create([
            'phone_e164' => '+919876500004',
            'email' => 'lead@example.com',
        ]);

        DncEntry::factory()->for($lead)->forChannel(Channel::Sms)->create();

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Sms->value, 'body' => 'x',
        ])->assertStatus(403);

        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::WhatsApp->value, 'body' => 'x',
        ])->assertStatus(202);
    }

    // -----------------------------------------------------------------------
    // Dispatch-time re-check, per channel (BR-DNC-03)
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('messageChannels')]
    public function a_lead_suppressed_after_queueing_is_not_sent_on_any_channel(Channel $channel): void
    {
        $this->actingAsAdmin();

        $lead = Lead::factory()->create([
            'phone_e164' => '+919876500005',
            'email' => 'lead@example.com',
        ]);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => $channel->value, 'body' => 'x',
        ])->assertStatus(202);

        $message = Message::where('lead_id', $lead->id)->firstOrFail();

        // They opt out while it sits in the queue. BR-DNC-03 exists for exactly
        // this window, and it has to hold on every channel - not just the one
        // it was first written for.
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

    // -----------------------------------------------------------------------
    // Idempotency (TESTING section 4.6)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_retried_send_job_does_not_send_twice(): void
    {
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['email' => 'lead@example.com']);

        Queue::fake();
        $this->postJson("/api/v1/leads/{$lead->id}/messages", [
            'channel' => Channel::Email->value, 'body' => 'x',
        ])->assertStatus(202);

        $message = Message::firstOrFail();
        $drivers = app(MessageDriverManager::class);
        $dnc = app(DncService::class);

        (new SendMessage($message->id))->handle($drivers, $dnc);
        $firstSentAt = $message->fresh()->sent_at;

        // The queue redelivers. The job guards on status rather than trying
        // again - a message already `sent` is not `queued`.
        (new SendMessage($message->id))->handle($drivers, $dnc);

        $message->refresh();
        $this->assertSame('sent', $message->status);
        $this->assertEquals($firstSentAt, $message->sent_at);
        $this->assertSame(1, Message::count());
    }

    #[Test]
    public function the_idempotency_key_is_unique_in_the_database(): void
    {
        $lead = Lead::factory()->create();
        $first = Message::factory()->for($lead)->create();

        // The constraint, not an application check, is what makes double
        // sending structurally impossible (ARCHITECTURE section 6).
        $this->expectException(UniqueConstraintViolationException::class);

        Message::factory()->for($lead)->create(['idempotency_key' => $first->idempotency_key]);
    }
}
