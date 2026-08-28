<?php

namespace Tests\Feature\Web;

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\LeadProduct;
use App\Models\Opportunity;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Controls a role is offered but cannot use (ROLE-05, SEC-AUTHZ-03).
 *
 * A button whose endpoint answers 403 is worse than no button: the person reads
 * it as the system being broken rather than as work they may not do. These four
 * screens each drew one. Two are Blade gates and are asserted as markup; two are
 * drawn by JS, so what is asserted is the server-rendered flag they hang off AND
 * that the control sits inside that guard in the shipped source - a rendering
 * test cannot run the JS, but it can prove the guard is there.
 *
 * Nothing here depends on the clock: what a role is shown is the same at 3am as
 * at noon.
 */
class RoleGatedUiTest extends TestCase
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

    /** A telecaller is scoped to their own leads, so a lead under test is assigned to them. */
    private function lead(?User $owner = null): Lead
    {
        return Lead::factory()->create(['assigned_to' => $owner?->id]);
    }

    /**
     * The rendered source between two markers.
     *
     * The JS defects below are structural - a control on the wrong side of a
     * guard - so the assertion has to be about where the control sits, not
     * merely that the page mentions it.
     */
    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Marker not found in the page: {$start}");

        $to = strpos($html, $end, $from);
        $this->assertNotFalse($to, "Marker not found after the first: {$end}");

        return substr($html, $from, $to - $from);
    }

    // -----------------------------------------------------------------------
    // 1. The Calls tab (calls.view)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_role_holding_calls_view_lands_on_the_calls_tab(): void
    {
        // Viewer holds leads.view and calls.view, so Calls is still the default.
        $this->actingAs($this->user(RoleName::Viewer))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertSee('data-bs-target="#tab-calls"', false)
            ->assertSee('<div class="tab-pane fade show active" id="tab-calls">', false)
            ->assertSee('id="call-rows"', false);
    }

    #[Test]
    public function a_role_without_calls_view_is_shown_no_calls_tab_at_all(): void
    {
        // Accounts holds leads.view but deliberately not calls.view: the tab's
        // two loaders (/callability and /calls) would both answer 403.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertDontSee('data-bs-target="#tab-calls"', false)
            ->assertDontSee('id="call-rows"', false)
            ->assertDontSee('id="call-form"', false);
    }

    #[Test]
    public function a_role_without_calls_view_lands_on_a_tab_it_can_actually_read(): void
    {
        $accounts = $this->user(RoleName::Accounts);

        // Products needs only leads.view, so it is the one tab every role that
        // can open this page can fill. Landing on a dead tab is the defect.
        $this->actingAs($accounts)
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertSee('tab-pane fade show active" id="tab-products"', false);

        // And it must not be active for a role whose Calls tab is drawn, or two
        // panes would open at once.
        $this->actingAs($this->user(RoleName::Viewer))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertDontSee('tab-pane fade show active" id="tab-products"', false);
    }

    #[Test]
    public function neither_call_loader_is_called_for_a_role_that_may_not_see_calls(): void
    {
        $html = $this->actingAs($this->user(RoleName::Accounts))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->getContent();

        // The bootstrap block at the foot of the page: two 403s per page view
        // is what the gate exists to stop.
        $bootstrap = $this->between($html, 'if (!isArchived) {', 'loadProducts();');

        $this->assertStringNotContainsString('loadCallability();', $bootstrap);
        $this->assertStringNotContainsString('loadCalls();', $bootstrap);
    }

    #[Test]
    public function both_call_loaders_still_run_for_a_role_that_may_see_calls(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $html = $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->getContent();

        $bootstrap = $this->between($html, 'if (!isArchived) {', 'loadProducts();');

        $this->assertStringContainsString('loadCallability();', $bootstrap);
        $this->assertStringContainsString('loadCalls();', $bootstrap);
    }

    // -----------------------------------------------------------------------
    // 2. Mark lost (sales.manage)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_sales_manage_flag_is_false_for_a_role_that_may_only_read_deals(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        // Telecaller holds sales.view and not sales.manage - it may read a deal
        // and not close one.
        $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->assertSee('const canManageSales = false;', false);

        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertSee('const canManageSales = true;', false);
    }

    #[Test]
    public function mark_lost_is_drawn_inside_the_sales_manage_guard(): void
    {
        $html = $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->getContent();

        // Closing a deal posts to /opportunities/{id}/lost, which requires
        // sales.manage - so the button belongs beside Add product, Quotations
        // and Record sale, not after the guard closes.
        $guarded = $this->between($html, 'if (canManageSales) {', 'return html;');

        $this->assertStringContainsString('deal-lost', $guarded);
    }

    #[Test]
    public function closing_a_deal_really_does_need_sales_manage(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = $this->lead($telecaller);
        $opportunity = Opportunity::factory()->for($lead)->create();

        // The contract the button was hidden for: sales.view alone can only 403.
        $this->actingAs($telecaller, 'sanctum')
            ->postJson('/api/v1/opportunities/'.$opportunity->id.'/lost', ['reason' => 'price'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // 3. Removing a product interest (leads.update)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_leads_update_flag_is_false_for_a_read_only_role(): void
    {
        // Accounts and Viewer both hold leads.view without leads.update.
        foreach ([RoleName::Accounts, RoleName::Viewer] as $role) {
            $this->actingAs($this->user($role))
                ->get('/leads/'.$this->lead()->id)
                ->assertOk()
                ->assertSee('const canUpdateLead = false;', false);
        }

        $telecaller = $this->user(RoleName::Telecaller);

        $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->assertSee('const canUpdateLead = true;', false);
    }

    #[Test]
    public function the_product_remove_button_is_drawn_behind_the_leads_update_flag(): void
    {
        $html = $this->actingAs($this->user(RoleName::Accounts))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->getContent();

        $rowBuilder = $this->between($html, 'function loadProducts() {', "'#add-product'");

        $this->assertStringContainsString('canUpdateLead', $rowBuilder);
    }

    #[Test]
    public function removing_a_product_interest_really_does_need_leads_update(): void
    {
        $accounts = $this->user(RoleName::Accounts);
        $lead = $this->lead();
        $product = Product::factory()->create();

        $interest = LeadProduct::create([
            'lead_id' => $lead->id,
            'product_id' => $product->id,
            'interest_status' => LeadStatus::Interested->value,
        ]);

        $this->actingAs($accounts, 'sanctum')
            ->deleteJson('/api/v1/leads/'.$lead->id.'/products/'.$interest->id)
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // 4. The Duplicates nav link (T-64)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_leads_view_only_role_is_offered_the_duplicates_link_it_can_open(): void
    {
        // The queue is deliberately open to leads.view and has a read-only mode
        // for exactly these roles; gated on leads.archive, the nav link made it
        // unreachable for every one of them.
        foreach ([RoleName::Viewer, RoleName::Accounts, RoleName::Telecaller] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get('/leads')->assertOk()->assertSee('/lead-duplicates', false);
            $this->actingAs($user)->get('/lead-duplicates')->assertOk();
        }
    }

    #[Test]
    public function reaching_the_duplicates_queue_does_not_hand_a_reviewer_the_merge_controls(): void
    {
        // Opening the queue is leads.view; resolving one is still leads.archive,
        // which is the whole reason the page has a read-only mode.
        $this->actingAs($this->user(RoleName::Viewer))
            ->get('/lead-duplicates')
            ->assertOk()
            ->assertSee('const canResolve = false;', false)
            ->assertDontSee('id="backfill"', false);

        $this->actingAs($this->user(RoleName::Manager))
            ->get('/lead-duplicates')
            ->assertOk()
            ->assertSee('const canResolve = true;', false)
            ->assertSee('id="backfill"', false);
    }
}
