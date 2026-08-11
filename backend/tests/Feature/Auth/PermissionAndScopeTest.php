<?php

namespace Tests\Feature\Auth;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\ApiResponse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RBAC and data scoping (ROLE-01..07, SEC-AUTHZ-01..06).
 *
 * The rules protected here are the ones whose failure is a data breach rather
 * than a bug: a telecaller reading the whole lead database, or exporting it.
 */
class PermissionAndScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWith(RoleName $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['team_id' => $team?->id]);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    // -----------------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------------

    #[Test]
    public function a_super_admin_bypasses_every_permission_check(): void
    {
        $user = $this->userWith(RoleName::SuperAdmin);

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($user->hasPermission($permission));
        }
    }

    #[Test]
    public function an_admin_cannot_manage_roles_or_credentials(): void
    {
        // ROLE-02: Admin runs the business but does not hold the keys.
        $user = $this->userWith(RoleName::Admin);

        $this->assertFalse($user->hasPermission(Permission::RolesManage));
        $this->assertFalse($user->hasPermission(Permission::CredentialsManage));
        $this->assertTrue($user->hasPermission(Permission::LeadsExport));
    }

    #[Test]
    public function a_telecaller_cannot_export_reassign_or_reopen(): void
    {
        // The insider-threat set (SEC-PII-04, BR-STAT-02).
        $user = $this->userWith(RoleName::Telecaller);

        $this->assertFalse($user->hasPermission(Permission::LeadsExport));
        $this->assertFalse($user->hasPermission(Permission::LeadsAssign));
        $this->assertFalse($user->hasPermission(Permission::LeadsReopen));
        $this->assertFalse($user->hasPermission(Permission::UsersManage));

        // But can do their actual job.
        $this->assertTrue($user->hasPermission(Permission::LeadsView));
        $this->assertTrue($user->hasPermission(Permission::CallsCreate));
        $this->assertTrue($user->hasPermission(Permission::FollowUpsManage));
    }

    #[Test]
    public function a_telecaller_can_suppress_a_lead_but_not_un_suppress_it(): void
    {
        // BR-DNC-06: removing suppression is elevated. Otherwise the agent who
        // marked a lead Not Interested could quietly undo it.
        $user = $this->userWith(RoleName::Telecaller);

        $this->assertTrue($user->hasPermission(Permission::DncCreate));
        $this->assertFalse($user->hasPermission(Permission::DncRemove));

        $this->assertTrue($this->userWith(RoleName::Manager)->hasPermission(Permission::DncRemove));
    }

    #[Test]
    public function accounts_is_read_only_on_leads(): void
    {
        // ROLE-05.
        $user = $this->userWith(RoleName::Accounts);

        $this->assertTrue($user->hasPermission(Permission::LeadsView));
        $this->assertFalse($user->hasPermission(Permission::LeadsUpdate));
        $this->assertTrue($user->hasPermission(Permission::PaymentsRefund));
    }

    #[Test]
    public function a_viewer_can_perform_no_mutations(): void
    {
        $user = $this->userWith(RoleName::Viewer);

        foreach ([
            Permission::LeadsCreate, Permission::LeadsUpdate, Permission::CallsCreate,
            Permission::MessagesSend, Permission::CampaignsRun, Permission::PaymentsManage,
        ] as $permission) {
            $this->assertFalse($user->hasPermission($permission), $permission->value.' must be denied');
        }

        $this->assertTrue($user->hasPermission(Permission::ReportsBusiness));
    }

    #[Test]
    public function a_user_with_no_roles_has_no_permissions_and_the_narrowest_scope(): void
    {
        // An unconfigured account must see nothing, not everything.
        $user = User::factory()->create();

        $this->assertFalse($user->hasPermission(Permission::LeadsView));
        $this->assertSame(DataScope::Own, $user->dataScope());
    }

    // -----------------------------------------------------------------------
    // Data scope (SEC-AUTHZ-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function roles_carry_the_expected_data_scope(): void
    {
        $this->assertSame(DataScope::All, $this->userWith(RoleName::Admin)->dataScope());
        $this->assertSame(DataScope::Team, $this->userWith(RoleName::Manager)->dataScope());
        $this->assertSame(DataScope::Own, $this->userWith(RoleName::Telecaller)->dataScope());
    }

    #[Test]
    public function the_broadest_scope_wins_when_a_user_holds_several_roles(): void
    {
        $user = $this->userWith(RoleName::Telecaller);
        $user->roles()->attach(Role::where('name', RoleName::Manager->value)->first());

        $this->assertSame(DataScope::Team, $user->fresh()->dataScope());
    }

    #[Test]
    public function a_telecaller_sees_only_their_own_leads(): void
    {
        $mine = $this->userWith(RoleName::Telecaller);
        $theirs = $this->userWith(RoleName::Telecaller);

        Lead::factory()->count(3)->create(['assigned_to' => $mine->id]);
        Lead::factory()->count(5)->create(['assigned_to' => $theirs->id]);
        Lead::factory()->count(2)->create();     // unassigned

        $visible = $mine->applyDataScope(Lead::query())->get();

        $this->assertCount(3, $visible);
        $this->assertTrue($visible->every(fn (Lead $l) => $l->assigned_to === $mine->id));
    }

    #[Test]
    public function a_manager_sees_their_teams_leads_but_not_another_teams(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $manager = $this->userWith(RoleName::Manager, $teamA);
        $agentA = $this->userWith(RoleName::Telecaller, $teamA);
        $agentB = $this->userWith(RoleName::Telecaller, $teamB);

        Lead::factory()->count(4)->create(['assigned_to' => $agentA->id]);
        Lead::factory()->count(6)->create(['assigned_to' => $agentB->id]);

        $visible = $manager->applyDataScope(Lead::query())->get();

        $this->assertCount(4, $visible);
    }

    #[Test]
    public function a_manager_with_no_team_sees_only_the_unassigned_pool(): void
    {
        // Regression, and a security one. Laravel rewrites
        // `where('team_id', null)` as `WHERE team_id IS NULL`, so a Team-scoped
        // user with no team matched every record owned by any OTHER teamless
        // user - which in an install where nobody has been put in a team yet is
        // every lead in the system (SEC-AUTHZ-03).
        //
        // `LeadPolicy::withinScope()` already guarded this, so the two
        // disagreed: the list leaked rows the policy then refused by id.
        $teamlessManager = $this->userWith(RoleName::Manager);
        $teamlessAgent = $this->userWith(RoleName::Telecaller);

        Lead::factory()->count(5)->create(['assigned_to' => $teamlessAgent->id]);
        Lead::factory()->count(2)->create(['assigned_to' => null]);

        $visible = $teamlessManager->applyDataScope(Lead::query())->get();

        // The unassigned pool only - a manager must still be able to see leads
        // with no owner in order to assign them.
        $this->assertCount(2, $visible);
    }

    #[Test]
    public function an_admin_sees_everything(): void
    {
        $admin = $this->userWith(RoleName::Admin);
        Lead::factory()->count(7)->create();

        $this->assertCount(7, $admin->applyDataScope(Lead::query())->get());
    }

    // -----------------------------------------------------------------------
    // Middleware
    // -----------------------------------------------------------------------

    #[Test]
    public function the_permission_middleware_blocks_users_without_the_permission(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'permission:leads.export'])
            ->get('api/v1/t-export', fn () => ApiResponse::success());

        $this->actingAs($this->userWith(RoleName::Telecaller), 'sanctum')
            ->getJson('/api/v1/t-export')
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'auth.forbidden');

        $this->actingAs($this->userWith(RoleName::Manager), 'sanctum')
            ->getJson('/api/v1/t-export')
            ->assertOk();
    }

    #[Test]
    public function using_a_sensitive_permission_is_audited_even_when_allowed(): void
    {
        // SEC-AUD-02: bulk export is the highest-value insider action, so
        // legitimate use is recorded too.
        Route::middleware(['api', 'auth:sanctum', 'permission:leads.export'])
            ->get('api/v1/t-export-audit', fn () => ApiResponse::success());

        $manager = $this->userWith(RoleName::Manager);

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/t-export-audit')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->id,
            'action' => 'permission_used',
            'description' => 'leads.export',
        ]);
    }

    #[Test]
    public function a_non_audited_permission_does_not_write_an_audit_row(): void
    {
        // Auditing everything would bury the signal that matters.
        Route::middleware(['api', 'auth:sanctum', 'permission:leads.view'])
            ->get('api/v1/t-view', fn () => ApiResponse::success());

        $user = $this->userWith(RoleName::Telecaller);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/t-view')->assertOk();

        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $user->id,
            'action' => 'permission_used',
        ]);
    }

    #[Test]
    public function the_seeder_is_idempotent_and_preserves_user_role_assignments(): void
    {
        // Re-running after a phase adds new permissions without un-assigning
        // anyone's role - important because it runs on production.
        $user = $this->userWith(RoleName::Manager);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(6, Role::count());
        $this->assertCount(count(Permission::cases()), \App\Models\Permission::all());
        $this->assertTrue($user->fresh()->hasRole(RoleName::Manager));
    }
}
