<?php

namespace Tests\Feature\Reports;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\RoleName;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Reports\BusinessReportService;
use App\Support\Reporting\ReportPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Campaign performance report (Phase 27 completion, FR-RPT-02/05/06).
 *
 * The report Phase 27 could not finish until the campaign engine existed. The
 * figures are checked against hand-computed fixtures, and the two rates most
 * likely to be got wrong are asserted deliberately: delivery is over messages
 * SENT (not targeted), and skip is over the whole audience TARGETED.
 */
class CampaignReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());
        $this->actingAs($user->fresh(), 'sanctum');

        return $user->fresh();
    }

    private function reports(): BusinessReportService
    {
        return app(BusinessReportService::class);
    }

    private function thisMonth(): ReportPeriod
    {
        return ReportPeriod::between(Carbon::now()->startOfMonth(), Carbon::now()->endOfDay());
    }

    /**
     * A campaign with its engine counters set. `total_*` and `started_at` are
     * guarded rather than fillable - the engine owns them - so they are written
     * with forceFill, which is exactly what the engine does.
     *
     * @param  array<string, mixed>  $counts
     */
    private function ranCampaign(array $counts, ?Carbon $startedAt = null, string $name = 'Campaign'): Campaign
    {
        $campaign = Campaign::create([
            'tenant_id' => 0,
            'name' => $name,
            'channel' => Channel::Email->value,
            'status' => CampaignStatus::Completed->value,
        ]);

        $campaign->forceFill(array_merge([
            'started_at' => $startedAt ?? now(),
            'completed_at' => now(),
            'total_targeted' => 0,
            'total_queued' => 0,
            'total_sent' => 0,
            'total_delivered' => 0,
            'total_failed' => 0,
            'total_skipped' => 0,
            'cost' => 0,
        ], $counts))->save();

        return $campaign->refresh();
    }

    // -----------------------------------------------------------------------
    // The formulas
    // -----------------------------------------------------------------------

    #[Test]
    public function it_reports_every_rate_with_its_denominator(): void
    {
        $this->ranCampaign([
            'total_targeted' => 100,
            'total_queued' => 100,
            'total_sent' => 80,      // 20 skipped by the gate
            'total_delivered' => 60, // of the 80 sent
            'total_failed' => 5,
            'total_skipped' => 20,
            'cost' => 12.5,
        ], name: 'August offer');

        $data = $this->reports()->campaignPerformance($this->thisMonth());

        $this->assertCount(1, $data['campaigns']);
        $row = $data['campaigns'][0];

        $this->assertSame('August offer', $row['name']);

        // Reach: sent over targeted.
        $this->assertSame(80.0, $row['send_rate']['value']);
        $this->assertSame('recipients targeted', $row['send_rate']['of']);

        // Delivery is over SENT, not targeted - a recipient the DNC gate skipped
        // was never sent to, and counting them against delivery would blame the
        // campaign for obeying a suppression.
        $this->assertSame(75.0, $row['delivery_rate']['value']);
        $this->assertSame(60, $row['delivery_rate']['numerator']);
        $this->assertSame(80, $row['delivery_rate']['denominator']);
        $this->assertSame('messages sent', $row['delivery_rate']['of']);

        // 5 / 80 = 6.25 -> 6.3 (rounded to one place).
        $this->assertSame(6.3, $row['failure_rate']['value']);

        // Skip is over the whole audience: "how much did we refuse to contact".
        $this->assertSame(20.0, $row['skip_rate']['value']);
        $this->assertSame('recipients targeted', $row['skip_rate']['of']);

        $this->assertSame(12.5, $row['cost']);
    }

    #[Test]
    public function the_totals_are_the_sum_of_the_rows(): void
    {
        $this->ranCampaign(['total_targeted' => 100, 'total_sent' => 90, 'total_delivered' => 70, 'total_skipped' => 10, 'cost' => 5]);
        $this->ranCampaign(['total_targeted' => 40, 'total_sent' => 40, 'total_delivered' => 30, 'total_failed' => 2, 'cost' => 3.25]);

        $totals = $this->reports()->campaignPerformance($this->thisMonth())['totals'];

        $this->assertSame(2, $totals['campaigns']);
        $this->assertSame(140, $totals['targeted']);
        $this->assertSame(130, $totals['sent']);
        $this->assertSame(100, $totals['delivered']);
        $this->assertSame(10, $totals['skipped']);
        $this->assertSame(8.25, $totals['cost']);
    }

    // -----------------------------------------------------------------------
    // Scoping and edge cases
    // -----------------------------------------------------------------------

    #[Test]
    public function a_campaign_that_never_ran_has_no_performance(): void
    {
        // A draft that never started is not a row of zeroes - it is absent.
        Campaign::create([
            'tenant_id' => 0,
            'name' => 'Never sent',
            'channel' => Channel::Sms->value,
            'status' => CampaignStatus::Draft->value,
        ]);

        $this->assertCount(0, $this->reports()->campaignPerformance($this->thisMonth())['campaigns']);
    }

    #[Test]
    public function a_campaign_that_ran_outside_the_period_is_excluded(): void
    {
        $this->ranCampaign(['total_targeted' => 50, 'total_sent' => 50], startedAt: Carbon::now()->subMonths(2));

        $this->assertCount(0, $this->reports()->campaignPerformance($this->thisMonth())['campaigns']);
    }

    #[Test]
    public function delivery_rate_is_null_when_nothing_was_sent(): void
    {
        // Everyone in the audience was suppressed. Delivery over zero sent is an
        // em dash, not 0% - "nothing was sent" and "sent and none delivered" are
        // different facts (FR-RPT-06).
        $this->ranCampaign(['total_targeted' => 10, 'total_sent' => 0, 'total_skipped' => 10]);

        $row = $this->reports()->campaignPerformance($this->thisMonth())['campaigns'][0];

        $this->assertNull($row['delivery_rate']['value']);
        $this->assertSame(100.0, $row['skip_rate']['value']);   // 10 of 10 targeted.
    }

    // -----------------------------------------------------------------------
    // The endpoint
    // -----------------------------------------------------------------------

    #[Test]
    public function the_endpoint_is_gated_on_reports_business(): void
    {
        $this->ranCampaign(['total_targeted' => 10, 'total_sent' => 10]);
        $this->actingAsRole(RoleName::Manager);

        $this->getJson('/api/v1/reports/campaigns')
            ->assertOk()
            ->assertJsonPath('data.campaigns.totals.campaigns', 1)
            ->assertJsonStructure(['data' => ['period', 'campaigns' => ['campaigns', 'totals']]]);
    }

    #[Test]
    public function a_telecaller_cannot_open_the_campaign_report(): void
    {
        // reports.business is money; a telecaller holds reports.view only.
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/reports/campaigns')->assertStatus(403);
    }
}
