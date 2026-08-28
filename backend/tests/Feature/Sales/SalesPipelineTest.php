<?php

namespace Tests\Feature\Sales;

use App\Enums\LeadStatus;
use App\Enums\OpportunityStatus;
use App\Enums\QuotationStatus;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sales pipeline (Phase 22, FR-SALE-01..05, BR-SALE-01..04, BR-CUST-01..04).
 *
 * The load-bearing assertions are about money and about conversion: discounts
 * above the threshold cannot be self-approved, an issued quotation cannot be
 * edited, a customer is not duplicated, and `Converted` still cannot be reached
 * without a sale behind it.
 */
class SalesPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['crm.discount_approval_threshold' => 15]);
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

    /** An opportunity with one product at a known price. */
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

    // -----------------------------------------------------------------------
    // Opportunity value (FR-SALE-02)
    // -----------------------------------------------------------------------

    #[Test]
    public function opportunity_value_is_the_sum_of_its_lines_and_not_settable(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        $a = Product::factory()->create(['base_price' => 1000]);
        $b = Product::factory()->create(['base_price' => 250]);

        $response = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", [
            'title' => 'Bundle',
            // A caller-supplied value is ignored - the lines are the truth.
            'value' => 999999,
            'products' => [
                ['product_id' => $a->id, 'quantity' => 2],
                ['product_id' => $b->id],
            ],
        ])->assertCreated();

        $this->assertSame('2250.00', $response->json('data.value'));
    }

    #[Test]
    public function a_line_price_is_snapshotted_and_survives_a_later_price_change(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $product = Product::factory()->create(['base_price' => 1000]);
        $lead = Lead::factory()->create();

        $id = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", [
            'title' => 'Deal',
            'products' => [['product_id' => $product->id]],
        ])->json('data.id');

        // The list price rises next quarter.
        $product->update(['base_price' => 5000]);

        // The open deal must not silently reprice itself - somebody has been
        // quoted the old figure.
        $this->assertSame('1000.00', Opportunity::find($id)->fresh()->value);
    }

    #[Test]
    public function products_cannot_be_added_to_a_closed_opportunity(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = Opportunity::factory()->lost()->create();
        $product = Product::factory()->create();

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/products", [
            'product_id' => $product->id,
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Lost tracking (FR-SALE-05, BR-SALE-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lost_deal_records_a_reason_from_the_closed_list(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/lost", [
            'reason' => 'competitor',
            'notes' => 'Went with a cheaper vendor',
        ])->assertOk()->assertJsonPath('data.lost_reason', 'competitor');

        $this->assertSame(OpportunityStatus::Lost, $opportunity->fresh()->status);
        $this->assertNotNull($opportunity->fresh()->closed_at);
    }

    #[Test]
    public function an_invented_lost_reason_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        // Free text produces "price", "Price" and "too expensive" as three
        // answers to one question, and the report shows nothing.
        $this->postJson("/api/v1/opportunities/{$opportunity->id}/lost", [
            'reason' => 'vibes',
        ])->assertStatus(422);
    }

    #[Test]
    public function other_as_a_reason_requires_an_explanation(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        // "Other" with no note is the same as no reason, and it is what people
        // reach for when the list is inconvenient.
        $this->postJson("/api/v1/opportunities/{$opportunity->id}/lost", [
            'reason' => 'other',
        ])->assertStatus(422);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/lost", [
            'reason' => 'other',
            'notes' => 'Project cancelled internally',
        ])->assertOk();
    }

    #[Test]
    public function a_lost_deal_cannot_be_reopened(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = Opportunity::factory()->lost()->create();

        // Reopening would make "how many did we lose, and why?" unanswerable
        // after the fact. Repeat business is a new opportunity (BR-CUST-03).
        $this->postJson("/api/v1/opportunities/{$opportunity->id}/lost", [
            'reason' => 'price',
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Quotations and discount approval (FR-SALE-03/04, BR-SALE-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_discount_within_the_threshold_needs_no_approval(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        $response = $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 10,
        ])->assertCreated();

        $this->assertSame('draft', $response->json('data.status'));
        $this->assertSame('100.00', $response->json('data.discount_amount'));
        $this->assertSame('900.00', $response->json('data.total'));
    }

    #[Test]
    public function a_discount_above_the_threshold_cannot_be_issued_unapproved(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        $id = $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 25,
        ])->assertCreated()->json('data.id');

        $this->assertSame(QuotationStatus::PendingApproval, Quotation::find($id)->status);

        $this->postJson("/api/v1/quotations/{$id}/issue")->assertStatus(422);
    }

    #[Test]
    public function a_discount_cannot_be_approved_by_the_person_who_raised_it(): void
    {
        // Admin holds discounts.approve, so permission alone would let this
        // through - and a salesperson who can approve their own discount does
        // not have an approval step, they have a checkbox.
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        $id = $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 30,
        ])->json('data.id');

        $this->postJson("/api/v1/quotations/{$id}/approve")->assertStatus(403);
    }

    #[Test]
    public function a_manager_can_approve_someone_elses_discount_and_it_is_audited(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);
        $id = $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 30,
        ])->json('data.id');

        $approver = $this->actingAsRole(RoleName::Manager);

        $this->postJson("/api/v1/quotations/{$id}/approve")->assertOk();

        $quotation = Quotation::find($id);
        $this->assertSame(QuotationStatus::Approved, $quotation->status);
        $this->assertSame($approver->id, $quotation->approved_by);

        // BR-SALE-03: the decision is recorded, not just the access.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $approver->id,
            'action' => 'quotation_discount_approved',
        ]);

        $this->postJson("/api/v1/quotations/{$id}/issue")->assertOk();
    }

    #[Test]
    public function a_telecaller_cannot_approve_a_discount(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);
        $id = $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 30,
        ])->json('data.id');

        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson("/api/v1/quotations/{$id}/approve")->assertStatus(403);
    }

    #[Test]
    public function a_rejected_discount_says_why(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);
        $id = $this->postJson("/api/v1/opportunities/{$opportunity->id}/quotations", [
            'discount_percent' => 40,
        ])->json('data.id');

        $this->actingAsRole(RoleName::Manager);

        $this->postJson("/api/v1/quotations/{$id}/reject", ['reason' => 'Margin too thin'])
            ->assertOk()
            ->assertJsonPath('data.rejection_reason', 'Margin too thin');

        $this->postJson("/api/v1/quotations/{$id}/issue")->assertStatus(422);
    }

    #[Test]
    public function quotation_items_are_copied_not_referenced(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $product = Product::factory()->create(['base_price' => 1000, 'name' => 'Original name']);
        $lead = Lead::factory()->create();

        $opportunityId = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", [
            'title' => 'Deal',
            'products' => [['product_id' => $product->id]],
        ])->json('data.id');

        $quotationId = $this->postJson("/api/v1/opportunities/{$opportunityId}/quotations")
            ->assertCreated()->json('data.id');

        $product->update(['name' => 'Renamed later']);

        // The quotation records what was quoted. A product renamed two years
        // later must not retroactively change somebody's paperwork.
        $this->assertSame('Original name', Quotation::find($quotationId)->items()->first()->description);
    }

    #[Test]
    public function a_quotation_needs_at_least_one_product(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $id = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", ['title' => 'Empty'])
            ->json('data.id');

        $this->postJson("/api/v1/opportunities/{$id}/quotations")->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Sale and Customer (BR-CUST-01..04)
    // -----------------------------------------------------------------------

    #[Test]
    public function recording_a_sale_creates_a_customer_linked_back_to_the_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['name' => 'Ramesh Kumar', 'email' => 'r@example.com']);
        $opportunity = $this->opportunityWorth(5000, $lead);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->assertCreated();

        $customer = Customer::firstOrFail();

        // BR-CUST-01: the lead is NOT converted in place. It survives, and the
        // customer links back - which is what makes "where did this customer
        // come from?" answerable a year later.
        $this->assertSame($lead->id, $customer->origin_lead_id);
        $this->assertSame('Ramesh Kumar', $customer->name);
        $this->assertNotNull($lead->fresh());
        $this->assertDatabaseHas('customer_leads', ['customer_id' => $customer->id, 'lead_id' => $lead->id]);
    }

    #[Test]
    public function billing_identity_lives_on_the_customer_and_is_never_guessed_from_the_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['name' => 'Ramesh Kumar', 'city' => 'Chennai']);
        $opportunity = $this->opportunityWorth(5000, $lead);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->assertCreated();

        /*
         * BR-CUST-02: billing name, address and tax ID belong to the Customer,
         * and they start empty rather than being copied from the lead. The
         * person who enquired is often not the legal entity that pays, and an
         * invoice addressed to a guess is not a cosmetic problem.
         */
        $customer = Customer::firstOrFail();
        $this->assertNull($customer->billing_name);
        $this->assertNull($customer->billing_address);
        $this->assertNull($customer->tax_id);

        // And the lead has nowhere to put them, so it cannot become a second
        // source of truth for who gets invoiced.
        $this->assertFalse(Schema::hasColumn('leads', 'billing_name'));
        $this->assertFalse(Schema::hasColumn('leads', 'tax_id'));
    }

    #[Test]
    public function a_second_sale_to_the_same_person_does_not_create_a_second_customer(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['phone_e164' => '+919876500123']);

        $first = $this->opportunityWorth(1000, $lead);
        $this->postJson("/api/v1/opportunities/{$first->id}/sale")->assertCreated();

        $second = $this->opportunityWorth(2000, $lead);
        $this->postJson("/api/v1/opportunities/{$second->id}/sale")->assertCreated();

        // BR-CUST-04: deduplicated on phone. Without this the account's history
        // splits in two and neither half is complete.
        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Sale::count());
    }

    #[Test]
    public function a_sale_closes_the_opportunity_as_won(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(3000);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->assertCreated();

        $this->assertSame(OpportunityStatus::Won, $opportunity->fresh()->status);
        $this->assertNotNull($opportunity->fresh()->closed_at);
    }

    #[Test]
    public function a_sale_cannot_be_recorded_twice_on_one_opportunity(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->assertCreated();
        $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->assertStatus(422);

        $this->assertSame(1, Sale::count());
    }

    #[Test]
    public function a_zero_value_sale_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        $id = $this->postJson("/api/v1/leads/{$lead->id}/opportunities", ['title' => 'Freebie'])
            ->json('data.id');

        // Either a mistake or a giveaway being logged as revenue.
        $this->postJson("/api/v1/opportunities/{$id}/sale")->assertStatus(422);
    }

    #[Test]
    public function sale_credit_does_not_move_when_the_lead_is_reassigned(): void
    {
        $seller = $this->user(RoleName::Telecaller);
        $other = $this->user(RoleName::Telecaller);
        $this->actingAsRole(RoleName::Admin);

        $lead = Lead::factory()->create(['assigned_to' => $seller->id]);
        $opportunity = $this->opportunityWorth(4000, $lead);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->assertCreated();
        $this->postJson("/api/v1/leads/{$lead->id}/assign", ['user_id' => $other->id])->assertOk();

        // T-24: whoever earned it keeps it. Deriving credit from the lead's
        // current owner would silently move commission on reassignment.
        $this->assertSame($seller->id, Sale::firstOrFail()->sold_by);
    }

    // -----------------------------------------------------------------------
    // Conversion (BR-STAT-05) - the thing this phase unblocks
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_still_cannot_be_converted_without_a_sale(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);

        // `Converted` is terminal and drives revenue reporting and pay. A lead
        // marked converted in error cannot be corrected - the matrix offers no
        // way back out.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => LeadStatus::Converted->value,
        ])->assertStatus(422);

        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    #[Test]
    public function a_lead_with_a_sale_and_a_payment_can_finally_be_converted(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $opportunity = $this->opportunityWorth(7500, $lead);

        $saleId = $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")
            ->assertCreated()->json('data.id');

        // Phase 23 added the second half of BR-PAY-05: a sale alone is a
        // promise, a sale with money against it is a conversion.
        $this->postJson("/api/v1/sales/{$saleId}/payments", ['amount' => 1000])->assertCreated();

        // Blocked since Phase 7. This is what finally opens it.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => LeadStatus::Converted->value,
        ])->assertOk();

        $this->assertSame(LeadStatus::Converted, $lead->fresh()->status);
    }

    #[Test]
    public function converted_remains_terminal(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);
        $opportunity = $this->opportunityWorth(1000, $lead);

        $saleId = $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->json('data.id');
        $this->postJson("/api/v1/sales/{$saleId}/payments", ['amount' => 1000]);
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Converted->value])->assertOk();

        // BR-SALE-02 / BR-STAT-02: repeat business is a new opportunity, never
        // a status revert.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Lost->value])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Scope and permissions
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_cannot_see_an_opportunity_on_someone_elses_lead(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $colleague->id]);
        $opportunity = Opportunity::factory()->for($lead)->create();

        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson("/api/v1/leads/{$lead->id}/opportunities")->assertStatus(403);
        $this->getJson('/api/v1/opportunities')->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("/api/v1/opportunities/{$opportunity->id}/lost", ['reason' => 'price'])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // The pipeline list (FR-SALE-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_pipeline_can_include_product_lines_with_their_names(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        $product = Product::factory()->create(['name' => 'Annual Licence', 'base_price' => 1000]);

        $this->postJson("/api/v1/leads/{$lead->id}/opportunities", [
            'title' => 'Deal',
            'products' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        $items = $this->getJson('/api/v1/opportunities?include=products.product')
            ->assertOk()->json('data.items');

        // The resource reaches from the line to the product for its name, so
        // loading only the lines left it lazy-loading one relation per row -
        // which under preventLazyLoading is a 500, not a slow response.
        $this->assertSame('Annual Licence', $items[0]['products'][0]['name']);
        $this->assertSame(2, $items[0]['products'][0]['quantity']);
    }

    #[Test]
    public function an_include_that_stops_short_of_what_the_resource_reads_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->opportunityWorth(1000);

        // An include names the full path the resource walks. Offering the
        // one-level spelling as well would just be a second way to ask for the
        // 500 this endpoint used to return.
        $this->getJson('/api/v1/opportunities?include=products')->assertStatus(422);
    }

    #[Test]
    public function a_read_only_role_cannot_record_a_sale(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $opportunity = $this->opportunityWorth(1000);

        // Accounts holds sales.view but not sales.manage.
        $this->actingAsRole(RoleName::Accounts);

        $this->postJson("/api/v1/opportunities/{$opportunity->id}/sale")->assertStatus(403);
    }
}
