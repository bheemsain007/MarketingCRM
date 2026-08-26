<?php

namespace Tests\Feature\Reports;

use App\Enums\CallStatus;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Opportunity;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReportDailyAggregate;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Reports\BusinessReportService;
use App\Services\Reports\ReportAggregationService;
use App\Support\Reporting\ReportPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Report pre-aggregation (FR-RPT-05).
 *
 * The bar is that BusinessReportService::summary()/revenue() return EXACTLY
 * the same numbers whether they scan calls/messages/lead_status_history
 * directly or read report_daily_aggregates - a dashboard that disagrees with
 * itself depending on how recently the scheduler last ran is worse than a slow
 * one. "Today" is frozen because the fully-past/touches-today branch this
 * whole feature hinges on is a date comparison, and a test that floats with
 * the real clock can flake depending on when it happens to run.
 */
class ReportPreAggregationTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'Asia/Kolkata';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::create(2026, 8, 20, 9, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function periodFor(string $fromDate, string $toDate): ReportPeriod
    {
        return ReportPeriod::between(
            Carbon::parse($fromDate, self::TIMEZONE)->startOfDay(),
            Carbon::parse($toDate, self::TIMEZONE)->endOfDay(),
            self::TIMEZONE,
        );
    }

    /**
     * One full slate of dashboard-tile data landing on the given ORG_TIMEZONE
     * calendar date - a lead, calls (one connected, one not, one AI), follow-
     * ups (scheduled/completed/missed), messages on two channels, a sale and a
     * partial payment. Touches every column report_daily_aggregates has.
     */
    private function seedDay(string $date): void
    {
        $at = Carbon::parse($date, self::TIMEZONE)->setTime(12, 0)->utc();

        $lead = Lead::factory()->create(['created_at' => $at]);

        Call::factory()->for($lead)->create([
            'status' => CallStatus::Connected->value, 'duration_seconds' => 130, 'started_at' => $at,
        ]);
        Call::factory()->for($lead)->create([
            'status' => CallStatus::NoAnswer->value, 'duration_seconds' => 0, 'started_at' => $at,
        ]);
        Call::factory()->for($lead)->create([
            'status' => CallStatus::Connected->value, 'duration_seconds' => 40, 'started_at' => $at, 'dial_source' => 'ai',
        ]);

        FollowUp::factory()->for($lead)->create(['created_at' => $at, 'scheduled_at' => $at->copy()->addDay()]);
        FollowUp::factory()->completed()->for($lead)->create(['created_at' => $at, 'completed_at' => $at]);
        FollowUp::factory()->missed()->for($lead)->create(['created_at' => $at, 'scheduled_at' => $at]);

        Message::factory()->for($lead)->create(['channel' => 'sms', 'created_at' => $at]);
        Message::factory()->for($lead)->create(['channel' => 'email', 'created_at' => $at]);

        $opportunity = Opportunity::factory()->for($lead)->create();
        $customer = Customer::create([
            'tenant_id' => 0,
            'origin_lead_id' => $lead->id,
            'name' => $lead->name,
            'phone_e164' => $lead->phone_e164,
        ]);
        $sale = Sale::create([
            'tenant_id' => 0,
            'opportunity_id' => $opportunity->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'reference' => 'S-AGG-'.$opportunity->id,
            'amount' => 5000,
            'sold_at' => $at,
        ]);
        Payment::factory()->create([
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'product_id' => Product::factory()->create()->id,
            'amount' => 2000,
            'status' => 'partial',
            'paid_at' => $at,
        ]);
    }

    // -----------------------------------------------------------------------

    #[Test]
    public function summary_and_revenue_agree_whether_read_live_or_from_aggregates_for_a_fully_past_period(): void
    {
        $this->actingAsRole(RoleName::Admin);

        foreach (['2026-08-01', '2026-08-03', '2026-08-05', '2026-08-10'] as $date) {
            $this->seedDay($date);
        }

        $period = $this->periodFor('2026-08-01', '2026-08-10');

        // Ground truth, computed before any aggregate row exists - summary()/
        // revenue() must fall back to this same live path anyway at this point.
        $liveSummary = $this->reports()->liveSummary($period);
        $liveRevenue = $this->reports()->liveRevenue($period);

        $this->assertSame($liveSummary, $this->reports()->summary($period));
        $this->assertSame($liveRevenue, $this->reports()->revenue($period));

        $this->artisan('crm:aggregate-daily-reports', ['--trailing' => 0, '--lookback' => 30])
            ->assertExitCode(0);

        // Every day in range now has a row, so this reads report_daily_aggregates.
        $this->assertSame(
            10,
            ReportDailyAggregate::query()->whereBetween('aggregate_date', ['2026-08-01', '2026-08-10'])->count(),
        );

        $this->assertSame($liveSummary, $this->reports()->summary($period));
        $this->assertSame($liveRevenue, $this->reports()->revenue($period));
    }

    #[Test]
    public function a_period_touching_today_never_trusts_an_aggregate_row_for_today(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->seedDay('2026-08-15');
        $this->seedDay('2026-08-20'); // "today" in ORG_TIMEZONE

        $this->artisan('crm:aggregate-daily-reports', ['--trailing' => 0, '--lookback' => 10])
            ->assertExitCode(0);

        // A stale/leftover row for TODAY, deliberately wrong - today is still
        // accumulating and the read side must never sum it in, row or not.
        ReportDailyAggregate::query()->create([
            'tenant_id' => 0,
            'aggregate_date' => '2026-08-20',
            'leads_new' => 9999,
        ]);

        $period = $this->periodFor('2026-08-15', '2026-08-20');

        $summary = $this->reports()->summary($period);

        // Real count: one lead on the 15th, one on the 20th.
        $this->assertSame(2, $summary['leads']['new']);
        $this->assertSame($this->reports()->liveSummary($period), $summary);
        $this->assertSame($this->reports()->liveRevenue($period), $this->reports()->revenue($period));
    }

    #[Test]
    public function a_fully_past_period_missing_one_days_aggregate_falls_back_to_live_instead_of_undercounting(): void
    {
        $this->actingAsRole(RoleName::Admin);

        foreach (['2026-08-01', '2026-08-05', '2026-08-10'] as $date) {
            $this->seedDay($date);
        }

        $period = $this->periodFor('2026-08-01', '2026-08-10');
        $liveSummary = $this->reports()->liveSummary($period);

        $this->artisan('crm:aggregate-daily-reports', ['--trailing' => 0, '--lookback' => 30])
            ->assertExitCode(0);

        // Simulate a gap: the 5th's row never made it (a missed/failed run).
        ReportDailyAggregate::query()->where('aggregate_date', '2026-08-05')->delete();

        $summary = $this->reports()->summary($period);

        // Must equal the live figure - in particular must NOT be short the
        // lead/calls/etc. that landed on the missing day.
        $this->assertSame($liveSummary, $summary);
        $this->assertSame($liveSummary['leads']['new'], $summary['leads']['new']);
    }

    #[Test]
    public function the_aggregation_command_never_writes_a_row_for_today(): void
    {
        $this->seedDay('2026-08-20');

        $this->artisan('crm:aggregate-daily-reports', ['--trailing' => 3, '--lookback' => 5])
            ->assertExitCode(0);

        $this->assertSame(0, ReportDailyAggregate::query()->where('aggregate_date', '2026-08-20')->count());
    }

    #[Test]
    public function the_trailing_window_re_aggregates_even_when_a_row_already_exists(): void
    {
        $this->seedDay('2026-08-19');

        app(ReportAggregationService::class)
            ->aggregateDate(Carbon::parse('2026-08-19', self::TIMEZONE));

        $before = ReportDailyAggregate::query()->where('aggregate_date', '2026-08-19')->first();
        $this->assertSame(1, $before->leads_new);

        // More activity lands on the same day after the first aggregation run.
        $this->seedDay('2026-08-19');

        $this->artisan('crm:aggregate-daily-reports', ['--trailing' => 1, '--lookback' => 0])
            ->assertExitCode(0);

        $after = ReportDailyAggregate::query()->where('aggregate_date', '2026-08-19')->first();
        $this->assertSame(2, $after->leads_new);
    }
}
