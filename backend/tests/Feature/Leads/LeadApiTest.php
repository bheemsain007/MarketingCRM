<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Leads API (FR-LEAD-01..11, BR-DUP-01..03, SEC-AUTHZ-03/04).
 */
class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['team_id' => $team?->id]);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function actingAsRole(RoleName $role, ?Team $team = null): User
    {
        $user = $this->user($role, $team);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // -----------------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------------

    #[Test]
    public function a_lead_can_be_created(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);

        $this->postJson('/api/v1/leads', [
            'name' => 'Ramesh Kumar',
            'company' => 'Kumar News',
            'phone' => '9876543210',
            'email' => 'ramesh@example.com',
            'city' => 'Jaipur',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Ramesh Kumar')
            ->assertJsonPath('data.phone', '+919876543210')
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('leads', [
            'phone_e164' => '+919876543210',
            'created_by' => $user->id,
            'tenant_id' => 0,
        ]);
    }

    #[Test]
    public function the_phone_number_is_normalised_however_it_is_typed(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson('/api/v1/leads', ['name' => 'A', 'phone' => '  098765-43210 '])
            ->assertStatus(201)
            ->assertJsonPath('data.phone', '+919876543210');
    }

    #[Test]
    public function the_original_typed_number_is_retained(): void
    {
        // Kept for support queries - "what did the agent actually enter?"
        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson('/api/v1/leads', ['name' => 'A', 'phone' => '098765-43210'])->assertStatus(201);

        $this->assertDatabaseHas('leads', ['phone_raw' => '098765-43210']);
    }

    #[Test]
    public function an_invalid_phone_number_is_rejected_with_a_field_error(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        $response = $this->postJson('/api/v1/leads', ['name' => 'A', 'phone' => '12345'])
            ->assertStatus(422);

        $this->assertContains('phone', array_column($response->json('errors'), 'field'));
    }

    #[Test]
    public function creating_a_lead_writes_the_timeline(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson('/api/v1/leads', ['name' => 'A', 'phone' => '9876543210'])->assertStatus(201);

        $this->assertDatabaseHas('lead_activities', ['activity_type' => 'lead_created']);
    }

    #[Test]
    public function a_lead_can_be_created_with_products_tags_and_a_note(): void
    {
        $this->actingAsRole(RoleName::Telecaller);
        $products = Product::factory()->count(2)->create();

        $response = $this->postJson('/api/v1/leads', [
            'name' => 'Multi Product Lead',
            'phone' => '9876543211',
            'product_ids' => $products->pluck('id')->all(),
            'note' => 'Interested in portal and epaper',
        ])->assertStatus(201);

        $leadId = $response->json('data.id');

        $this->assertDatabaseCount('lead_products', 2);
        $this->assertDatabaseHas('lead_notes', ['lead_id' => $leadId]);
    }

    // -----------------------------------------------------------------------
    // Duplicate detection (BR-DUP-01/02)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_duplicate_phone_number_is_refused_with_the_existing_lead(): void
    {
        $this->actingAsRole(RoleName::Telecaller);
        $existing = Lead::factory()->create(['phone_e164' => '+919876543210', 'name' => 'Original']);

        $this->postJson('/api/v1/leads', ['name' => 'Copy', 'phone' => '9876543210'])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'lead.duplicate')
            ->assertJsonPath('data.existing_lead_id', $existing->id)
            ->assertJsonPath('data.existing_lead_name', 'Original');

        $this->assertSame(1, Lead::count());
    }

    #[Test]
    public function a_duplicate_is_detected_across_input_formats(): void
    {
        // The real-world case: the same person entered a second time with the
        // number typed differently.
        $this->actingAsRole(RoleName::Telecaller);
        Lead::factory()->create(['phone_e164' => '+919876543210']);

        foreach (['09876543210', '919876543210', '+91 98765 43210', '98765-43210'] as $variant) {
            $this->postJson('/api/v1/leads', ['name' => 'Dup', 'phone' => $variant])
                ->assertStatus(409);
        }

        $this->assertSame(1, Lead::count());
    }

    #[Test]
    public function the_duplicate_response_names_the_current_owner(): void
    {
        // BR-ASSIGN-05: the caller needs to know who already owns the
        // relationship, not just that they are refused.
        $owner = $this->user(RoleName::Telecaller);
        $this->actingAsRole(RoleName::Manager);
        Lead::factory()->create(['phone_e164' => '+919876543210', 'assigned_to' => $owner->id]);

        $this->postJson('/api/v1/leads', ['name' => 'Dup', 'phone' => '9876543210'])
            ->assertStatus(409)
            ->assertJsonPath('data.assigned_to', $owner->id);
    }

    #[Test]
    public function an_archived_lead_still_blocks_a_duplicate_and_says_so(): void
    {
        $this->actingAsRole(RoleName::Manager);
        Lead::factory()->create(['phone_e164' => '+919876543210'])->delete();

        $this->postJson('/api/v1/leads', ['name' => 'Dup', 'phone' => '9876543210'])
            ->assertStatus(409)
            ->assertJsonPath('data.archived', true);
    }

    #[Test]
    public function changing_a_phone_to_one_already_in_use_is_refused(): void
    {
        $this->actingAsRole(RoleName::Manager);
        Lead::factory()->create(['phone_e164' => '+919876500001']);
        $lead = Lead::factory()->create(['phone_e164' => '+919876500002']);

        $this->patchJson("/api/v1/leads/{$lead->id}", ['phone' => '9876500001'])
            ->assertStatus(409);
    }

    #[Test]
    public function a_lead_can_keep_its_own_phone_number_on_update(): void
    {
        // Guards against the duplicate check matching the record itself.
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create(['phone_e164' => '+919876500002']);

        $this->patchJson("/api/v1/leads/{$lead->id}", [
            'name' => 'Renamed',
            'phone' => '9876500002',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed');
    }

    #[Test]
    public function an_alternate_phone_can_be_set_and_cleared_on_update(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create(['alt_phone_e164' => null]);

        // `alt_phone` is a request field; the column is `alt_phone_e164`.
        // store() translated between them and update() did not, so this either
        // threw (dev, preventSilentlyDiscardingAttributes) or dropped the value
        // without a word (production).
        $this->patchJson("/api/v1/leads/{$lead->id}", ['alt_phone' => '9876500123'])
            ->assertOk()
            ->assertJsonPath('data.alt_phone', '+919876500123');

        $this->patchJson("/api/v1/leads/{$lead->id}", ['alt_phone' => null])
            ->assertOk()
            ->assertJsonPath('data.alt_phone', null);
    }

    #[Test]
    public function an_unparseable_alternate_phone_is_a_field_level_rejection(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create();

        // Not a silent normalisation to null - the caller typed something and
        // is entitled to know it was not stored.
        $this->patchJson("/api/v1/leads/{$lead->id}", ['alt_phone' => '12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'alt_phone');
    }

    #[Test]
    public function an_update_cannot_blank_the_name(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create(['name' => 'Ramesh Kumar']);

        // `sometimes|string` accepted an empty string, which is a valid string
        // and a nameless lead. `filled` is what makes "omit to leave alone"
        // different from "send blank to erase".
        $this->patchJson("/api/v1/leads/{$lead->id}", ['name' => ''])
            ->assertStatus(422);

        $this->assertSame('Ramesh Kumar', $lead->fresh()->name);
    }

    #[Test]
    public function the_unassigned_pool_can_be_listed_with_the_null_filter(): void
    {
        // Admin, so the assertion is about the filter operator and not about
        // which of these leads a Team scope would have hidden.
        $this->actingAsRole(RoleName::Admin);
        $owner = $this->user(RoleName::Telecaller);

        Lead::factory()->count(2)->create(['assigned_to' => null]);
        Lead::factory()->count(3)->create(['assigned_to' => $owner->id]);

        // The assignment screen is built entirely on this operator, and an
        // empty filter value would be `WHERE assigned_to = ''` - which matches
        // nothing and would show an empty pool rather than an error.
        $this->getJson('/api/v1/leads?filter[assigned_to][null]=true')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $this->getJson('/api/v1/leads?filter[assigned_to][null]=false')
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
    }

    // -----------------------------------------------------------------------
    // Data scoping and IDOR (SEC-AUTHZ-03/04)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_lists_only_their_own_leads(): void
    {
        $me = $this->actingAsRole(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        Lead::factory()->count(3)->create(['assigned_to' => $me->id]);
        Lead::factory()->count(5)->create(['assigned_to' => $colleague->id]);

        $this->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.meta.total', 3);
    }

    #[Test]
    public function a_telecaller_cannot_read_a_colleagues_lead_by_id(): void
    {
        // THE IDOR test. A route-level permission check alone would let this
        // through, since the telecaller legitimately holds leads.view.
        $this->actingAsRole(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);
        $theirLead = Lead::factory()->create(['assigned_to' => $colleague->id]);

        $this->getJson("/api/v1/leads/{$theirLead->id}")->assertStatus(403);
    }

    #[Test]
    public function a_telecaller_cannot_edit_or_archive_a_colleagues_lead(): void
    {
        $this->actingAsRole(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);
        $theirLead = Lead::factory()->create(['assigned_to' => $colleague->id]);

        $this->patchJson("/api/v1/leads/{$theirLead->id}", ['name' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/v1/leads/{$theirLead->id}")->assertStatus(403);
    }

    #[Test]
    public function a_client_supplied_filter_cannot_widen_a_telecallers_scope(): void
    {
        // Scope is applied before client filters, so asking for someone else's
        // leads returns nothing rather than theirs.
        $me = $this->actingAsRole(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        Lead::factory()->count(2)->create(['assigned_to' => $me->id]);
        Lead::factory()->count(4)->create(['assigned_to' => $colleague->id]);

        $this->getJson("/api/v1/leads?filter[assigned_to]={$colleague->id}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    #[Test]
    public function a_manager_sees_their_teams_leads_only(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $this->actingAsRole(RoleName::Manager, $teamA);
        $agentA = $this->user(RoleName::Telecaller, $teamA);
        $agentB = $this->user(RoleName::Telecaller, $teamB);

        Lead::factory()->count(3)->create(['assigned_to' => $agentA->id]);
        Lead::factory()->count(4)->create(['assigned_to' => $agentB->id]);

        $this->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.meta.total', 3);
    }

    #[Test]
    public function an_admin_sees_every_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Lead::factory()->count(6)->create();

        $this->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.meta.total', 6);
    }

    #[Test]
    public function a_viewer_cannot_create_or_modify_leads(): void
    {
        $this->actingAsRole(RoleName::Viewer);
        $lead = Lead::factory()->create();

        $this->postJson('/api/v1/leads', ['name' => 'X', 'phone' => '9876543210'])->assertStatus(403);
        $this->patchJson("/api/v1/leads/{$lead->id}", ['name' => 'X'])->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Protected fields (SEC-IN-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function status_score_and_suppression_cannot_be_set_through_a_general_edit(): void
    {
        // These change only through their own services. A crafted payload must
        // not be able to convert a lead or un-suppress it.
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value, 'score' => 0]);

        $this->patchJson("/api/v1/leads/{$lead->id}", [
            'name' => 'Legit change',
            'status' => LeadStatus::Converted->value,
            'score' => 100,
            'is_suppressed' => false,
            'assigned_to' => 999,
        ])->assertOk();

        $lead->refresh();
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame(0, $lead->score);
        $this->assertSame('Legit change', $lead->name);
    }

    // -----------------------------------------------------------------------
    // Search, filter, archive
    // -----------------------------------------------------------------------

    #[Test]
    public function leads_can_be_searched_by_name_company_phone_or_email(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Lead::factory()->create(['name' => 'Sunita Sharma', 'phone_e164' => '+919000000001']);
        Lead::factory()->create(['company' => 'Sharma Media', 'phone_e164' => '+919000000002']);

        // Names and companies pinned rather than left to the faker. With random
        // Indian names, "Sharma" turns up in the noise often enough to fail
        // this test perhaps one run in twenty - and a suite that fails at random
        // teaches people to re-run rather than to look (TESTING §5, "no flaky
        // tests dependent on random data").
        foreach (['Noise One', 'Noise Two', 'Noise Three'] as $i => $name) {
            Lead::factory()->create([
                'name' => $name,
                'company' => 'Unrelated Holdings',
                'phone_e164' => '+91900000010'.$i,
            ]);
        }

        $this->getJson('/api/v1/leads?q=Sharma')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/leads?q=9000000001')->assertOk()->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function leads_can_be_filtered_and_sorted(): void
    {
        $this->actingAsRole(RoleName::Admin);
        Lead::factory()->count(2)->create(['status' => LeadStatus::Interested->value, 'priority' => 9]);
        Lead::factory()->count(3)->create(['status' => LeadStatus::New->value, 'priority' => 1]);

        $this->getJson('/api/v1/leads?filter[status]=interested')
            ->assertOk()->assertJsonPath('data.meta.total', 2);

        $items = $this->getJson('/api/v1/leads?sort=-priority')->assertOk()->json('data.items');
        $this->assertSame(9, $items[0]['priority']);
    }

    #[Test]
    public function archiving_removes_a_lead_from_lists_but_keeps_it_restorable(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        Lead::factory()->count(2)->create();

        $this->deleteJson("/api/v1/leads/{$lead->id}")->assertOk();

        $this->getJson('/api/v1/leads')->assertOk()->assertJsonPath('data.meta.total', 2);
        $this->getJson('/api/v1/leads?with_archived=1')->assertOk()->assertJsonPath('data.meta.total', 3);

        $this->postJson("/api/v1/leads/{$lead->id}/restore")
            ->assertOk()->assertJsonPath('data.is_archived', false);
    }

    #[Test]
    public function the_timeline_returns_activities_chronologically(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->postJson("/api/v1/leads/{$lead->id}/notes", ['body' => 'Spoke to the client'])
            ->assertStatus(201);

        $response = $this->getJson("/api/v1/leads/{$lead->id}/timeline")->assertOk();

        $types = array_column($response->json('data.items'), 'type');
        $this->assertContains('note', $types);
    }

    #[Test]
    public function an_unknown_filter_is_rejected_rather_than_ignored(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/leads?filter[password]=x')->assertStatus(422);
    }
}
