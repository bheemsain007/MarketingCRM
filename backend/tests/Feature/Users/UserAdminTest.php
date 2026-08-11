<?php

namespace Tests\Feature\Users;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * User administration (T-51, ROLE-01..07, SEC-AUTHZ-05).
 *
 * The privilege-escalation guards are the load-bearing tests here. Everything
 * else in this file is CRUD; those four are the reason the module needs to
 * exist at all rather than being a form over the users table.
 */
class UserAdminTest extends TestCase
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

    private function actingAsRole(RoleName $role): User
    {
        $user = $this->user($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // -----------------------------------------------------------------------
    // Privilege escalation (SEC-AUTHZ-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_can_create_a_user_but_cannot_give_them_a_role(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $id = $this->postJson('/api/v1/users', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'correct-horse-99',
        ])->assertCreated()->json('data.id');

        // `users.manage` creates people; `roles.manage` decides what they may
        // do. Admin deliberately holds only the first, or onboarding somebody
        // and promoting them would be the same power.
        $this->putJson("/api/v1/users/{$id}/roles", ['roles' => [RoleName::Telecaller->value]])
            ->assertStatus(403);
    }

    #[Test]
    public function nobody_can_change_their_own_roles_not_even_a_super_admin(): void
    {
        $superAdmin = $this->actingAsRole(RoleName::SuperAdmin);

        // The rule exists so that compromising one account is not the same as
        // compromising every permission. An exception for the most powerful
        // account would defeat it entirely.
        $this->putJson("/api/v1/users/{$superAdmin->id}/roles", [
            'roles' => [RoleName::SuperAdmin->value],
        ])->assertStatus(403);
    }

    #[Test]
    public function a_super_admin_can_assign_roles_to_someone_else_and_it_is_audited(): void
    {
        $actor = $this->actingAsRole(RoleName::SuperAdmin);
        $target = User::factory()->create();

        $this->putJson("/api/v1/users/{$target->id}/roles", [
            'roles' => [RoleName::Manager->value],
        ])->assertOk();

        $this->assertTrue($target->fresh()->roles->contains('name', RoleName::Manager->value));

        // A role change is how an attacker makes a foothold permanent, so it is
        // the highest-value audit event in the system.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'user_roles_changed',
        ]);
    }

    #[Test]
    public function roles_can_be_removed_entirely(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $target = $this->user(RoleName::Manager);

        $this->putJson("/api/v1/users/{$target->id}/roles", ['roles' => []])->assertOk();

        // An account with no role can sign in and do nothing, which is the safe
        // state to leave somebody in while their access is reviewed.
        $this->assertCount(0, $target->fresh()->roles);
    }

    #[Test]
    public function a_manager_cannot_administer_users_at_all(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $target = User::factory()->create();

        // Manager holds users.view - useful for assignment screens - but not
        // users.manage.
        $this->getJson('/api/v1/users')->assertOk();
        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'correct-horse-99',
        ])->assertStatus(403);
        $this->patchJson("/api/v1/users/{$target->id}", ['name' => 'Y'])->assertStatus(403);
    }

    #[Test]
    public function a_telecaller_cannot_even_list_users(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/users')->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Lockout protection
    // -----------------------------------------------------------------------

    #[Test]
    public function a_user_cannot_disable_their_own_account(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);

        // Not paternalism - an admin who disables themselves may be the only
        // person who could have re-enabled it.
        $this->postJson("/api/v1/users/{$actor->id}/disable")->assertStatus(403);

        $this->assertTrue($actor->fresh()->is_active);
    }

    #[Test]
    public function the_last_active_super_admin_cannot_be_disabled(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $onlySuperAdmin = $this->user(RoleName::SuperAdmin);

        // The system must always have somebody who can restore it.
        $this->postJson("/api/v1/users/{$onlySuperAdmin->id}/disable")
            ->assertStatus(422);

        $this->assertTrue($onlySuperAdmin->fresh()->is_active);
    }

    #[Test]
    public function a_super_admin_can_be_disabled_once_another_one_exists(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $first = $this->user(RoleName::SuperAdmin);
        $this->user(RoleName::SuperAdmin);

        $this->postJson("/api/v1/users/{$first->id}/disable")->assertOk();

        $this->assertFalse($first->fresh()->is_active);
    }

    // -----------------------------------------------------------------------
    // Disabling actually disables
    // -----------------------------------------------------------------------

    #[Test]
    public function disabling_revokes_tokens_and_closes_the_work_session(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->user(RoleName::Telecaller, ['password' => bcrypt('correct-horse-99')]);

        $target->createToken('mobile');
        $target->workSessions()->create([
            'tenant_id' => 0,
            'started_at' => now(),
            'source' => 'web',
        ]);

        $this->postJson("/api/v1/users/{$target->id}/disable")->assertOk();

        // Leaving either behind means a "disabled" user keeps working from an
        // app that never re-authenticates, and keeps accruing attendance time.
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(0, $target->workSessions()->whereNull('ended_at')->count());
    }

    #[Test]
    public function a_disabled_user_cannot_sign_in(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->user(RoleName::Telecaller, ['password' => bcrypt('correct-horse-99')]);

        $this->postJson("/api/v1/users/{$target->id}/disable")->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'correct-horse-99',
        ])->assertStatus(403);
    }

    #[Test]
    public function a_disabled_user_can_be_re_enabled(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->user(RoleName::Telecaller);

        $this->postJson("/api/v1/users/{$target->id}/disable")->assertOk();
        $this->postJson("/api/v1/users/{$target->id}/enable")->assertOk();

        $this->assertTrue($target->fresh()->is_active);
    }

    // -----------------------------------------------------------------------
    // Ordinary CRUD
    // -----------------------------------------------------------------------

    #[Test]
    public function a_created_user_starts_with_no_roles(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $response = $this->postJson('/api/v1/users', [
            'name' => 'New Person',
            'email' => 'NEW@Example.COM',
            'password' => 'correct-horse-99',
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        // Emails are lower-cased, or the same person signs up twice.
        $this->assertSame('new@example.com', $user->email);
        $this->assertCount(0, $user->roles);
        $this->assertStringContainsString('assign a role', $response->json('message'));
    }

    #[Test]
    public function a_weak_password_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);

        // An account created by an admin is not a lesser account - it gets the
        // same strength rule as a self-service change.
        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'short',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_duplicate_email_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $existing = User::factory()->create();

        $this->postJson('/api/v1/users', [
            'name' => 'X', 'email' => $existing->email, 'password' => 'correct-horse-99',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_user_can_be_edited_without_touching_their_roles(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->user(RoleName::Telecaller);

        $this->patchJson("/api/v1/users/{$target->id}", [
            'name' => 'Renamed Person',
            // Roles are not reachable from a general edit - an Admin who can
            // rename somebody must not thereby be able to promote them.
            'roles' => [RoleName::SuperAdmin->value],
        ])->assertOk();

        $target->refresh();
        $this->assertSame('Renamed Person', $target->name);
        $this->assertTrue($target->roles->contains('name', RoleName::Telecaller->value));
        $this->assertFalse($target->roles->contains('name', RoleName::SuperAdmin->value));
    }

    #[Test]
    public function the_list_can_be_searched_and_filtered_by_role(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->user(RoleName::Telecaller, ['name' => 'Ramesh Kumar']);
        $this->user(RoleName::Manager, ['name' => 'Priya Sharma']);

        $this->getJson('/api/v1/users?q=Ramesh')->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/users?role=manager')->assertOk()->assertJsonCount(1, 'data.items');
    }

    #[Test]
    public function a_user_password_is_never_returned(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->user(RoleName::Telecaller);

        $response = $this->getJson("/api/v1/users/{$target->id}")->assertOk();

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $response->assertDontSee($target->password);
    }
}
