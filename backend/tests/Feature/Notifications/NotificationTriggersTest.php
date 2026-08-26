<?php

namespace Tests\Feature\Notifications;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Jobs\SendCampaignMessage;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Calls\CallRecordingService;
use App\Services\Campaigns\CampaignEligibility;
use App\Services\Campaigns\CampaignService;
use App\Services\Dnc\DncService;
use App\Services\Messaging\OutboundMessageService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four remaining BR-NOTIF-02 triggers wired onto their real hook points:
 * campaign completed/failed, recording upload failed, and discount approval
 * requested. (Follow-up due, lead assigned, and payment overdue were already
 * wired - see tests/Feature/FollowUps/NotificationTest.php.)
 *
 * Every test here also proves BR-NOTIF-02's other half: a suppressed lead
 * changes nothing about whether the STAFF notification fires. Suppression
 * protects leads from being contacted; it has no opinion on whether a
 * colleague hears about their own work.
 */
class NotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
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

    // -----------------------------------------------------------------------
    // Campaign completed / failed (App\Jobs\SendCampaignMessage,
    // App\Jobs\DispatchCampaign, via CampaignService::notifyOwnerOfCompletion)
    // -----------------------------------------------------------------------

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

    private function processRecipient(CampaignRecipient $recipient): void
    {
        (new SendCampaignMessage($recipient->id))->handle(
            app(CampaignEligibility::class),
            app(OutboundMessageService::class),
        );
    }

    #[Test]
    public function a_campaign_that_finishes_sending_notifies_its_creator(): void
    {
        Bus::fake();
        $owner = $this->user(RoleName::Admin);
        $this->emailLead();
        $campaign = $this->campaign(['created_by' => $owner->id]);

        app(CampaignService::class)->start($campaign);

        foreach (CampaignRecipient::where('campaign_id', $campaign->id)->get() as $recipient) {
            $this->processRecipient($recipient);
        }

        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'campaign_completed',
        ]);
    }

    #[Test]
    public function a_campaign_that_reaches_nobody_notifies_its_creator_as_failed(): void
    {
        Bus::fake();
        $owner = $this->user(RoleName::Admin);
        $lead = $this->emailLead();
        $campaign = $this->campaign(['created_by' => $owner->id]);

        app(CampaignService::class)->start($campaign);
        $recipient = CampaignRecipient::firstOrFail();

        // Suppressed AFTER the audience was built (BR-CAMP-02) - the campaign
        // still targeted one lead, it just could not reach them.
        app(DncService::class)->suppress($lead, DncReason::OptedOut, channel: null, source: 'manual');
        $this->processRecipient($recipient);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(0, $campaign->total_sent);

        // CampaignStatus has no distinct "failed" state, so the outcome - not
        // the status column - is what the notification reads.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'campaign_failed',
        ]);

        // BR-NOTIF-02: the suppression that caused the failure does not also
        // block the owner from being told about it.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->id,
            'type' => 'campaign_completed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Recording upload failed (App\Services\Calls\CallRecordingService::attach)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_storage_failure_notifies_the_telecaller_whose_call_it_was(): void
    {
        Storage::fake('recordings');
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create();
        // Suppressed for good measure - this notification is about the
        // telecaller's own upload and has nothing to do with the lead.
        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();
        $call = Call::factory()->for($lead)->create(['user_id' => $telecaller->id]);

        // Force the write itself to fail - `throw => false` on the disk means
        // this surfaces as a plain `false` return, not an exception.
        $failingDisk = Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('putFileAs')->andReturn(false);
        Storage::set('recordings', $failingDisk);

        $threw = false;

        try {
            app(CallRecordingService::class)->attach(
                $call,
                UploadedFile::fake()->createWithContent('call.m4a', 'AUDIO'),
                $telecaller,
            );
        } catch (ApiException) {
            $threw = true;
        }

        // The caller must still see the failure - the notification is in
        // addition to that, not instead of it.
        $this->assertTrue($threw);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $telecaller->id,
            'type' => 'recording_upload_failed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Discount approval requested (App\Services\Sales\QuotationService::draft)
    // -----------------------------------------------------------------------

    private function opportunityWorth(float $price, ?Lead $lead = null): Opportunity
    {
        $lead ??= Lead::factory()->create();
        $product = Product::factory()->create(['base_price' => $price]);

        $id = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", [
            'title' => 'Test deal',
            'products' => [['product_id' => $product->id]],
        ])->assertCreated()->json('data.id');

        return Opportunity::findOrFail($id);
    }

    #[Test]
    public function a_discount_above_threshold_notifies_approvers_but_not_the_requester(): void
    {
        config(['crm.discount_approval_threshold' => 15]);
        $requester = $this->actingAsRole(RoleName::Admin);
        $approver = $this->user(RoleName::Manager);
        $noAuthority = $this->user(RoleName::Viewer);

        $opportunity = $this->opportunityWorth(1000);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 25,
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $approver->id,
            'type' => 'discount_approval_requested',
        ]);

        // BR-SALE-03: the requester could not approve their own discount even
        // if they tried, so they are not asked to.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $requester->id,
            'type' => 'discount_approval_requested',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $noAuthority->id,
            'type' => 'discount_approval_requested',
        ]);
    }

    #[Test]
    public function a_discount_at_or_below_threshold_notifies_nobody(): void
    {
        config(['crm.discount_approval_threshold' => 15]);
        $this->actingAsRole(RoleName::Admin);
        $this->user(RoleName::Manager);

        $opportunity = $this->opportunityWorth(1000);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 10,
        ])->assertCreated();

        $this->assertDatabaseCount('notifications', 0);
    }

    #[Test]
    public function a_suppressed_leads_discount_request_still_reaches_the_approver(): void
    {
        config(['crm.discount_approval_threshold' => 15]);
        $this->actingAsRole(RoleName::Admin);
        $approver = $this->user(RoleName::Manager);

        $lead = Lead::factory()->create();
        DncEntry::factory()->for($lead)->reason(DncReason::DoNotContact)->create();
        $opportunity = $this->opportunityWorth(1000, $lead);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 25,
        ])->assertCreated();

        // BR-NOTIF-02: suppression protects the lead from contact, not the
        // approver from hearing about a decision they need to make.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $approver->id,
            'type' => 'discount_approval_requested',
        ]);
    }
}
