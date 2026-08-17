<?php

namespace Tests\Feature\Attendance;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActivityPing;
use App\Models\UserWorkSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Closing abandoned work sessions (FR-ATT-01, Phase 26, T-24).
 *
 * Sessions open on login and close on logout, which covers the telecaller who
 * clicks "log out". It covers nobody who closes the browser, loses power, or is
 * killed by the OS - their row stays open for ever, and `loggedInSeconds()`
 * counts to `now()` for an open session, so their logged-in time grows without
 * bound. That number feeds telecaller reports, and the attribution note in
 * config/crm.php is explicit that these figures decide what people are paid.
 * An unbounded one is not a cosmetic bug.
 *
 * The property these tests are really protecting is not "the row gets closed" -
 * it is WHERE it gets closed. Stamping `ended_at` with the moment the sweep ran
 * would replace an unbounded lie with a smaller one, and would silently pay for
 * the hours between someone walking out and the cron noticing. The last
 * activity ping is the last moment we have evidence the person was there, so
 * that is the honest end.
 *
 * Time is frozen throughout: a sweep test whose result depends on the wall
 * clock is a test that passes in the morning and fails after lunch.
 */
class StaleWorkSessionSweepTest extends TestCase
{
    use RefreshDatabase;

    /** 09:00 IST - inside a working day, so nothing here depends on the hour. */
    private const NOW = '2026-08-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Kolkata'));

        // Four hours of silence. Named explicitly so a test never inherits
        // whatever the deployed default happens to become.
        config(['crm.attendance.stale_after_minutes' => 240]);
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

    private function openSession(User $user, string $startedAgo, array $attributes = []): UserWorkSession
    {
        return UserWorkSession::create(array_merge([
            'tenant_id' => config('crm.default_tenant_id'),
            'user_id' => $user->id,
            'started_at' => now()->sub($startedAgo),
            'source' => 'web',
        ], $attributes));
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

    private function sweep(bool $dryRun = false): void
    {
        $this->artisan('crm:close-stale-work-sessions'.($dryRun ? ' --dry-run' : ''))
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------------
    // The sweep exists at all
    // -----------------------------------------------------------------------

    #[Test]
    public function a_session_abandoned_past_the_threshold_is_closed(): void
    {
        // Logged in yesterday evening, last did anything yesterday evening, and
        // it is now nine in the morning. Nobody is at that desk.
        $session = $this->openSession($this->telecaller(), '16 hours');
        $this->ping($session, '15 hours');

        $this->sweep();

        $this->assertNotNull($session->fresh()->ended_at);
    }

    #[Test]
    public function it_is_closed_with_the_timeout_reason_rather_than_looking_like_a_logout(): void
    {
        $session = $this->openSession($this->telecaller(), '16 hours');
        $this->ping($session, '15 hours');

        $this->sweep();

        /*
         * The reason is what lets a supervisor - and any later audit of the
         * hours - tell "worked a shift and signed off" apart from "vanished and
         * the system guessed". Recording both as `logout` would make the sweep
         * indistinguishable from the person, which is exactly the kind of
         * invented evidence this row should never contain.
         */
        $this->assertSame('timeout', $session->fresh()->end_reason);
    }

    // -----------------------------------------------------------------------
    // Where the session ends - the part that decides what is paid
    // -----------------------------------------------------------------------

    #[Test]
    public function it_ends_the_session_at_the_last_sign_of_life_not_at_the_moment_the_sweep_ran(): void
    {
        $session = $this->openSession($this->telecaller(), '16 hours');
        $lastPing = $this->ping($session, '15 hours');

        // An older ping is present too, so this proves the sweep takes the
        // LATEST evidence rather than whichever row it happened to read first.
        $this->ping($session, '15 hours 40 minutes');

        $this->sweep();

        $ended = $session->fresh()->ended_at;

        $this->assertTrue(
            $lastPing->occurred_at->equalTo($ended),
            'Expected the session to end at the last activity ping, got '.$ended,
        );

        // The alternative - now() - would have credited fifteen hours nobody
        // worked, and done it every night for every telecaller.
        $this->assertTrue($ended->lessThan(now()));
    }

    #[Test]
    public function a_session_with_no_activity_at_all_ends_where_it_started(): void
    {
        // Signed in, then nothing: a login on a machine that was closed
        // immediately. There is no evidence of a single minute of work, so
        // claiming any would be inventing it.
        $session = $this->openSession($this->telecaller(), '16 hours');

        $this->sweep();

        $session->refresh();

        $this->assertTrue($session->started_at->equalTo($session->ended_at));
        $this->assertSame(0, $session->loggedInSeconds());
    }

    #[Test]
    public function logged_in_time_stops_growing_once_the_sweep_has_run(): void
    {
        $session = $this->openSession($this->telecaller(), '16 hours');
        $this->ping($session, '15 hours');

        // Before: the open row counts to now(), so the number is a function of
        // how long ago the person disappeared - it never stops rising.
        $this->assertSame(16 * 3600, $session->loggedInSeconds());

        $this->sweep();

        // After: one hour, which is what the pings actually evidence. Time
        // passing no longer changes it.
        $session->refresh();
        $this->assertSame(3600, $session->loggedInSeconds());

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(3600, $session->fresh()->loggedInSeconds());
    }

    // -----------------------------------------------------------------------
    // What the sweep must NOT touch
    // -----------------------------------------------------------------------

    #[Test]
    public function a_session_still_being_used_is_left_open(): void
    {
        $session = $this->openSession($this->telecaller(), '2 hours');
        $this->ping($session, '3 minutes');

        $this->sweep();

        // Closing a live session mid-shift would log the telecaller's own
        // attendance out from under them and understate the shift they are
        // still working.
        $this->assertNull($session->fresh()->ended_at);
    }

    #[Test]
    public function a_long_shift_is_judged_on_its_last_activity_and_not_its_length(): void
    {
        // Started eleven hours ago and was active a minute ago. Somebody on a
        // double shift is present, not abandoned - staleness is silence, not
        // duration, and confusing the two would close sessions out from under
        // exactly the people working hardest.
        $session = $this->openSession($this->telecaller(), '11 hours');
        $this->ping($session, '1 minute');

        $this->sweep();

        $this->assertNull($session->fresh()->ended_at);
    }

    #[Test]
    public function a_session_that_is_only_just_short_of_the_threshold_is_left_open(): void
    {
        // 239 minutes of silence against a 240-minute threshold. The boundary
        // is worth pinning: an off-by-one here closes sessions a whole sweep
        // interval early, every time.
        $session = $this->openSession($this->telecaller(), '5 hours');
        $this->ping($session, '239 minutes');

        $this->sweep();

        $this->assertNull($session->fresh()->ended_at);
    }

    #[Test]
    public function an_already_closed_session_is_not_reopened_or_restamped(): void
    {
        $user = $this->telecaller();
        $closedAt = now()->subHours(14);

        $session = $this->openSession($user, '16 hours', [
            'ended_at' => $closedAt,
            'end_reason' => 'logout',
        ]);

        $this->sweep();

        $session->refresh();

        // A real logout is the best evidence there is. Overwriting it with a
        // guess would be a regression, not a fix.
        $this->assertTrue($closedAt->equalTo($session->ended_at));
        $this->assertSame('logout', $session->end_reason);
    }

    // -----------------------------------------------------------------------
    // Operability
    // -----------------------------------------------------------------------

    #[Test]
    public function the_dry_run_reports_what_it_would_close_without_writing(): void
    {
        $session = $this->openSession($this->telecaller(), '16 hours');
        $this->ping($session, '15 hours');

        $this->artisan('crm:close-stale-work-sessions --dry-run')
            ->expectsOutputToContain('[dry run] 1 work session(s) would be closed')
            ->assertSuccessful();

        // The point of a dry run on a job that rewrites attendance is being
        // able to see what it is about to do to the payroll numbers first.
        $this->assertNull($session->fresh()->ended_at);
    }

    #[Test]
    public function the_threshold_is_configuration_and_not_a_constant(): void
    {
        // Shift patterns differ; a call centre running nights needs a different
        // number from one that does not, and neither should need a deployment.
        config(['crm.attendance.stale_after_minutes' => 60 * 24]);

        $session = $this->openSession($this->telecaller(), '16 hours');
        $this->ping($session, '15 hours');

        $this->sweep();

        $this->assertNull($session->fresh()->ended_at);
    }

    #[Test]
    public function each_users_session_is_judged_on_its_own_activity(): void
    {
        $abandoned = $this->openSession($this->telecaller(), '16 hours');
        $this->ping($abandoned, '15 hours');

        $working = $this->openSession($this->telecaller(), '16 hours');
        $this->ping($working, '2 minutes');

        $this->sweep();

        // Pings are joined per session, so one busy telecaller must not keep a
        // colleague's abandoned row alive, nor an abandoned row close a busy
        // colleague's.
        $this->assertNotNull($abandoned->fresh()->ended_at);
        $this->assertNull($working->fresh()->ended_at);
    }
}
