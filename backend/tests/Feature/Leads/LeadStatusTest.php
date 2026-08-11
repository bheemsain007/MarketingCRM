<?php

namespace Tests\Feature\Leads;

use App\Enums\DncReason;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Enums\StatusSource;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Leads\LeadStatusService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lead status transitions (FR-STAT-01..03, BR-STAT-01..05, BR-DNC-07).
 *
 * The 11×11 matrix itself is covered exhaustively by
 * `Unit\Enums\LeadStatusTransitionTest`. This suite covers what the *service*
 * adds on top: authority, history, suppression, and the refusals a caller
 * actually sees.
 */
class LeadStatusTest extends TestCase
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

    private function leadFor(User $user, LeadStatus $status = LeadStatus::New): Lead
    {
        return Lead::factory()->create([
            'status' => $status->value,
            'assigned_to' => $user->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // The matrix, as the API enforces it
    // -----------------------------------------------------------------------

    #[Test]
    public function a_legal_transition_moves_the_lead(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => 'contacted',
        ])->assertOk()
            ->assertJsonPath('data.status', 'contacted');

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    #[Test]
    public function a_forward_skip_is_allowed_where_it_reflects_reality(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // New → Interested on a first connected call is exactly how this goes.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'interested'])
            ->assertOk();
    }

    #[Test]
    public function an_illegal_transition_is_rejected_and_says_what_is_allowed(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // You do not send a proposal to someone you have never spoken to.
        $response = $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'proposal']);

        $response->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'lead.invalid_status_transition')
            // The legal moves come back with the refusal so a client can
            // correct itself instead of guessing.
            ->assertJsonPath('data.from', 'new')
            ->assertJsonPath('data.to', 'proposal');

        $this->assertContains('contacted', $response->json('data.allowed'));
        $this->assertNotContains('proposal', $response->json('data.allowed'));
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    #[Test]
    public function setting_the_status_it_already_has_is_rejected(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Contacted);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The lead is already Contacted.');
    }

    #[Test]
    public function a_value_that_is_not_a_status_is_rejected_before_the_matrix(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // FR-STAT-01. "Not a status" and "not a legal move" are different
        // failures and get different messages.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'almost_sold'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'status');
    }

    #[Test]
    public function nothing_returns_to_new(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Contacted);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'new'])
            ->assertStatus(422);
    }

    #[Test]
    public function converted_cannot_be_set_directly_because_it_needs_a_sale(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);

        // BR-STAT-05. Converted is terminal and drives revenue reporting and
        // telecaller pay — a lead marked converted with no sale behind it
        // cannot be walked back, because the matrix offers no way out.
        //
        // Phase 22 unblocked this for leads that DO have a sale; the guard is
        // now `sale` rather than `sale_with_payment`, because payments only
        // arrive in Phase 23 (T-57). `SalesPipelineTest` covers the positive
        // case.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'converted'])
            ->assertStatus(422)
            ->assertJsonPath('data.requires', 'sale');

        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    #[Test]
    public function a_converted_lead_is_terminal(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Converted->value]);

        // Repeat business creates a new opportunity; it never reverts this.
        foreach (['negotiation', 'interested', 'lost', 'contacted'] as $target) {
            $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => $target])
                ->assertStatus(422)
                ->assertJsonPath('data.is_terminal', true);
        }
    }

    #[Test]
    public function an_archived_lead_cannot_change_status(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->archived()->create();

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertStatus(404);   // Archived leads are not route-bound at all.
    }

    // -----------------------------------------------------------------------
    // Reopens (BR-STAT-02 ⚑)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_cannot_reopen_a_closed_lead(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Lost);

        // Without this, an agent can quietly recycle their own dead leads to
        // flatter their conversion numbers.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => 'contacted',
            'reason' => 'They called back',
        ])->assertStatus(403);

        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);
    }

    #[Test]
    public function a_manager_can_reopen_with_a_reason(): void
    {
        $team = Team::factory()->create();
        $manager = $this->actingAsRole(RoleName::Manager, $team);
        $lead = Lead::factory()->create(['status' => LeadStatus::Lost->value]);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => 'interested',
            'reason' => 'Customer called back after budget approval',
        ])->assertOk()
            ->assertJsonPath('data.status', 'interested');

        $this->assertDatabaseHas('lead_status_history', [
            'lead_id' => $lead->id,
            'from_status' => 'lost',
            'to_status' => 'interested',
            'changed_by' => $manager->id,
            'reason' => 'Customer called back after budget approval',
        ]);
    }

    #[Test]
    public function a_reopen_without_a_reason_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create(['status' => LeadStatus::Lost->value]);

        // "Why" is the whole question when a reopened lead later converts.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'reason');

        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);
    }

    #[Test]
    public function a_lost_lead_cannot_jump_back_into_the_middle_of_the_pipeline(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create(['status' => LeadStatus::Lost->value]);

        // Reopening restarts the conversation; it does not restore the deal.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => 'negotiation',
            'reason' => 'Trying it on',
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Suppression (BR-DNC-07, BR-DNC-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function not_interested_writes_suppression_automatically(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Contacted);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'not_interested'])
            ->assertOk();

        // BR-DNC-07. Requiring a separate manual step would mean the CRM knows
        // the lead said no while still campaigning to them until someone
        // remembers.
        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'reason' => DncReason::NotInterested->value,
            'channel' => null,          // Not Interested blocks everything.
            'source' => 'system',
            'active' => true,
        ]);

        // The denormalised list-filter flag follows the entries, never the
        // other way round (BR-DNC-01).
        $this->assertTrue($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function lost_does_not_suppress(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Contacted);

        // "We did not win it" is not "they told us no" — suppressing on Lost
        // would block legitimate re-marketing to price losses.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'lost'])
            ->assertOk();

        $this->assertSame(0, DncEntry::where('lead_id', $lead->id)->count());
        $this->assertFalse($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function reopening_from_not_interested_does_not_clear_suppression(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Contacted);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'not_interested'])->assertOk();

        // Admin rather than Manager: a Manager is team-scoped, and this lead
        // belongs to a telecaller outside their team. Authority is covered by
        // its own tests; this one is about what happens to suppression.
        $this->actingAsRole(RoleName::Admin);
        $this->patchJson("/api/v1/leads/{$lead->id}/status", [
            'status' => 'interested',
            'reason' => 'Asked us to try again next quarter',
        ])->assertOk();

        // BR-DNC-06. Somebody who said "do not contact me" has not changed
        // their mind because an internal status moved. Un-suppressing is a
        // separate, separately-audited action.
        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'reason' => DncReason::NotInterested->value,
            'active' => true,
        ]);
        $this->assertTrue($lead->fresh()->is_suppressed);
    }

    // -----------------------------------------------------------------------
    // History and timeline (BR-STAT-03, FR-STAT-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function every_change_writes_append_only_history(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted', 'source' => 'call'])->assertOk();
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'interested'])->assertOk();

        $this->assertSame(2, $lead->statusHistory()->count());

        $this->assertDatabaseHas('lead_status_history', [
            'lead_id' => $lead->id,
            'from_status' => 'new',
            'to_status' => 'contacted',
            'changed_by' => $user->id,
            'source_channel' => 'call',
        ]);
    }

    #[Test]
    public function a_status_change_also_lands_on_the_timeline(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk();

        // FR-LEAD-09: one chronological view, not a separate place to look.
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'activity_type' => 'status_changed',
        ]);
    }

    #[Test]
    public function the_history_endpoint_returns_newest_first_and_filters(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])->assertOk();
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'interested'])->assertOk();

        $this->getJson("/api/v1/leads/{$lead->id}/status-history")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.items.0.to', 'interested');

        $this->getJson("/api/v1/leads/{$lead->id}/status-history?filter[to_status]=contacted")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function a_system_change_is_recorded_without_an_actor(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        app(LeadStatusService::class)->change(
            $lead,
            LeadStatus::Contacted,
            actor: null,
            source: StatusSource::System,
        );

        // Crediting a person for an automatic advance would corrupt
        // attribution, which drives telecaller pay (GLOSSARY §2.6).
        $this->assertDatabaseHas('lead_status_history', [
            'lead_id' => $lead->id,
            'to_status' => 'contacted',
            'changed_by' => null,
            'source_channel' => 'system',
        ]);
    }

    // -----------------------------------------------------------------------
    // Transitions endpoint
    // -----------------------------------------------------------------------

    #[Test]
    public function the_transitions_endpoint_lists_only_moves_this_caller_can_make(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user, LeadStatus::Lost);

        $response = $this->getJson("/api/v1/leads/{$lead->id}/transitions")->assertOk();

        // A telecaller cannot reopen, so a client rendering these buttons
        // shows none — rather than discovering the matrix one 403 at a time.
        $this->assertSame([], $response->json('data.available'));
        $this->assertTrue($response->json('data.current.is_closed'));
    }

    #[Test]
    public function a_manager_sees_the_reopen_moves(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $lead = Lead::factory()->create(['status' => LeadStatus::NotInterested->value]);

        $response = $this->getJson("/api/v1/leads/{$lead->id}/transitions")->assertOk();

        $statuses = array_column($response->json('data.available'), 'status');

        $this->assertEqualsCanonicalizing(['contacted', 'interested'], $statuses);
        $this->assertTrue($response->json('data.available.0.is_reopen'));
    }

    #[Test]
    public function converted_never_appears_as_an_available_transition(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);

        $statuses = array_column(
            $this->getJson("/api/v1/leads/{$lead->id}/transitions")->json('data.available'),
            'status',
        );

        // The matrix permits it; the service does not, until sales exist.
        // Advertising a button that always 422s would be worse than hiding it.
        $this->assertNotContains('converted', $statuses);
        $this->assertContains('decision_pending', $statuses);
    }

    // -----------------------------------------------------------------------
    // Authorisation
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_cannot_change_a_colleagues_lead_status(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create([
            'status' => LeadStatus::New->value,
            'assigned_to' => $colleague->id,
        ]);

        $this->actingAsRole(RoleName::Telecaller);

        // The classic IDOR, on the endpoint that moves revenue-bearing state.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertStatus(403);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    #[Test]
    public function a_read_only_role_cannot_change_status(): void
    {
        $this->actingAsRole(RoleName::Accounts);
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        // Accounts holds leads.view but not leads.update.
        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertStatus(403);
    }

    #[Test]
    public function status_still_cannot_be_set_through_the_generic_update(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // SEC-IN-06. The field is not in the update rules, so it is dropped
        // rather than refused - the legitimate part of the edit still applies.
        // What matters is that the matrix cannot be reached this way.
        $this->patchJson("/api/v1/leads/{$lead->id}", [
            'name' => 'Renamed',
            'status' => 'converted',
        ])->assertOk();

        $lead->refresh();

        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame('Renamed', $lead->name);
        // And no history was written, because no status change happened.
        $this->assertSame(0, $lead->statusHistory()->count());
    }
}
