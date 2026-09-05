<?php

namespace Tests\Feature\Attendance;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Models\UserWorkSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Explicit break start/stop (FR-ATT-04).
 */
class AttendanceBreakTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsTelecaller(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());
        $user = $user->fresh();

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function openSession(User $user, array $attributes = []): UserWorkSession
    {
        return UserWorkSession::create(array_merge([
            'tenant_id' => config('crm.default_tenant_id'),
            'user_id' => $user->id,
            'started_at' => now()->subHour(),
            'source' => 'web',
        ], $attributes));
    }

    // -----------------------------------------------------------------------
    // Starting a break
    // -----------------------------------------------------------------------

    #[Test]
    public function a_break_can_be_started_on_an_open_session(): void
    {
        $user = $this->actingAsTelecaller();
        $session = $this->openSession($user);

        $this->postJson('/api/v1/attendance/breaks/start')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.work_session_id', $session->id);

        $this->assertTrue(now()->equalTo($session->fresh()->break_started_at));
    }

    #[Test]
    public function starting_a_break_twice_is_rejected(): void
    {
        $user = $this->actingAsTelecaller();
        $this->openSession($user, ['break_started_at' => now()->subMinutes(5)]);

        $this->postJson('/api/v1/attendance/breaks/start')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A break is already in progress.');
    }

    #[Test]
    public function starting_a_break_with_no_open_session_is_rejected(): void
    {
        $this->actingAsTelecaller();

        $this->postJson('/api/v1/attendance/breaks/start')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // -----------------------------------------------------------------------
    // Stopping a break
    // -----------------------------------------------------------------------

    #[Test]
    public function stopping_a_break_adds_the_elapsed_time_to_break_seconds(): void
    {
        $user = $this->actingAsTelecaller();
        $session = $this->openSession($user, [
            'break_started_at' => now()->subMinutes(12),
            'break_seconds' => 300, // an earlier break already banked five minutes
        ]);

        $this->postJson('/api/v1/attendance/breaks/stop')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.break_seconds', 300 + 12 * 60);

        $session->refresh();
        $this->assertNull($session->break_started_at);
        $this->assertSame(300 + 12 * 60, $session->break_seconds);
    }

    #[Test]
    public function stopping_a_break_that_was_never_started_is_rejected(): void
    {
        $user = $this->actingAsTelecaller();
        $this->openSession($user);

        $this->postJson('/api/v1/attendance/breaks/stop')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not currently on a break.');
    }

    #[Test]
    public function stopping_a_break_with_no_open_session_is_rejected(): void
    {
        $this->actingAsTelecaller();

        $this->postJson('/api/v1/attendance/breaks/stop')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function break_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/attendance/breaks/start')->assertStatus(401);
        $this->postJson('/api/v1/attendance/breaks/stop')->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Status - what a page reload needs to know which control to draw
    // -----------------------------------------------------------------------

    #[Test]
    public function status_reports_no_open_session_when_there_is_none(): void
    {
        $this->actingAsTelecaller();

        $this->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.has_open_session', false)
            ->assertJsonPath('data.is_on_break', false);
    }

    #[Test]
    public function status_reports_an_open_session_that_is_not_on_break(): void
    {
        $user = $this->actingAsTelecaller();
        $this->openSession($user);

        $this->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.has_open_session', true)
            ->assertJsonPath('data.is_on_break', false)
            ->assertJsonPath('data.break_started_at', null);
    }

    #[Test]
    public function status_reports_being_on_break_with_when_it_started(): void
    {
        $user = $this->actingAsTelecaller();
        $session = $this->openSession($user, ['break_started_at' => now()->subMinutes(5)]);

        $this->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.has_open_session', true)
            ->assertJsonPath('data.is_on_break', true)
            ->assertJsonPath('data.break_started_at', $session->break_started_at->toIso8601String());
    }

    #[Test]
    public function status_does_not_report_someone_elses_session(): void
    {
        $other = User::factory()->create();
        $other->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());
        $this->openSession($other->fresh());

        $this->actingAsTelecaller();

        $this->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.has_open_session', false);
    }

    #[Test]
    public function status_requires_authentication(): void
    {
        $this->getJson('/api/v1/attendance/status')->assertStatus(401);
    }
}
