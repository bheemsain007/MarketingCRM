<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Password recovery (SEC-AUTH-06, SEC-AUTH-02/03, SEC-AUD-02).
 *
 * Before this existed a user who forgot their password was locked out
 * permanently - there is deliberately no administrator screen that sets
 * somebody else's password, because whoever can do that can sign in as them.
 *
 * The properties worth proving are not "the form works". They are that the form
 * cannot be used to discover who has an account, that a token dies the moment
 * it is used and again when it ages out, and that a reset actually evicts
 * whoever may have been using the old password.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => 'priya@example.com',
            'password' => bcrypt('correct-horse-1'),
        ], $attributes));

        $user->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        return $user->fresh();
    }

    /** Requests a link and returns the token that was mailed. */
    private function requestLinkFor(User $user): string
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        return (string) $token;
    }

    // -----------------------------------------------------------------------
    // The form must not answer "does this account exist"
    // -----------------------------------------------------------------------

    #[Test]
    public function the_request_form_says_the_same_thing_whether_the_account_exists_or_not(): void
    {
        Notification::fake();
        $user = $this->user();

        $real = $this->post('/forgot-password', ['email' => $user->email]);
        $fake = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        // Identical status and identical message. A different response for a
        // known address turns this page into a staff directory that needs no
        // password to read (SEC-AUTH-02).
        $this->assertSame($real->getStatusCode(), $fake->getStatusCode());
        $this->assertSame(
            $real->getSession()->get('status'),
            $fake->getSession()->get('status'),
        );

        // ...but only the real one is actually emailed.
        Notification::assertSentTo($user, QueuedResetPassword::class);
        Notification::assertCount(1);
    }

    #[Test]
    public function a_disabled_account_is_not_sent_a_link(): void
    {
        Notification::fake();
        $suspended = $this->user(['is_active' => false]);

        $response = $this->post('/forgot-password', ['email' => $suspended->email]);

        // A suspended user must not be able to let themselves back in - and the
        // page still must not admit that is why (SEC-AUTH-02).
        $response->assertSessionHas('status');
        Notification::assertNothingSent();
    }

    #[Test]
    public function every_request_is_audited_including_the_ones_that_go_nowhere(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->post('/forgot-password', ['email' => $user->email]);
        $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        // A burst of requests for addresses that do not exist is what
        // enumeration looks like, and it should be visible to whoever reads the
        // log (SEC-AUD-02).
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'password_reset_requested',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_request_ignored']);
    }

    // -----------------------------------------------------------------------
    // Resetting
    // -----------------------------------------------------------------------

    #[Test]
    public function a_valid_token_sets_the_new_password(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-pass-9',
            'password_confirmation' => 'brand-new-pass-9',
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check('brand-new-pass-9', $user->fresh()->password));

        // And the new password actually signs in.
        $this->post('/login', ['email' => $user->email, 'password' => 'brand-new-pass-9'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user->fresh());
    }

    #[Test]
    public function the_reset_form_renders_for_a_token(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        $this->get("/reset-password/{$token}?email=".urlencode($user->email))
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee($user->email);
    }

    // -----------------------------------------------------------------------
    // Single-use and short-lived (SEC-AUTH-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_token_cannot_be_used_twice(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'first-new-pass-1',
            'password_confirmation' => 'first-new-pass-1',
        ];

        $this->post('/reset-password', $payload)->assertRedirect('/login');

        // Replayed - a link sitting in a mailbox somebody else later reads must
        // be spent, not merely used.
        $this->post('/reset-password', array_merge($payload, [
            'password' => 'second-new-pass-2',
            'password_confirmation' => 'second-new-pass-2',
        ]))->assertSessionHasErrors('email');

        // The first reset stands; the replay changed nothing.
        $this->assertTrue(Hash::check('first-new-pass-1', $user->fresh()->password));
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        // Past the configured window (SEC-AUTH-06). Travelling forward is the
        // honest test: it exercises the broker's own expiry, not a stub.
        Carbon::setTestNow(now()->addMinutes((int) config('auth.passwords.users.expire') + 1));

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'too-late-pass-3',
            'password_confirmation' => 'too-late-pass-3',
        ])->assertSessionHasErrors('email');

        Carbon::setTestNow();
        $this->assertTrue(Hash::check('correct-horse-1', $user->fresh()->password));
    }

    #[Test]
    public function the_token_window_is_short(): void
    {
        // A guard on the config itself: SEC-AUTH-06 says short-lived, and
        // Laravel ships 60 minutes. If somebody raises this, the requirement
        // should be re-read rather than the number quietly drifting.
        $this->assertLessThanOrEqual(30, (int) config('auth.passwords.users.expire'));
    }

    #[Test]
    public function a_forged_token_is_refused_and_reads_the_same_as_an_expired_one(): void
    {
        $user = $this->user();

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'attacker-pass-4',
            'password_confirmation' => 'attacker-pass-4',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('correct-horse-1', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_reset_failed']);
    }

    // -----------------------------------------------------------------------
    // A reset evicts whoever held the old password
    // -----------------------------------------------------------------------

    #[Test]
    public function resetting_revokes_every_existing_mobile_token(): void
    {
        $user = $this->user();
        $user->createToken('their-phone');
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = $this->requestLinkFor($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'evicting-pass-5',
            'password_confirmation' => 'evicting-pass-5',
        ])->assertRedirect('/login');

        // A reset usually answers a password believed compromised. Leaving a
        // device signed in that the real owner cannot see would defeat it.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function resetting_invalidates_remember_me_cookies(): void
    {
        $user = $this->user();
        $user->forceFill(['remember_token' => 'old-remember-value'])->save();

        $token = $this->requestLinkFor($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'evicting-pass-6',
            'password_confirmation' => 'evicting-pass-6',
        ])->assertRedirect('/login');

        $this->assertNotSame('old-remember-value', $user->fresh()->remember_token);
    }

    #[Test]
    public function the_reset_is_audited(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'audited-pass-7',
            'password_confirmation' => 'audited-pass-7',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'password_reset_completed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Password quality and reachability
    // -----------------------------------------------------------------------

    #[Test]
    public function a_weak_password_is_refused(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        // The same strength rule user creation and the account page apply - the
        // recovery route must not be the way to set a weaker password.
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('correct-horse-1', $user->fresh()->password));
    }

    #[Test]
    public function a_mismatched_confirmation_is_refused(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'typed-one-way-8',
            'password_confirmation' => 'typed-another-way-8',
        ])->assertSessionHasErrors('password');
    }

    #[Test]
    public function the_login_page_offers_the_recovery_route(): void
    {
        // It is the only way back in, so it has to be findable from the one
        // page a locked-out user can reach.
        $this->get('/login')
            ->assertOk()
            ->assertSee('Forgotten your password?')
            ->assertSee('/forgot-password', false);
    }

    #[Test]
    public function a_signed_in_user_is_sent_away_from_the_recovery_pages(): void
    {
        $this->actingAs($this->user());

        // Guest-only, like the login page: somebody already signed in changes
        // their password from the account screen, which asks for the current
        // one (SEC-AUTH-04).
        $this->get('/forgot-password')->assertRedirect('/dashboard');
    }

    #[Test]
    public function the_reset_link_points_at_this_applications_route(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            // Laravel builds this from a route named `password.reset`; every
            // route here is named `web.*`, so without the explicit wiring in
            // AppServiceProvider the mail would break or point somewhere wrong.
            return str_contains($mail->actionUrl, '/reset-password/');
        });
    }

    // -----------------------------------------------------------------------
    // Throttling (SEC-AUTH-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_request_form_is_rate_limited(): void
    {
        Notification::fake();
        $user = $this->user();

        // Six attempts against a 5/min limiter. Without it, this endpoint is a
        // free mail cannon pointed at any address someone cares to name.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $user->email]);
        }

        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);
    }

    // -----------------------------------------------------------------------
    // Findings from the adversarial review, each with its own guard
    // -----------------------------------------------------------------------

    #[Test]
    public function a_token_issued_before_suspension_cannot_be_redeemed_after_it(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        // Suspending somebody is usually the response to needing them out now.
        $user->forceFill(['is_active' => false])->save();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'suspended-pass-1',
            'password_confirmation' => 'suspended-pass-1',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('correct-horse-1', $user->fresh()->password));
    }

    #[Test]
    public function resetting_kills_existing_browser_sessions(): void
    {
        // The suite runs on the array driver for speed; deployment runs on
        // `database` (.env.example). Pointed at the real driver here, because
        // the behaviour under test only exists there.
        config(['session.driver' => 'database']);

        $user = $this->user();

        // A live session row is enough to authenticate on its own - it never
        // re-reads the password hash. Left behind, whoever the reset was meant
        // to evict simply stays signed in (SEC-AUTH-04).
        DB::table('sessions')->insert([
            'id' => 'attacker-session-id',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'stolen',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $token = $this->requestLinkFor($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'evicting-pass-10',
            'password_confirmation' => 'evicting-pass-10',
        ])->assertRedirect('/login');

        $this->assertDatabaseMissing('sessions', ['id' => 'attacker-session-id']);
    }

    #[Test]
    public function a_mail_failure_does_not_become_an_account_existence_oracle(): void
    {
        $user = $this->user();

        // Only an address matching an active account ever reaches the send, so
        // an escaping exception meant 500 for real users and 302 for everyone
        // else - which tells an attacker exactly what the identical message
        // was written to hide.
        Notification::fake();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));

        $real = $this->post('/forgot-password', ['email' => $user->email]);
        $fake = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($real->getStatusCode(), $fake->getStatusCode());
        $this->assertSame(302, $real->getStatusCode());
    }

    #[Test]
    public function the_reset_email_is_queued_rather_than_sent_in_the_request(): void
    {
        Queue::fake();
        $user = $this->user();

        $this->post('/forgot-password', ['email' => $user->email]);

        // Sending inline made the "found an account" branch measurably slower
        // than the "no such user" branch - a timing oracle. It also let a slow
        // SMTP host hold the web request open.
        Queue::assertPushed(SendQueuedNotifications::class);
    }

    #[Test]
    public function the_broker_is_timeboxed_above_the_cost_of_one_bcrypt(): void
    {
        // Laravel's 200ms default sits BELOW one bcrypt at this application's
        // 12 rounds, and only the branch that found a user pays that cost - so
        // the default left the two branches distinguishable with a stopwatch.
        $this->assertGreaterThanOrEqual(500000, (int) config('auth.timebox_duration'));
    }

    #[Test]
    public function jamming_the_login_limiter_does_not_also_block_recovery(): void
    {
        $user = $this->user();

        // Per-account throttling is what SEC-AUTH-03 asks for, and its cost is
        // that anyone knowing an address can fill that account's bucket. Shared
        // with login, that became a TOTAL lockout - no sign-in AND no recovery.
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        Notification::fake();
        $this->post('/forgot-password', ['email' => $user->email])->assertStatus(302);
    }

    #[Test]
    public function an_over_long_email_cannot_be_written_into_the_audit_log(): void
    {
        // Unauthenticated, so without a length bound this endpoint is a way to
        // push an arbitrarily long attacker-controlled string into audit_logs.
        $this->post('/forgot-password', ['email' => str_repeat('a', 300).'@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[Test]
    public function the_broker_deletes_the_token_row_when_it_is_consumed(): void
    {
        $user = $this->user();
        $token = $this->requestLinkFor($user);

        $this->assertDatabaseCount('password_reset_tokens', 1);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'consumed-pass-9',
            'password_confirmation' => 'consumed-pass-9',
        ])->assertRedirect('/login');

        // Single-use is structural rather than remembered: the row is gone, so
        // no code path can accept the token a second time (SEC-AUTH-06).
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(Password::INVALID_TOKEN, Password::tokenExists($user, $token) ? 'exists' : Password::INVALID_TOKEN);
    }
}
