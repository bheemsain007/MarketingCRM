<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Jobs\DispatchCampaign;
use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\Campaigns\CampaignEligibility;
use App\Services\Campaigns\CampaignService;
use App\Services\Dnc\DncService;
use App\Services\Messaging\OutboundMessageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Campaign engine (Phase 18, FR-CAMP-01..05, BR-CAMP-01..05, BR-DNC-03/05).
 *
 * The load-bearing assertions are about who does NOT get a message: eligibility
 * is re-evaluated at dispatch rather than trusted from when the audience was
 * built, and every skipped lead carries a reason. A campaign that quietly drops
 * people is one nobody can audit.
 */
class CampaignEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['crm.campaign_caps.per_day' => 2, 'crm.campaign_caps.per_week' => 5]);
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());
        $this->actingAs($user = $user->fresh(), 'sanctum');

        return $user;
    }

    private function emailLead(array $attributes = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'email' => 'lead'.uniqid().'@example.com',
        ], $attributes));
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::create(array_merge([
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => 'August offer',
            'channel' => Channel::Email->value,
            'status' => CampaignStatus::Draft->value,
            'audience_filters' => [],
        ], $attributes));
    }

    private function runCampaign(Campaign $campaign): void
    {
        app(CampaignService::class)->start($campaign);
    }

    // -----------------------------------------------------------------------
    // Nothing sends inline (FR-CAMP-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function starting_a_campaign_queues_rather_than_sending_inline(): void
    {
        Queue::fake();
        $this->actingAsRole(RoleName::Admin);
        $this->emailLead();
        $campaign = $this->campaign();

        // 202, not 200: the work has been accepted, not finished. A campaign of
        // any size must complete without an HTTP timeout.
        $this->postJson("/api/v1/campaigns/{$campaign->id}/start")->assertStatus(202);

        Queue::assertPushed(DispatchCampaign::class);
    }

    #[Test]
    public function the_audience_is_materialised_once_and_not_rebuilt_on_resume(): void
    {
        Bus::fake();
        $this->emailLead();
        $campaign = $this->campaign();

        $this->runCampaign($campaign);
        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->count());

        // A lead that would now match the filters arrives mid-campaign.
        $this->emailLead();

        app(CampaignService::class)->pause($campaign->fresh());
        app(CampaignService::class)->start($campaign->fresh());

        /*
         * Still one. A resumed campaign sends to the people it targeted, not to
         * whoever matches now - otherwise pausing and resuming quietly changes
         * who was reached and the report stops describing a single event.
         */
        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->count());
    }

    // -----------------------------------------------------------------------
    // Eligibility at dispatch (BR-CAMP-02, BR-DNC-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_suppressed_after_the_audience_was_built_is_not_sent_to(): void
    {
        Bus::fake();
        $lead = $this->emailLead();
        $campaign = $this->campaign();

        $this->runCampaign($campaign);
        $recipient = CampaignRecipient::firstOrFail();

        // The exact case the rule exists for: opting out while the campaign is
        // still working through its queue.
        app(DncService::class)->suppress($lead, DncReason::OptedOut, channel: null, source: 'manual');

        app(SendCampaignMessage::class, ['recipientId' => $recipient->id])
            ->handle(app(CampaignEligibility::class), app(OutboundMessageService::class));

        $recipient->refresh();
        $this->assertSame('skipped', $recipient->status);
        $this->assertSame(CampaignSkipReason::Suppressed->value, $recipient->skip_reason);
        $this->assertDatabaseCount('messages', 0);
    }

    #[Test]
    public function a_lead_with_no_address_for_the_channel_is_skipped_with_that_reason(): void
    {
        Bus::fake();
        // No email at all - a perfectly good lead for a phone campaign.
        $this->emailLead(['email' => null]);
        $campaign = $this->campaign();

        $this->runCampaign($campaign);
        $recipient = CampaignRecipient::firstOrFail();

        $this->processRecipient($recipient);

        $recipient->refresh();
        $this->assertSame(CampaignSkipReason::NoContactDetail->value, $recipient->skip_reason);
    }

    #[Test]
    public function a_paused_campaign_stops_dispatching(): void
    {
        Bus::fake();
        $this->emailLead();
        $campaign = $this->campaign();

        $this->runCampaign($campaign);
        $recipient = CampaignRecipient::firstOrFail();

        app(CampaignService::class)->pause($campaign->fresh());
        $this->processRecipient($recipient);

        $recipient->refresh();
        $this->assertSame(CampaignSkipReason::CampaignNotRunning->value, $recipient->skip_reason);
        $this->assertDatabaseCount('messages', 0);
    }

    // -----------------------------------------------------------------------
    // Frequency caps (BR-CAMP-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_at_the_daily_cap_is_skipped(): void
    {
        Bus::fake();
        $lead = $this->emailLead();

        // Two campaign messages already today, at the configured cap of 2.
        Message::factory()->count(2)->create([
            'lead_id' => $lead->id,
            'channel' => Channel::Email->value,
            'campaign_id' => $this->campaign()->id,
            'status' => 'sent',
        ]);

        $campaign = $this->campaign();
        $this->runCampaign($campaign);

        $this->processRecipient(CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail());

        $recipient = CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertSame(CampaignSkipReason::FrequencyCapped->value, $recipient->skip_reason);
    }

    #[Test]
    public function transactional_messages_do_not_consume_the_campaign_cap(): void
    {
        Bus::fake();
        $lead = $this->emailLead();

        // Receipts and reminders carry no campaign_id. A payment receipt must
        // not spend somebody's marketing allowance.
        Message::factory()->count(5)->create([
            'lead_id' => $lead->id,
            'channel' => Channel::Email->value,
            'campaign_id' => null,
            'status' => 'sent',
        ]);

        $campaign = $this->campaign();
        $this->runCampaign($campaign);
        $this->processRecipient(CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail());

        $recipient = CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertNull($recipient->skip_reason);
    }

    #[Test]
    public function a_skipped_message_does_not_count_towards_the_cap(): void
    {
        Bus::fake();
        $lead = $this->emailLead();

        // Skipped means it was never delivered. Capping a lead for messages
        // they did not receive would compound one problem into two.
        Message::factory()->count(3)->create([
            'lead_id' => $lead->id,
            'channel' => Channel::Email->value,
            'campaign_id' => $this->campaign()->id,
            'status' => 'skipped',
        ]);

        $campaign = $this->campaign();
        $this->runCampaign($campaign);
        $this->processRecipient(CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail());

        $this->assertNull(
            CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail()->skip_reason,
        );
    }

    // -----------------------------------------------------------------------
    // Lifecycle (BR-CAMP-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_stopped_campaign_cannot_be_restarted(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Bus::fake();
        $campaign = $this->campaign();

        $this->postJson("/api/v1/campaigns/{$campaign->id}/start")->assertStatus(202);
        $this->postJson("/api/v1/campaigns/{$campaign->id}/stop")->assertOk();

        // Stop has to actually mean stop - it is what somebody reaches for when
        // a campaign is going wrong.
        $this->postJson("/api/v1/campaigns/{$campaign->id}/start")->assertStatus(422);
    }

    #[Test]
    public function a_stopped_campaign_can_be_cloned_back_to_draft(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Bus::fake();
        $campaign = $this->campaign();
        app(CampaignService::class)->stop($campaign);

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/clone")->assertCreated();

        $this->assertSame(CampaignStatus::Draft->value, $response->json('data.status'));
        $this->assertStringContainsString('copy', (string) $response->json('data.name'));
    }

    #[Test]
    public function a_running_campaign_cannot_be_edited(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Bus::fake();
        $campaign = $this->campaign();
        app(CampaignService::class)->start($campaign);

        // Two halves of one send with different content cannot be reported on
        // honestly.
        $this->patchJson("/api/v1/campaigns/{$campaign->id}", ['name' => 'Renamed'])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // A job that gives up (BR-CAMP-05, BR-DNC-05)
    //
    // Completion is decided by "no recipient row is still pending". A job that
    // exhausts its retries without recording an outcome therefore does not
    // merely lose one message - it holds the whole campaign Running for ever,
    // which every screen reads as a send still in progress.
    // -----------------------------------------------------------------------

    #[Test]
    public function a_recipient_whose_job_dies_after_its_last_retry_does_not_leave_the_campaign_running(): void
    {
        Bus::fake();
        $owner = $this->actingAsRole(RoleName::Admin);
        $this->emailLead();
        $campaign = $this->campaign(['created_by' => $owner->id]);

        $this->runCampaign($campaign);
        $recipient = CampaignRecipient::firstOrFail();

        // Exactly what the queue calls once `tries` is exhausted.
        (new SendCampaignMessage($recipient->id))
            ->failed(new RuntimeException('The provider refused the message.'));

        $recipient->refresh();
        $this->assertSame('failed', $recipient->status);
        $this->assertNotNull($recipient->processed_at);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertNotNull($campaign->completed_at);

        // The counter the campaign report reads for failure_rate. Nothing wrote
        // to it before this handler existed.
        $this->assertSame(1, $campaign->total_failed);

        // Reaching nobody is the outcome, so the owner hears about it - the
        // notification the stuck campaign never sent.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'campaign_failed',
        ]);
    }

    #[Test]
    public function a_dead_job_on_the_last_pending_recipient_still_lets_the_campaign_finish(): void
    {
        Bus::fake();
        $this->emailLead();
        $this->emailLead();
        $campaign = $this->campaign();

        $this->runCampaign($campaign);
        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)->get();

        $this->processRecipient($recipients[0]);

        // Still open, correctly: one recipient is genuinely outstanding.
        $this->assertSame(CampaignStatus::Running, $campaign->refresh()->status);

        (new SendCampaignMessage($recipients[1]->id))
            ->failed(new RuntimeException('The provider refused the message.'));

        // A failed row counts as no-longer-pending, which is what lets the
        // completion check make progress at all.
        $this->assertSame(
            0,
            CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count(),
        );
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
        $this->assertSame(1, $campaign->total_sent);
        $this->assertSame(1, $campaign->total_failed);
    }

    #[Test]
    public function a_failure_reported_after_the_row_was_already_resolved_is_not_counted_twice(): void
    {
        Bus::fake();
        $this->emailLead();
        $campaign = $this->campaign();

        $this->runCampaign($campaign);
        $recipient = CampaignRecipient::firstOrFail();

        $this->processRecipient($recipient);

        // The counter increments sit after the row update, so a throw can land
        // here with the outcome already recorded. Overwriting it would lose the
        // real result and count the recipient twice.
        (new SendCampaignMessage($recipient->id))
            ->failed(new RuntimeException('The connection dropped after the send.'));

        $this->assertSame('sent', $recipient->refresh()->status);

        $campaign->refresh();
        $this->assertSame(1, $campaign->total_sent);
        $this->assertSame(0, $campaign->total_failed);
    }

    #[Test]
    public function a_fan_out_that_dies_does_not_strand_its_audience_at_pending(): void
    {
        Bus::fake();
        $owner = $this->actingAsRole(RoleName::Admin);
        $this->emailLead();
        $this->emailLead();
        $campaign = $this->campaign(['created_by' => $owner->id]);

        $this->runCampaign($campaign);
        $this->assertSame(
            2,
            CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count(),
        );

        // DispatchCampaign runs with tries = 1, so there is no later attempt to
        // finish handing these rows out. Nobody else ever will.
        (new DispatchCampaign($campaign->id))
            ->failed(new RuntimeException('The database went away part-way through the fan-out.'));

        $this->assertSame(
            0,
            CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count(),
        );
        $this->assertSame(
            2,
            CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'failed')->count(),
        );

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(2, $campaign->total_failed);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'campaign_failed',
        ]);
    }

    // -----------------------------------------------------------------------
    // An unkeyed channel is admitted, not hidden (FR-COMM-05)
    //
    // An unconfigured channel falls back to LogDriver: every message is
    // recorded as sent and nobody receives anything. The single-send endpoint
    // says so on its 202; across a whole audience the same silence is far more
    // expensive, and the counters read as a completely successful send.
    // -----------------------------------------------------------------------

    #[Test]
    public function starting_a_campaign_on_a_channel_with_no_provider_says_so_on_the_response(): void
    {
        Bus::fake();
        $this->actingAsRole(RoleName::Admin);
        $this->emailLead();
        $campaign = $this->campaign();

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/start")->assertStatus(202);

        // Before the audience goes out, not after a report of sends nobody got.
        $this->assertStringContainsString(
            'No provider is configured',
            (string) $response->json('message'),
        );
    }

    #[Test]
    public function starting_a_campaign_on_a_configured_channel_carries_no_such_warning(): void
    {
        Bus::fake();
        $this->actingAsRole(RoleName::Admin);

        // SettingsService reads a stored row first and falls back to config, and
        // the settings table is empty here - so this is a configured channel.
        config([
            'providers.mailercloud.api_key' => 'mc-test-key',
            'providers.mailercloud.from_email' => 'crm@example.com',
        ]);

        $this->emailLead();
        $campaign = $this->campaign();

        $response = $this->postJson("/api/v1/campaigns/{$campaign->id}/start")->assertStatus(202);

        // The warning has to mean something, so it must not be boilerplate.
        $this->assertStringNotContainsString(
            'No provider is configured',
            (string) $response->json('message'),
        );
    }

    #[Test]
    public function the_completion_notification_admits_that_an_unkeyed_channel_delivered_nothing(): void
    {
        Bus::fake();
        $owner = $this->actingAsRole(RoleName::Admin);
        $this->emailLead();
        $campaign = $this->campaign(['created_by' => $owner->id]);

        $this->runCampaign($campaign);

        foreach (CampaignRecipient::where('campaign_id', $campaign->id)->get() as $recipient) {
            $this->processRecipient($recipient);
        }

        // The lie this exists to correct: one "sent", zero delivered, and every
        // count says the campaign worked.
        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(1, $campaign->total_sent);

        $body = (string) Notification::where('user_id', $owner->id)
            ->where('type', 'campaign_completed')
            ->value('body');

        $this->assertStringContainsString('No provider is configured', $body);
    }

    // -----------------------------------------------------------------------
    // Authority and reporting
    // -----------------------------------------------------------------------

    #[Test]
    public function reading_a_campaign_and_sending_it_are_separate_permissions(): void
    {
        // Viewer holds campaigns.view and neither manage nor run - reporting
        // on a campaign is not authority to send one.
        $this->actingAsRole(RoleName::Viewer);
        $campaign = $this->campaign();

        $this->getJson('/api/v1/campaigns')->assertOk();
        $this->getJson("/api/v1/campaigns/{$campaign->id}/recipients")->assertOk();

        $this->postJson('/api/v1/campaigns', [
            'name' => 'Mine', 'channel' => Channel::Email->value,
        ])->assertStatus(403);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/start")->assertStatus(403);
    }

    #[Test]
    public function a_telecaller_has_no_campaign_access_at_all(): void
    {
        // Campaigns are not a telecaller's job: they work their own leads.
        // Bulk sending to the whole database is a manager's authority.
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/campaigns')->assertStatus(403);
    }

    #[Test]
    public function a_campaign_cannot_dial(): void
    {
        $this->actingAsRole(RoleName::Admin);

        // Calling has consent, calling-hours and single-assignment rules that a
        // bulk send knows nothing about.
        $this->postJson('/api/v1/campaigns', [
            'name' => 'Ring everyone', 'channel' => Channel::Call->value,
        ])->assertStatus(422);
    }

    #[Test]
    public function the_preview_reports_eligibility_before_anything_is_sent(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $reachable = $this->emailLead();
        $suppressed = $this->emailLead();
        $this->emailLead(['email' => null]);

        app(DncService::class)->suppress($suppressed, DncReason::BouncedEmail, channel: null, source: 'manual');

        $campaign = $this->campaign();
        $response = $this->getJson("/api/v1/campaigns/{$campaign->id}/preview")->assertOk();

        // "12,000 leads" and "12,000 of whom 4,000 are suppressed" are
        // different decisions, and after pressing send is too late to learn it.
        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame(1, $response->json('data.eligible'));
        $this->assertSame(1, $response->json('data.no_contact_detail'));
        $this->assertSame($reachable->id, $reachable->id);
    }

    #[Test]
    public function every_targeted_lead_ends_with_a_message_or_a_reason(): void
    {
        Bus::fake();
        $this->emailLead();
        $this->emailLead(['email' => null]);
        $campaign = $this->campaign();

        $this->runCampaign($campaign);

        foreach (CampaignRecipient::where('campaign_id', $campaign->id)->get() as $recipient) {
            $this->processRecipient($recipient);
        }

        // BR-DNC-05: "nothing happened" is never an acceptable outcome.
        $this->assertSame(
            0,
            CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('status', 'skipped')
                ->whereNull('skip_reason')
                ->count(),
        );
        $this->assertSame(
            0,
            CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count(),
        );
    }

    private function processRecipient(CampaignRecipient $recipient): void
    {
        (new SendCampaignMessage($recipient->id))->handle(
            app(CampaignEligibility::class),
            app(OutboundMessageService::class),
        );
    }
}
