<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Lead assignment (FR-LEAD-08/10/11, BR-ASSIGN-01..05).
 */
class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    #[Test]
    public function a_manager_can_assign_a_lead(): void
    {
        $manager = $this->user(RoleName::Manager);
        $agent = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['user_id' => $agent->id])
            ->assertOk();

        $this->assertSame($agent->id, $lead->fresh()->assigned_to);
    }

    #[Test]
    public function a_telecaller_cannot_assign_leads(): void
    {
        // Otherwise an agent could push a difficult lead onto a colleague, or
        // claim someone else's.
        $agent = $this->user(RoleName::Telecaller);
        $other = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $agent->id]);

        $this->actingAs($agent, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['user_id' => $other->id])
            ->assertStatus(403);
    }

    #[Test]
    public function assignment_history_is_recorded_and_the_previous_one_closed(): void
    {
        // BR-ASSIGN-03: history is the source for conversion attribution, so a
        // reassignment must close the old row rather than overwrite it.
        $manager = $this->user(RoleName::Manager);
        $first = $this->user(RoleName::Telecaller);
        $second = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create();

        $service = app(LeadAssignmentService::class);
        $service->assign($lead, $first, $manager->id);
        $service->assign($lead->fresh(), $second, $manager->id);

        $this->assertDatabaseCount('lead_assignments', 2);
        $this->assertSame(1, $lead->assignments()->whereNull('unassigned_at')->count());
        $this->assertSame($second->id, $lead->fresh()->assigned_to);
    }

    #[Test]
    public function reassignment_preserves_the_leads_notes_and_history(): void
    {
        // FR-LEAD-08 / BR-ASSIGN-04: moving a lead must never destroy work.
        $manager = $this->user(RoleName::Manager);
        $first = $this->user(RoleName::Telecaller);
        $second = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $first->id]);
        $lead->notes()->create(['user_id' => $first->id, 'body' => 'Called on Monday']);

        app(LeadAssignmentService::class)->assign($lead, $second, $manager->id);

        $this->assertDatabaseHas('lead_notes', ['lead_id' => $lead->id, 'body' => 'Called on Monday']);
    }

    #[Test]
    public function the_assignee_is_notified(): void
    {
        $manager = $this->user(RoleName::Manager);
        $agent = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['name' => 'Priya Singh']);

        app(LeadAssignmentService::class)->assign($lead, $agent, $manager->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'type' => 'lead_assigned',
        ]);
    }

    #[Test]
    public function auto_assign_picks_the_least_loaded_telecaller(): void
    {
        // BR-ASSIGN-01 load-balanced: responds to actual workload rather than
        // turn order, which matters when agents work at different speeds.
        config(['crm.assignment.method' => 'load_balanced']);

        $busy = $this->user(RoleName::Telecaller);
        $free = $this->user(RoleName::Telecaller);

        Lead::factory()->count(10)->create([
            'assigned_to' => $busy->id, 'status' => LeadStatus::New->value,
        ]);

        $lead = Lead::factory()->create();
        app(LeadAssignmentService::class)->autoAssign($lead);

        $this->assertSame($free->id, $lead->fresh()->assigned_to);
    }

    #[Test]
    public function closed_leads_do_not_count_toward_a_telecallers_load(): void
    {
        // Converted/lost leads are finished work; counting them would starve a
        // productive agent of new leads.
        config(['crm.assignment.method' => 'load_balanced']);

        $productive = $this->user(RoleName::Telecaller);
        $other = $this->user(RoleName::Telecaller);

        Lead::factory()->count(20)->create([
            'assigned_to' => $productive->id, 'status' => LeadStatus::Converted->value,
        ]);
        Lead::factory()->count(2)->create([
            'assigned_to' => $other->id, 'status' => LeadStatus::New->value,
        ]);

        $lead = Lead::factory()->create();
        app(LeadAssignmentService::class)->autoAssign($lead);

        $this->assertSame($productive->id, $lead->fresh()->assigned_to);
    }

    #[Test]
    public function a_telecaller_at_the_open_lead_cap_is_skipped(): void
    {
        // BR-ASSIGN-02.
        config(['crm.assignment.method' => 'load_balanced', 'crm.assignment.open_lead_cap' => 3]);

        $full = $this->user(RoleName::Telecaller);
        $available = $this->user(RoleName::Telecaller);

        Lead::factory()->count(3)->create(['assigned_to' => $full->id, 'status' => LeadStatus::New->value]);

        $lead = Lead::factory()->create();
        app(LeadAssignmentService::class)->autoAssign($lead);

        $this->assertSame($available->id, $lead->fresh()->assigned_to);
    }

    #[Test]
    public function a_lead_is_left_unassigned_when_nobody_is_eligible(): void
    {
        // Never force-assign to an overloaded agent: an unassigned lead in a
        // queue is recoverable, one buried in an overloaded list is not.
        config(['crm.assignment.method' => 'load_balanced', 'crm.assignment.open_lead_cap' => 1]);

        $agent = $this->user(RoleName::Telecaller);
        Lead::factory()->create(['assigned_to' => $agent->id, 'status' => LeadStatus::New->value]);

        $lead = Lead::factory()->create();
        app(LeadAssignmentService::class)->autoAssign($lead);

        $this->assertNull($lead->fresh()->assigned_to);
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'title' => 'Awaiting assignment - no eligible telecaller',
        ]);
    }

    #[Test]
    public function a_repeat_enquiry_stays_with_the_existing_owner(): void
    {
        // BR-ASSIGN-05: two agents must never be calling the same person.
        config(['crm.assignment.method' => 'load_balanced']);

        $owner = $this->user(RoleName::Telecaller);
        $this->user(RoleName::Telecaller);   // idle agent who would otherwise win

        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);

        app(LeadAssignmentService::class)->autoAssign($lead);

        $this->assertSame($owner->id, $lead->fresh()->assigned_to);
    }

    #[Test]
    public function leads_are_not_assigned_to_disabled_accounts(): void
    {
        $manager = $this->user(RoleName::Manager);
        $disabled = $this->user(RoleName::Telecaller, ['is_active' => false]);
        $lead = Lead::factory()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['user_id' => $disabled->id])
            ->assertStatus(422);
    }

    #[Test]
    public function leads_are_not_assigned_to_users_who_cannot_work_them(): void
    {
        // Assigning to an Accounts user would park the lead where nobody works it.
        $manager = $this->user(RoleName::Manager);
        $accounts = $this->user(RoleName::Viewer);
        $lead = Lead::factory()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['user_id' => $accounts->id])
            ->assertStatus(422);
    }

    #[Test]
    public function a_lead_can_be_unassigned(): void
    {
        $manager = $this->user(RoleName::Manager);
        $agent = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $agent->id]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/leads/{$lead->id}/unassign", ['reason' => 'Agent on leave'])
            ->assertOk();

        $this->assertNull($lead->fresh()->assigned_to);
    }

    #[Test]
    public function eligible_assignees_are_listed_with_their_load(): void
    {
        $manager = $this->user(RoleName::Manager);
        $agent = $this->user(RoleName::Telecaller);
        Lead::factory()->count(4)->create(['assigned_to' => $agent->id, 'status' => LeadStatus::New->value]);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/leads/assignees')
            ->assertOk();

        $entry = collect($response->json('data'))->firstWhere('id', $agent->id);
        $this->assertSame(4, $entry['open_lead_count']);
    }
}
