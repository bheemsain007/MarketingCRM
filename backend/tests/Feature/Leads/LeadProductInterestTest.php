<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\LeadProduct;
use App\Models\Opportunity;
use App\Models\OpportunityProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Per-product interest (FR-STAT-05, BR-PROD-01..03, BR-STAT-04).
 *
 * The property this suite exists to protect is independence: a lead can be
 * negotiating one product and uninterested in another, and touching either
 * must leave the other exactly as it was.
 */
class LeadProductInterestTest extends TestCase
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
        $user = $user->fresh();

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function leadFor(User $user, LeadStatus $status = LeadStatus::New): Lead
    {
        return Lead::factory()->create([
            'status' => $status->value,
            'assigned_to' => $user->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // Recording interest
    // -----------------------------------------------------------------------

    #[Test]
    public function a_product_interest_can_be_recorded(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);
        $product = Product::factory()->create(['name' => 'News Portal']);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => $product->id,
            'interest_status' => 'interested',
            'quoted_value' => 25000,
        ])->assertStatus(201)
            ->assertJsonPath('data.interest_status', 'interested')
            ->assertJsonPath('data.product.name', 'News Portal');

        $this->assertDatabaseHas('lead_products', [
            'lead_id' => $lead->id,
            'product_id' => $product->id,
            'interest_status' => 'interested',
        ]);

        // Real interest stamps first_interest_at; "on the list" does not.
        $this->assertNotNull(LeadProduct::first()->first_interest_at);
    }

    #[Test]
    public function the_same_product_cannot_be_added_twice(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);
        $product = Product::factory()->create();

        $this->postJson("/api/v1/leads/{$lead->id}/products", ['product_id' => $product->id])
            ->assertStatus(201);

        // The conflict carries the id of the row they probably meant to update.
        $response = $this->postJson("/api/v1/leads/{$lead->id}/products", ['product_id' => $product->id])
            ->assertStatus(409);

        $this->assertNotNull($response->json('data.lead_product_id'));
        $this->assertSame(1, LeadProduct::count());
    }

    #[Test]
    public function an_archived_product_cannot_be_added_as_new_interest(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);
        $product = Product::factory()->create();
        $product->delete();

        $this->postJson("/api/v1/leads/{$lead->id}/products", ['product_id' => $product->id])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // BR-PROD-01 — independence
    // -----------------------------------------------------------------------

    #[Test]
    public function changing_one_product_leaves_the_others_untouched(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $portal = Product::factory()->create(['name' => 'News Portal']);
        $epaper = Product::factory()->create(['name' => 'Epaper']);
        $posting = Product::factory()->create(['name' => 'News Posting']);

        foreach ([$portal, $epaper, $posting] as $product) {
            $this->postJson("/api/v1/leads/{$lead->id}/products", [
                'product_id' => $product->id,
                'interest_status' => 'interested',
            ])->assertStatus(201);
        }

        $epaperInterest = LeadProduct::where('product_id', $epaper->id)->firstOrFail();

        $this->patchJson("/api/v1/leads/{$lead->id}/products/{$epaperInterest->id}", [
            'interest_status' => 'not_interested',
        ])->assertOk()
            ->assertJsonPath('data.interest_status', 'not_interested');

        // The whole point of the table: A moves, B and C do not.
        $this->assertSame(
            LeadStatus::Interested,
            LeadProduct::where('product_id', $portal->id)->first()->interest_status,
        );
        $this->assertSame(
            LeadStatus::Interested,
            LeadProduct::where('product_id', $posting->id)->first()->interest_status,
        );
    }

    #[Test]
    public function a_lead_can_hold_several_products_in_different_states(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $states = ['new', 'contacted', 'interested', 'proposal'];

        foreach ($states as $state) {
            $this->postJson("/api/v1/leads/{$lead->id}/products", [
                'product_id' => Product::factory()->create()->id,
                'interest_status' => $state,
            ])->assertStatus(201);
        }

        // BR-PROD-02.
        $this->assertEqualsCanonicalizing(
            $states,
            LeadProduct::pluck('interest_status')->map(fn ($s) => $s->value)->all(),
        );
    }

    #[Test]
    public function product_interest_follows_the_same_transition_matrix(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);
        $product = Product::factory()->create();

        $this->postJson("/api/v1/leads/{$lead->id}/products", ['product_id' => $product->id])
            ->assertStatus(201);

        $interest = LeadProduct::firstOrFail();

        // A product cannot jump New → Negotiation any more than a lead can;
        // the rules a telecaller learns in one place hold in the other.
        $this->patchJson("/api/v1/leads/{$lead->id}/products/{$interest->id}", [
            'interest_status' => 'negotiation',
        ])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'lead.invalid_status_transition');
    }

    // -----------------------------------------------------------------------
    // BR-PROD-03 — product decline is not lead suppression
    // -----------------------------------------------------------------------

    #[Test]
    public function declining_one_product_does_not_suppress_the_lead(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);
        $product = Product::factory()->create();

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => $product->id,
            'interest_status' => 'contacted',
        ])->assertStatus(201);

        $interest = LeadProduct::firstOrFail();

        $this->patchJson("/api/v1/leads/{$lead->id}/products/{$interest->id}", [
            'interest_status' => 'not_interested',
        ])->assertOk();

        // BR-PROD-03. Declining one product is a normal sales outcome; treating
        // it as do-not-contact would end the whole relationship on the strength
        // of a single "no thanks".
        $this->assertSame(0, DncEntry::where('lead_id', $lead->id)->count());
        $this->assertFalse($lead->fresh()->is_suppressed);

        // The lead sits at Contacted because the product was added there
        // (BR-STAT-04) - declining it afterwards does not pull the lead back
        // either. Product state moves; lead state does not follow downwards.
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // BR-STAT-04 — the lead follows its furthest-advanced product
    // -----------------------------------------------------------------------

    #[Test]
    public function the_lead_advances_to_match_its_furthest_product(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'contacted',
        ])->assertStatus(201);

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);

        // BR-SALE-01: Proposal requires an open Opportunity with a product and
        // a value. Without one, the propagation below is refused rather than
        // erroring (LeadProductService::syncLeadStatus logs and moves on), so
        // this is here to let the advance actually happen.
        $opportunity = Opportunity::factory()->create(['lead_id' => $lead->id, 'value' => 5000]);
        OpportunityProduct::create([
            'opportunity_id' => $opportunity->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'unit_price' => 5000,
            'line_total' => 5000,
        ]);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'proposal',
        ])->assertStatus(201);

        $this->assertSame(LeadStatus::Proposal, $lead->fresh()->status);

        // Recorded as a system change with no actor — nobody chose it.
        $this->assertDatabaseHas('lead_status_history', [
            'lead_id' => $lead->id,
            'to_status' => 'proposal',
            'source_channel' => 'system',
            'changed_by' => null,
        ]);
    }

    #[Test]
    public function the_lead_does_not_advance_to_proposal_from_a_product_change_without_an_opportunity(): void
    {
        // The other half of BR-SALE-01: the product interest can legitimately
        // reach `proposal` while the lead itself has no costed opportunity, and
        // that must not be a way around the rule enforced on the direct status
        // endpoint. The product write still succeeds; only the propagated lead
        // advance is refused.
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Contacted);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'proposal',
        ])->assertStatus(201);

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    #[Test]
    public function the_lead_status_never_goes_backwards_from_a_product_change(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Negotiation);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'contacted',
        ])->assertStatus(201);

        // A less-advanced product does not drag the lead back.
        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    #[Test]
    public function a_closed_lead_is_not_reopened_by_a_product_edit(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Lost);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'interested',
        ])->assertStatus(201);

        // Reopening is a supervisory act with a reason attached. A side effect
        // of a product edit is not that, so the advance is silently skipped —
        // the product change itself was valid and is saved.
        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);
        $this->assertSame(1, LeadProduct::count());
    }

    #[Test]
    public function a_product_never_auto_converts_the_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'proposal',
        ])->assertStatus(201);

        $interest = LeadProduct::firstOrFail();

        $this->patchJson("/api/v1/leads/{$lead->id}/products/{$interest->id}", [
            'interest_status' => 'converted',
        ])->assertOk();

        // Converted needs a sale behind it and is terminal — an automatic one
        // could not be undone.
        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // Details, removal, scoping
    // -----------------------------------------------------------------------

    #[Test]
    public function a_quoted_value_can_be_updated_without_a_status_change(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'interested',
        ])->assertStatus(201);

        $interest = LeadProduct::firstOrFail();

        $this->patchJson("/api/v1/leads/{$lead->id}/products/{$interest->id}", [
            'quoted_value' => 48000,
            'notes' => 'Annual, paid quarterly',
        ])->assertOk()
            ->assertJsonPath('data.quoted_value', '48000.00')
            ->assertJsonPath('data.interest_status', 'interested');
    }

    #[Test]
    public function an_interest_can_be_removed_and_the_removal_is_logged(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
        ])->assertStatus(201);

        $interest = LeadProduct::firstOrFail();

        $this->deleteJson("/api/v1/leads/{$lead->id}/products/{$interest->id}")->assertOk();

        $this->assertSame(0, LeadProduct::count());
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'activity_type' => 'product_interest_removed',
        ]);
    }

    #[Test]
    public function an_interest_belonging_to_another_lead_is_not_reachable(): void
    {
        $user = $this->actingAsRole(RoleName::Admin);
        $mine = Lead::factory()->create();
        $theirs = Lead::factory()->create();

        $this->postJson("/api/v1/leads/{$theirs->id}/products", [
            'product_id' => Product::factory()->create()->id,
        ])->assertStatus(201);

        $interest = LeadProduct::firstOrFail();

        // Scope-bound routes: the id exists, but not under this lead.
        $this->patchJson("/api/v1/leads/{$mine->id}/products/{$interest->id}", [
            'interest_status' => 'interested',
        ])->assertStatus(404);
    }

    #[Test]
    public function a_telecaller_cannot_touch_a_colleagues_product_interest(): void
    {
        $colleague = User::factory()->create();
        $colleague->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());
        $lead = Lead::factory()->create(['assigned_to' => $colleague->id]);

        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
        ])->assertStatus(403);
    }

    #[Test]
    public function the_interest_list_is_readable_by_anyone_who_can_see_the_lead(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/products", [
            'product_id' => Product::factory()->create()->id,
            'interest_status' => 'interested',
        ])->assertStatus(201);

        $this->actingAsRole(RoleName::Viewer);

        $this->getJson("/api/v1/leads/{$lead->id}/products")
            ->assertOk()
            ->assertJsonPath('data.0.interest_status', 'interested');
    }
}
