<?php

namespace Tests\Feature\Web;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Reports\BusinessReportService;
use App\Support\Reporting\ReportPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard's revenue row (FR-RPT-02/03/05, SEC-AUTHZ-03).
 *
 * Two things are worth testing and they are both about boundaries rather than
 * markup. The figures are organisation-wide - the reporting service does not
 * scope them to the viewer - so the tiles must be absent for anyone without
 * `reports.business`, or the dashboard becomes the one screen that hands a
 * telecaller the company's revenue. And the window must be this month: an
 * unbounded all-time aggregate is both the wrong number and the query that
 * takes the dashboard down (FR-RPT-05).
 */
class DashboardRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // "This month" is the tile's window, so the clock decides every figure
        // below. Frozen mid-month and mid-day so neither boundary is one tick
        // away in the organisation's timezone.
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00', $this->orgTimezone()));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function orgTimezone(): string
    {
        return (string) config('crm.timezone', 'UTC');
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    /** The exact window the controller builds, so the two cannot drift apart. */
    private function thisMonth(): ReportPeriod
    {
        $timezone = $this->orgTimezone();

        return ReportPeriod::between(
            Carbon::now($timezone)->startOfMonth()->startOfDay(),
            Carbon::now($timezone)->endOfDay(),
            $timezone,
        );
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
            'reference' => 'S-DASH-'.$opportunity->id,
            'amount' => $amount,
            'sold_at' => $soldAt ?? now(),
        ]);
    }

    private function payment(Sale $sale, float $amount, string $status, ?Carbon $paidAt = null): Payment
    {
        return Payment::factory()->create([
            'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'lead_id' => $sale->lead_id,
            'product_id' => Product::factory()->create()->id,
            'amount' => $amount,
            'status' => $status,
            'paid_at' => $paidAt,
        ]);
    }

    // -----------------------------------------------------------------------
    // Who may see it
    // -----------------------------------------------------------------------

    #[Test]
    public function a_manager_sees_the_revenue_tiles(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Revenue this month')
            ->assertSee('id="revenue-tiles"', false)
            // The caption that stops the classic mistake (GLOSSARY section 2.5).
            ->assertSee('never added together');
    }

    #[Test]
    public function the_accounts_role_sees_the_revenue_tiles_too(): void
    {
        // Accounts holds reports.business, and money is their whole job
        // (ROLE-05). Asserted separately from Manager because the gate is a
        // permission, not a rank.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="revenue-tiles"', false);
    }

    #[Test]
    public function a_telecaller_never_sees_company_revenue_on_their_dashboard(): void
    {
        $sale = $this->sale(10000);
        $this->payment($sale, 4000, 'partial', Carbon::now($this->orgTimezone()));

        // The dashboard has no route-level permission - every user lands here -
        // so the tile's own gate is the only thing standing between a telecaller
        // and the company's revenue (SEC-AUTHZ-03).
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Revenue this month')
            ->assertDontSee('id="revenue-tiles"', false)
            // Not just the heading: the numbers themselves must be absent.
            ->assertDontSee('₹10,000')
            ->assertDontSee('₹4,000');
    }

    // -----------------------------------------------------------------------
    // The figures themselves
    // -----------------------------------------------------------------------

    #[Test]
    public function the_rendered_figures_match_the_reporting_service(): void
    {
        $today = Carbon::now($this->orgTimezone());

        // 10,000 booked, 4,000 of it collected, 6,000 still owed, and a
        // separate 2,500 already past due. Four distinct amounts, so a tile
        // rendering the wrong figure cannot pass by coincidence.
        $sale = $this->sale(10000, $today);
        $this->payment($sale, 4000, 'partial', $today);
        $this->payment($sale, 2500, 'overdue');

        $revenue = app(BusinessReportService::class)->revenue($this->thisMonth());

        $response = $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk();

        // Compared against the service rather than against hand-typed numbers:
        // the point of reusing it is that the dashboard and /reports can never
        // disagree, and a hard-coded expectation would not catch them drifting.
        foreach (['collected', 'booked', 'outstanding', 'overdue'] as $figure) {
            $response->assertSee('₹'.number_format((float) $revenue[$figure]));
        }

        $this->assertSame(4000.0, $revenue['collected']);
        $this->assertSame(10000.0, $revenue['booked']);
        $this->assertSame(6000.0, $revenue['outstanding']);
        $this->assertSame(2500.0, $revenue['overdue']);
    }

    #[Test]
    public function the_period_is_named_on_the_page(): void
    {
        // Two people comparing dashboards must be able to see they were looking
        // at the same window, timezone included (FR-RPT-04).
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('2026-05-01')
            ->assertSee('2026-05-14')
            ->assertSee($this->orgTimezone());
    }

    #[Test]
    public function the_window_is_this_month_and_not_all_time(): void
    {
        $lastMonth = Carbon::now($this->orgTimezone())->subMonth();

        // Sold and fully settled last month, so it leaves the snapshots at zero
        // too and any figure that appears is a period bug rather than a debt.
        $sale = $this->sale(99000, $lastMonth);
        $this->payment($sale, 99000, 'paid', $lastMonth);

        $this->actingAs($this->user(RoleName::Manager))
            ->get('/dashboard')
            ->assertOk()
            // An all-time aggregate is the wrong number AND the query that
            // takes the dashboard down (FR-RPT-05).
            ->assertDontSee('₹99,000')
            ->assertSee('₹0');
    }
}
