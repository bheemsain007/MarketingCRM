<?php

namespace Tests\Feature\Attendance;

use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Models\UserWorkSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Activity signals (FR-ATT-02): tracked lead actions writing a ping onto the
 * caller's open session, and the page_view heartbeat endpoint.
 */
class AttendancePingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function telecaller(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        return $user->fresh();
    }

    private function actingAsTelecaller(): User
    {
        $user = $this->telecaller();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function openSession(User $user, string $source = 'web'): UserWorkSession
    {
        return UserWorkSession::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(30),
            'source' => $source,
        ]);
    }

    // -----------------------------------------------------------------------
    // Tracked lead actions (FR-ATT-02's list: calls, notes, status changes,
    // sends, follow-ups)
    // -----------------------------------------------------------------------

    #[Test]
    public function adding_a_note_writes_an_attendance_ping_against_the_open_session(): void
    {
        $user = $this->actingAsTelecaller();
        $session = $this->openSession($user);
        $lead = Lead::factory()->create(['assigned_to' => $user->id]);

        $this->postJson("/api/v1/leads/{$lead->id}/notes", ['body' => 'Called back, wants a demo.'])
            ->assertStatus(201);

        $this->assertDatabaseHas('user_activity_pings', [
            'user_id' => $user->id,
            'work_session_id' => $session->id,
            'action_type' => 'note',
            'reference_type' => Lead::class,
            'reference_id' => $lead->id,
        ]);
    }

    #[Test]
    public function a_status_change_writes_a_status_change_ping(): void
    {
        $user = $this->actingAsTelecaller();
        $session = $this->openSession($user);
        $lead = Lead::factory()->create(['assigned_to' => $user->id, 'status' => 'new']);

        $this->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => 'contacted'])
            ->assertOk();

        $this->assertDatabaseHas('user_activity_pings', [
            'user_id' => $user->id,
            'work_session_id' => $session->id,
            'action_type' => 'status_change',
        ]);
    }

    #[Test]
    public function an_action_with_no_open_session_writes_no_ping(): void
    {
        // Signed in through the test helper, never through /auth/login, so
        // there is no work session at all - the automated-action case FR-ATT-02
        // says must be skipped rather than erroring.
        $user = $this->actingAsTelecaller();
        $lead = Lead::factory()->create(['assigned_to' => $user->id]);

        $this->postJson("/api/v1/leads/{$lead->id}/notes", ['body' => 'No session open.'])
            ->assertStatus(201);

        $this->assertDatabaseCount('user_activity_pings', 0);
    }

    #[Test]
    public function an_untracked_lead_activity_writes_no_ping(): void
    {
        // Lead creation writes a timeline entry (lead_created) but is not one
        // of FR-ATT-02's five tracked action types - only calls, notes, status
        // changes, sends and follow-ups count as attendance evidence.
        $user = $this->actingAsTelecaller();
        $this->openSession($user);

        $this->postJson('/api/v1/leads', [
            'name' => 'Priya Sharma',
            'phone' => '9123456780',
        ])->assertStatus(201);

        $this->assertDatabaseCount('user_activity_pings', 0);
    }

    // -----------------------------------------------------------------------
    // page_view heartbeat
    // -----------------------------------------------------------------------

    #[Test]
    public function the_heartbeat_endpoint_records_a_page_view_ping(): void
    {
        $user = $this->actingAsTelecaller();
        $session = $this->openSession($user);

        $this->postJson('/api/v1/attendance/ping')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_activity_pings', [
            'user_id' => $user->id,
            'work_session_id' => $session->id,
            'action_type' => 'page_view',
        ]);
    }

    #[Test]
    public function the_heartbeat_endpoint_refuses_a_caller_with_no_open_session(): void
    {
        $this->actingAsTelecaller();

        $this->postJson('/api/v1/attendance/ping')
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('user_activity_pings', 0);
    }

    #[Test]
    public function the_heartbeat_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/v1/attendance/ping')->assertStatus(401);
    }
}
