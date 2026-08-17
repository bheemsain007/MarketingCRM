<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Jobs\DispatchCampaign;
use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Lead;
use App\Services\Campaigns\CampaignEligibility;
use App\Services\Campaigns\CampaignService;
use App\Services\Messaging\OutboundMessageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A lead archived mid-campaign still gets an outcome (BR-CAMP-01, BR-DNC-05).
 *
 * The audience is materialised once and never rebuilt, so a lead soft-deleted
 * afterwards keeps its recipient row. The row used to stay `pending` for ever:
 * the send job could not resolve the lead and simply returned, which left the
 * campaign one outstanding recipient short of completion permanently.
 *
 * Two properties are load-bearing here. Every targeted lead ends with a message
 * or a REASON - BR-CAMP-01 names "not archived" as an eligibility condition, so
 * an archived lead is a skip, not a silence. And completion has to stay
 * reachable, because a campaign stuck at 99% is a report nobody can close.
 */
class ArchivedLeadRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Frozen because processed_at and completed_at are asserted on, and a
        // test that reads the wall clock twice is testing the clock.
        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function emailLead(): Lead
    {
        return Lead::factory()->create(['email' => 'lead'.uniqid().'@example.com']);
    }

    private function campaign(): Campaign
    {
        return Campaign::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => 'August offer',
            'channel' => Channel::Email->value,
            'status' => CampaignStatus::Draft->value,
            'audience_filters' => [],
        ]);
    }

    private function processRecipient(CampaignRecipient $recipient): void
    {
        (new SendCampaignMessage($recipient->id))->handle(
            app(CampaignEligibility::class),
            app(OutboundMessageService::class),
        );
    }

    #[Test]
    public function a_lead_archived_after_the_audience_was_built_is_skipped_with_a_reason(): void
    {
        Bus::fake();
        $lead = $this->emailLead();
        $campaign = $this->campaign();

        app(CampaignService::class)->start($campaign);
        $recipient = CampaignRecipient::firstOrFail();

        // Archived while the campaign is still working through its queue -
        // ordinary housekeeping, not an edge case.
        $lead->delete();

        $this->processRecipient($recipient);

        $recipient->refresh();

        // A reason, not a silence: "nothing happened" is not an outcome anybody
        // can report on (BR-DNC-05).
        $this->assertSame('skipped', $recipient->status);
        $this->assertSame(CampaignSkipReason::LeadArchived->value, $recipient->skip_reason);
        $this->assertNotNull($recipient->processed_at);
        $this->assertDatabaseCount('messages', 0);

        // The skip breakdown is how an operator sees this happened at all.
        $this->assertSame(1, $campaign->refresh()->total_skipped);
    }

    #[Test]
    public function a_campaign_whose_last_pending_lead_was_archived_still_completes(): void
    {
        Bus::fake();
        $reachable = $this->emailLead();
        $archived = $this->emailLead();
        $campaign = $this->campaign();

        app(CampaignService::class)->start($campaign);
        $archived->delete();

        foreach (CampaignRecipient::where('campaign_id', $campaign->id)->get() as $recipient) {
            $this->processRecipient($recipient);
        }

        // The bug's real cost: one unresolvable row held the whole campaign
        // open for ever, so it never reached a terminal state and its report
        // never described a finished event.
        $this->assertSame(
            0,
            CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->count(),
        );
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
        $this->assertNotNull($campaign->completed_at);
        $this->assertSame($reachable->id, CampaignRecipient::where('status', 'sent')->firstOrFail()->lead_id);
    }

    #[Test]
    public function the_fan_out_resolves_an_archived_lead_rather_than_leaving_it_pending(): void
    {
        /*
         * Only the fan-out is faked, so `start()` materialises the audience
         * without immediately sending to it on the sync queue - otherwise the
         * lead is contacted before the test can archive it, and the test proves
         * nothing. SendCampaignMessage is left real: this test exists to walk
         * the actual production path, fan-out into send job, rather than
         * calling the job by hand as the tests above do.
         */
        Bus::fake([DispatchCampaign::class]);

        $lead = $this->emailLead();
        $campaign = $this->campaign();

        app(CampaignService::class)->start($campaign);
        $lead->delete();

        (new DispatchCampaign($campaign->id))->handle();

        $this->assertSame(
            CampaignSkipReason::LeadArchived->value,
            CampaignRecipient::firstOrFail()->skip_reason,
        );
        $this->assertSame(CampaignStatus::Completed, $campaign->refresh()->status);
    }

    #[Test]
    public function the_archived_skip_reason_is_reportable_like_every_other_one(): void
    {
        // The enum is a closed set precisely because these are grouped by in
        // the campaign report - a case without a label would render as a raw
        // database string in front of an operator.
        $this->assertNotSame('', CampaignSkipReason::LeadArchived->label());

        // Not transient: archiving is a decision somebody made about the lead,
        // not a window that reopens tomorrow like a frequency cap. A re-run
        // should not retry it.
        $this->assertFalse(CampaignSkipReason::LeadArchived->isTransient());
    }
}
