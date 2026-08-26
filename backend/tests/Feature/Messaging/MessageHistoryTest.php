<?php

namespace Tests\Feature\Messaging;

use App\Enums\Channel;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-lead message history — GET /api/v1/messages (FR-COMM-05).
 *
 * EmailSendTest already covers that the endpoint is scoped through the lead.
 * What this file covers is the shape the history screen reads: the lead on
 * each row, which the per-lead thread has no need for and therefore did not
 * load, and the filters the screen actually sends.
 *
 * Nothing here depends on the clock, so no time is frozen.
 */
class MessageHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function telecaller(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        return $user->fresh();
    }

    #[Test]
    public function the_history_names_the_lead_on_every_row(): void
    {
        $telecaller = $this->telecaller();
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id, 'name' => 'Ramesh Kumar']);

        Message::factory()->for($lead)->sent()->create();

        $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('data.items.0.lead.id', $lead->id)
            // A cross-lead list that could only show `lead_id` would be a
            // column of numbers.
            ->assertJsonPath('data.items.0.lead.name', 'Ramesh Kumar');
    }

    #[Test]
    public function the_per_lead_thread_does_not_carry_the_lead_it_already_knows(): void
    {
        // `whenLoaded`, so adding the lead to the history must not add a query
        // or a payload to the thread on the lead's own page.
        $telecaller = $this->telecaller();
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id]);

        Message::factory()->for($lead)->sent()->create();

        $this->actingAs($telecaller, 'sanctum')
            ->getJson("/api/v1/leads/{$lead->id}/messages")
            ->assertOk()
            ->assertJsonMissingPath('data.items.0.lead');
    }

    #[Test]
    public function the_history_filters_by_channel_and_delivery_state(): void
    {
        $telecaller = $this->telecaller();
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id]);

        Message::factory()->for($lead)->sent()->create(['channel' => Channel::Email->value]);
        $failedSms = Message::factory()->for($lead)->create([
            'channel' => Channel::Sms->value,
            'status' => 'failed',
            'failure_reason' => 'Handset unreachable.',
        ]);

        $this->actingAs($telecaller, 'sanctum');

        $byChannel = $this->getJson('/api/v1/messages?filter[channel]=sms')
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$failedSms->id], array_column($byChannel, 'id'));

        $byStatus = $this->getJson('/api/v1/messages?filter[status]=failed')
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$failedSms->id], array_column($byStatus, 'id'));

        // The screen puts the reason under the badge - chasing a failed send
        // should not need a second screen.
        $this->assertSame('Handset unreachable.', $byStatus[0]['failure_reason']);
    }

    #[Test]
    public function a_suppressed_send_is_in_the_history_with_its_reason(): void
    {
        // BR-DNC-05: a refused send is recorded, and the record is only an
        // audit trail if a person can actually find it.
        $telecaller = $this->telecaller();
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id]);

        Message::factory()->for($lead)->skipped('do_not_contact')->create();

        $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/messages?filter[status]=skipped')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.skip_reason', 'do_not_contact');
    }

    #[Test]
    public function an_unsupported_filter_is_refused_rather_than_ignored(): void
    {
        // A silently dropped filter on a scoped list shows MORE than was asked
        // for, so the endpoint must 422 (QueryOptions).
        $this->actingAs($this->telecaller(), 'sanctum')
            ->getJson('/api/v1/messages?filter[recipient]=someone@example.com')
            ->assertStatus(422);
    }
}
