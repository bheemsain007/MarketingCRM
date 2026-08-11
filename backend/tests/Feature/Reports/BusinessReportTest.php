<?php

namespace Tests\Feature\Reports;

use App\Enums\CallStatus;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Opportunity;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Reports\BusinessReportService;
use App\Support\Reporting\Rate;
use App\Support\Reporting\ReportPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Business reports (Phase 27, FR-RPT-02/04/05/06).
 *
 * Every figure here is checked against a hand-computed fixture, because most of
 * these metrics have a plausible-looking wrong version and the wrong version is
 * the one that gets written by accident. Average call duration divided by
 * attempts rather than connected calls is the canonical example: the number
 * still looks reasonable, and it silently punishes whoever is working the worst
 * list.
 */
class BusinessReportTest extends TestCase
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

    private function sale(float $amount, ?Carbon $soldAt = null): Sale
    {
        $lead = Lead::factory()->create();
        $opportunity = Opportunity::factory()->for($lead)->create();
        $customer = Customer::create([
            'tenant_id' => 0,
            'origin_lead_id' => $lead->id,
            'name' => $lead->name,
            'phone_e164' => $lead->phone_e164,
        ]);

        return Sale::create([
            'tenant_id' => 0,
            'opportunity_id' => $opportunity->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'reference' => 'S-TEST-'.$opportunity->id,
            'amount' => $amount,
            'sold_at' => $soldAt ?? now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Rates carry their denominator (FR-RPT-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_rate_cannot_be_returned_without_its_denominator(): void
    {
        $rate = Rate::of(3, 10, 'leads assigned this period')->toArray();

        // "34%" is ambiguous and will be misread. The requirement is structural
        // here: the object cannot be built without naming what it is a rate of.
        $this->assertSame(30.0, $rate['value']);
        $this->assertSame(3, $rate['numerator']);
        $this->assertSame(10, $rate['denominator']);
        $this->assertSame('leads assigned this period', $rate['of']);
    }

    #[Test]
    public function a_zero_denominator_is_null_and_never_zero_percent(): void
    {
        $rate = Rate::of(0, 0, 'leads contacted this period')->toArray();

        // "No leads were contacted" and "leads were contacted and none showed
        // interest" are different facts. A dashboard rendering both as 0% is
        // lying about one of them.
        $this->assertNull($rate['value']);
        $this->assertSame(0, $rate['denominator']);
    }

    // -----------------------------------------------------------------------
    // Calling metrics (GLOSSARY 2.2)
    // -----------------------------------------------------------------------

    #[Test]
    public function average_call_duration_divides_by_connected_calls_not_attempts(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        // Two connected calls totalling 300s, plus three that never connected.
        Call::factory()->for($lead)->create([
            'status' => CallStatus::Connected->value, 'duration_seconds' => 200, 'started_at' => now(),
        ]);
        Call::factory()->for($lead)->create([
            'status' => CallStatus::Connected->value, 'duration_seconds' => 100, 'started_at' => now(),
        ]);
        Call::factory()->count(3)->for($lead)->create([
            'status' => CallStatus::NoAnswer->value, 'duration_seconds' => 0, 'started_at' => now(),
        ]);

        $calls = $this->reports()->summary($this->thisMonth())['calls'];

        $this->assertSame(5, $calls['attempts']);
        $this->assertSame(2, $calls['connected']);
        $this->assertSame(300, $calls['talk_time_seconds']);

        // 300 / 2 = 150. Dividing by 5 attempts gives 60 - a plausible-looking
        // number that silently punishes an agent facing dead numbers.
        $this->assertSame(150.0, $calls['average_duration_seconds']);

        $this->assertSame(40.0, $calls['connect_rate']['value']);
        $this->assertSame('call attempts this period', $calls['connect_rate']['of']);
    }

    #[Test]
    public function talk_time_excludes_calls_that_never_connected(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        // A ringing attempt with a recorded duration must not count - it is the
        // number telecallers are measured on.
        Call::factory()->for($lead)->create([
            'status' => CallStatus::NoAnswer->value, 'duration_seconds' => 45, 'started_at' => now(),
        ]);

        $this->assertSame(0, $this->reports()->summary($this->thisMonth())['calls']['talk_time_seconds']);
    }

    #[Test]
    public function average_duration_is_null_rather_than_zero_when_nothing_connected(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->assertNull($this->reports()->summary($this->thisMonth())['calls']['average_duration_seconds']);
    }

    // -----------------------------------------------------------------------
    // Revenue (GLOSSARY 2.5)
    // -----------------------------------------------------------------------

    #[Test]
    public function booked_and_collected_are_separate_numbers(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $sale = $this->sale(10000);
        Payment::factory()->create([
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'lead_id' => $sale->lead_id,
            'product_id' => Product::factory()->create()->id,
            'amount' => 4000,
            'status' => 'partial',
            'paid_at' => now(),
        ]);

        $revenue = $this->reports()->revenue($this->thisMonth());

        // Summing these would report the money twice - once when the deal was
        // signed and again when it was paid.
        $this->assertSame(10000.0, $revenue['booked']);
        $this->assertSame(4000.0, $revenue['collected']);
        $this->assertSame(6000.0, $revenue['outstanding']);
    }

    #[Test]
    public function net_revenue_subtracts_refunds(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->sale(5000);
        $product = Product::factory()->create();

        Payment::factory()->create([
            'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'lead_id' => $sale->lead_id,
            'product_id' => $product->id, 'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);
        Payment::factory()->create([
            'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'lead_id' => $sale->lead_id,
            'product_id' => $product->id, 'amount' => 1000, 'status' => 'refund',
        ]);

        $revenue = $this->reports()->revenue($this->thisMonth());

        $this->assertSame(5000.0, $revenue['collected']);
        $this->assertSame(1000.0, $revenue['refunded']);
        $this->assertSame(4000.0, $revenue['net']);
    }

    #[Test]
    public function collected_revenue_is_counted_by_payment_date_not_sale_date(): void
    {
        $this->actingAsRole(RoleName::Admin);

        // Sold last month, paid this month.
        $sale = $this->sale(8000, Carbon::now()->subMonth());
        Payment::factory()->create([
            'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'lead_id' => $sale->lead_id,
            'product_id' => Product::factory()->create()->id,
            'amount' => 8000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $revenue = $this->reports()->revenue($this->thisMonth());

        // Money is collected when it arrives, not when the deal was signed.
        $this->assertSame(0.0, $revenue['booked']);
        $this->assertSame(8000.0, $revenue['collected']);
    }

    #[Test]
    public function average_deal_size_is_null_with_no_sales(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->assertNull($this->reports()->revenue($this->thisMonth())['average_deal_size']);
    }

    // -----------------------------------------------------------------------
    // Conversion (GLOSSARY 2.4)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_headline_conversion_rate_is_cohort_based(): void
    {
        $this->actingAsRole(RoleName::Admin);

        // Four leads assigned this month; one of them has since converted.
        Lead::factory()->count(3)->create(['assigned_at' => now()]);
        Lead::factory()->create(['assigned_at' => now(), 'status' => LeadStatus::Converted->value]);

        // A lead assigned LAST month that converted this month. It belongs to
        // last month's cohort and must not inflate this month's rate - mixing
        // the populations produces nonsense whenever volume changes.
        Lead::factory()->create([
            'assigned_at' => Carbon::now()->subMonths(2),
            'status' => LeadStatus::Converted->value,
        ]);

        $conversion = $this->reports()->conversion($this->thisMonth());

        $this->assertSame(4, $conversion['lead_to_sale']['denominator']);
        $this->assertSame(1, $conversion['lead_to_sale']['numerator']);
        $this->assertSame(25.0, $conversion['lead_to_sale']['value']);
        $this->assertSame('leads assigned this period (cohort)', $conversion['lead_to_sale']['of']);
    }

    #[Test]
    public function opportunity_win_rate_counts_only_closed_opportunities(): void
    {
        $this->actingAsRole(RoleName::Admin);

        Opportunity::factory()->count(3)->won()->create();
        Opportunity::factory()->lost()->create();
        // Still open - not in the denominator, because it has not been decided.
        Opportunity::factory()->count(5)->create();

        $conversion = $this->reports()->conversion($this->thisMonth());

        $this->assertSame(4, $conversion['opportunity_win_rate']['denominator']);
        $this->assertSame(75.0, $conversion['opportunity_win_rate']['value']);
    }

    #[Test]
    public function pipeline_value_counts_open_opportunities_only(): void
    {
        $this->actingAsRole(RoleName::Admin);

        Opportunity::factory()->create(['value' => 1000]);
        Opportunity::factory()->create(['value' => 2500]);
        Opportunity::factory()->won()->create(['value' => 9999]);
        Opportunity::factory()->lost()->create(['value' => 9999]);

        $this->assertSame(3500.0, $this->reports()->conversion($this->thisMonth())['pipeline_value']);
    }

    #[Test]
    public function loss_reasons_are_reported_with_their_share(): void
    {
        $this->actingAsRole(RoleName::Admin);

        Opportunity::factory()->count(3)->lost()->create(['lost_reason' => 'price']);
        Opportunity::factory()->lost()->create(['lost_reason' => 'competitor']);

        $reasons = collect($this->reports()->conversion($this->thisMonth())['loss_reasons']);

        $price = $reasons->firstWhere('reason', 'price');
        $this->assertSame(3, $price['count']);
        $this->assertSame(75.0, $price['share']['value']);
        $this->assertSame('opportunities lost this period', $price['share']['of']);
    }

    // -----------------------------------------------------------------------
    // Universal rules (GLOSSARY 2.1)
    // -----------------------------------------------------------------------

    #[Test]
    public function archived_leads_are_excluded_from_metrics(): void
    {
        $this->actingAsRole(RoleName::Admin);

        Lead::factory()->count(2)->create();
        $archived = Lead::factory()->create();
        $archived->delete();

        // "Archived and test leads are excluded from every metric."
        $this->assertSame(2, $this->reports()->summary($this->thisMonth())['leads']['total']);
    }

    #[Test]
    public function metrics_are_period_scoped_by_event_time(): void
    {
        $this->actingAsRole(RoleName::Admin);

        Lead::factory()->count(2)->create(['created_at' => now()]);
        Lead::factory()->count(5)->create(['created_at' => Carbon::now()->subMonths(3)]);

        $summary = $this->reports()->summary($this->thisMonth())['leads'];

        // New is period-scoped; total is a snapshot. They answer different
        // questions and mixing them makes a dashboard unreadable.
        $this->assertSame(2, $summary['new']);
        $this->assertSame(7, $summary['total']);
    }

    #[Test]
    public function the_period_states_the_timezone_it_was_aggregated_in(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $response = $this->getJson('/api/v1/reports/summary')->assertOk();

        // Rows are UTC; aggregation is in the org timezone. Stating it lets two
        // people comparing dashboards see they used the same window.
        $this->assertSame(config('crm.timezone'), $response->json('data.period.timezone'));
    }

    #[Test]
    public function a_date_range_can_be_supplied(): void
    {
        $this->actingAsRole(RoleName::Admin);

        Lead::factory()->create(['created_at' => Carbon::parse('2026-03-15')]);
        Lead::factory()->create(['created_at' => Carbon::parse('2026-05-15')]);

        $response = $this->getJson('/api/v1/reports/summary?from=2026-03-01&to=2026-03-31')->assertOk();

        $this->assertSame(1, $response->json('data.summary.leads.new'));
        $this->assertSame('2026-03-01', $response->json('data.period.from'));
    }

    // -----------------------------------------------------------------------
    // Breakdowns
    // -----------------------------------------------------------------------

    #[Test]
    public function product_performance_reports_collected_revenue_per_product(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $sale = $this->sale(3000);
        $a = Product::factory()->create(['name' => 'Product A']);
        $b = Product::factory()->create(['name' => 'Product B']);

        foreach ([[$a, 2000], [$b, 500]] as [$product, $amount]) {
            Payment::factory()->create([
                'sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'lead_id' => $sale->lead_id,
                'product_id' => $product->id, 'amount' => $amount, 'status' => 'partial', 'paid_at' => now(),
            ]);
        }

        $products = $this->reports()->productPerformance($this->thisMonth());

        // Highest earner first - the list exists to be read from the top.
        $this->assertSame('Product A', $products[0]['name']);
        $this->assertSame(2000.0, $products[0]['collected']);
        $this->assertSame(500.0, $products[1]['collected']);
    }

    #[Test]
    public function source_performance_judges_a_source_on_outcomes_not_volume(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $good = LeadSource::create(['tenant_id' => 0, 'code' => 'GOOD', 'name' => 'Good source', 'is_active' => true]);
        $noisy = LeadSource::create(['tenant_id' => 0, 'code' => 'NOISY', 'name' => 'Noisy source', 'is_active' => true]);

        Lead::factory()->count(4)->create(['lead_source_id' => $good->id]);
        Lead::factory()->count(2)->create([
            'lead_source_id' => $good->id, 'status' => LeadStatus::Converted->value,
        ]);
        Lead::factory()->count(20)->create(['lead_source_id' => $noisy->id]);

        $sources = collect($this->reports()->sourcePerformance($this->thisMonth()));

        // 20 leads and no sales is worse than 6 leads and two.
        $this->assertSame(0.0, $sources->firstWhere('name', 'Noisy source')['conversion_rate']['value']);
        $this->assertSame(33.3, $sources->firstWhere('name', 'Good source')['conversion_rate']['value']);
    }

    #[Test]
    public function unattributed_leads_are_reported_rather_than_dropped(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Lead::factory()->count(3)->create(['lead_source_id' => null]);

        $sources = collect($this->reports()->sourcePerformance($this->thisMonth()));

        // Silently omitting them would make the source totals disagree with the
        // lead total, and somebody would spend an afternoon on the difference.
        $this->assertSame(3, $sources->firstWhere('name', 'Unattributed')['leads']);
    }

    // -----------------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_cannot_open_the_business_reports(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        foreach (['summary', 'revenue', 'pipeline', 'products', 'sources'] as $report) {
            $this->getJson("/api/v1/reports/{$report}")->assertStatus(403);
        }
    }

    #[Test]
    public function a_manager_can_open_them(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->getJson('/api/v1/reports/summary')->assertOk();
        $this->getJson('/api/v1/reports/revenue')->assertOk();
    }

    #[Test]
    public function the_report_is_not_data_scoped_to_the_caller(): void
    {
        $colleague = User::factory()->create();
        Lead::factory()->count(4)->create(['assigned_to' => $colleague->id]);

        $this->actingAsRole(RoleName::Manager);

        // A business dashboard IS the organisation-wide view. Scoping it would
        // produce a number that looks like a company total and is not one.
        $this->assertSame(4, $this->getJson('/api/v1/reports/summary')->json('data.summary.leads.total'));
    }
}
