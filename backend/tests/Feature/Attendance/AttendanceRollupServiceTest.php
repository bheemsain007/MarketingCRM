<?php

namespace Tests\Feature\Attendance;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActivityPing;
use App\Models\UserWorkSession;
use App\Services\Attendance\AttendanceRollupService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Active/idle/break rollup math (FR-ATT-03, GLOSSARY §2.3).
 *
 * Every scenario here hinges on the GAP between two timestamps, so the clock
 * is frozen throughout and every occurred_at is built explicitly off it -
 * never off real elapsed wall-clock time, which would make the test flake
 * depending on how long the assertions themselves take to run.
 */
class AttendanceRollupServiceTest extends TestCase
{
    use RefreshDatabase;

    /** 09:00 IST - matches the other attendance test's anchor, no special significance beyond consistency. */
    private const NOW = '2026-08-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Kolkata'));

        // Five minutes, named explicitly so this test never inherits whatever
        // the deployed default happens to become.
        config(['crm.idle_threshold_minutes' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function telecaller(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        return $user->fresh();
    }

    private function closedSession(User $user, string $startedAgo, string $endedAgo = '0 minutes'): UserWorkSession
    {
        return UserWorkSession::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'user_id' => $user->id,
            'started_at' => now()->sub($startedAgo),
            'ended_at' => now()->sub($endedAgo),
            'end_reason' => 'logout',
            'source' => 'web',
        ]);
    }

    private function ping(UserWorkSession $session, string $ago): UserActivityPing
    {
        return UserActivityPing::create([
            'user_id' => $session->user_id,
            'work_session_id' => $session->id,
            'action_type' => 'call',
            'occurred_at' => now()->sub($ago),
        ]);
    }

    private function rollup(): AttendanceRollupService
    {
        return app(AttendanceRollupService::class);
    }

    #[Test]
    public function pings_two_minutes_apart_stay_active_throughout(): void
    {
        // Ten minutes of a telecaller pinging every two minutes - every gap is
        // well inside the five-minute threshold, so none of it should read as
        // silence.
        $session = $this->closedSession($this->telecaller(), '10 minutes');

        $this->ping($session, '10 minutes');
        $this->ping($session, '8 minutes');
        $this->ping($session, '6 minutes');
        $this->ping($session, '4 minutes');
        $this->ping($session, '2 minutes');

        $session = $this->rollup()->rollup($session);

        // Four gaps of two minutes each between the five pings.
        $this->assertSame(4 * 120, $session->active_seconds);
    }

    #[Test]
    public function a_twenty_minute_gap_against_a_five_minute_threshold_is_mostly_idle(): void
    {
        // One ping, a twenty-minute silence, then another. Only the first five
        // minutes of that gap are credited as active - the rest is exactly the
        // silence the threshold exists to catch.
        $session = $this->closedSession($this->telecaller(), '20 minutes');

        $this->ping($session, '20 minutes');
        $this->ping($session, '0 minutes');

        $session = $this->rollup()->rollup($session);

        $this->assertSame(5 * 60, $session->active_seconds);
        // 20 minutes logged in, 5 credited active, no break -> 15 minutes idle.
        $this->assertSame(15 * 60, $session->idle_seconds);
    }

    #[Test]
    public function an_explicit_break_window_is_excluded_from_idle(): void
    {
        // Ping, then a marked 10-minute break sitting inside a silent stretch,
        // then a ping again. Without the break, all 20 minutes between the two
        // pings would be a candidate for idle; the break must carve its own
        // window out of that idle time rather than adding on top of it.
        $user = $this->telecaller();
        $session = $this->closedSession($user, '30 minutes');

        $this->ping($session, '30 minutes');

        $session->forceFill([
            'break_started_at' => now()->sub('20 minutes'),
        ])->save();

        // The break itself is marked stopped 10 minutes later, exactly like
        // AttendanceBreakService::stop() would record it.
        $session->forceFill([
            'break_seconds' => 10 * 60,
            'break_started_at' => null,
        ])->save();

        $this->ping($session, '0 minutes');

        $session = $this->rollup()->rollup($session);

        // Gap between the two pings is 30 minutes, capped at the 5-minute
        // threshold for the active credit.
        $this->assertSame(5 * 60, $session->active_seconds);
        $this->assertSame(10 * 60, $session->break_seconds);

        // 30 minutes logged in, 5 active, 10 break -> 15 idle. The 10 marked
        // break minutes must not also show up as idle.
        $this->assertSame(15 * 60, $session->idle_seconds);
    }

    #[Test]
    public function a_session_closed_while_still_on_an_open_break_finalises_it_instead_of_dropping_it(): void
    {
        // Forced closed (logout, or the stale sweep) while break_started_at is
        // still set - the break must be closed out to the session's own end,
        // not left dangling or silently discarded.
        $user = $this->telecaller();
        $session = $this->closedSession($user, '15 minutes', endedAgo: '0 minutes');

        $this->ping($session, '15 minutes');

        $session->forceFill(['break_started_at' => now()->sub('10 minutes')])->save();

        $session = $this->rollup()->rollup($session);

        $this->assertNull($session->break_started_at);
        $this->assertSame(10 * 60, $session->break_seconds);
    }

    #[Test]
    public function a_session_with_no_pings_at_all_credits_no_active_time(): void
    {
        // No evidence, no active credit - the same conservatism the stale
        // sweep applies to ended_at itself.
        $session = $this->closedSession($this->telecaller(), '10 minutes');

        $session = $this->rollup()->rollup($session);

        $this->assertSame(0, $session->active_seconds);
        $this->assertSame(10 * 60, $session->idle_seconds);
    }

    #[Test]
    public function rolling_up_twice_reproduces_the_same_numbers(): void
    {
        $session = $this->closedSession($this->telecaller(), '20 minutes');
        $this->ping($session, '20 minutes');
        $this->ping($session, '0 minutes');

        $first = $this->rollup()->rollup($session);
        $second = $this->rollup()->rollup($first);

        $this->assertSame($first->active_seconds, $second->active_seconds);
        $this->assertSame($first->idle_seconds, $second->idle_seconds);
        $this->assertSame($first->break_seconds, $second->break_seconds);
    }
}
