<?php

namespace App\Services\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserWorkSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Authentication, session and audit (SEC-AUTH-*, SEC-AUD-02, FR-ATT-01).
 *
 * Login does three things that must not drift apart:
 *   1. issues a token,
 *   2. opens a work session - the basis for all active/idle reporting,
 *   3. writes an audit entry.
 *
 * Keeping them in one service means a future login path (SSO, mobile) cannot
 * accidentally skip attendance tracking and quietly corrupt telecaller reports.
 */
class AuthService
{
    /**
     * Session key marking a browser session that has passed the password but
     * not yet the second factor (SEC-AUTH-07).
     *
     * Held here rather than in the controller because three places must agree
     * on it: the login that sets it, RequireTwoFactorChallenge that enforces
     * it, and the challenge that clears it. A literal string copied between
     * them is a lockout or an open door, depending on which copy drifts.
     */
    public const TWO_FACTOR_PENDING = 'auth.two_factor_pending';

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * @return array{user: User, token: string, session: UserWorkSession}
     *
     * @throws ApiException on invalid credentials, a disabled account, or a
     *                      missing/invalid second factor
     */
    public function login(string $email, string $password, Request $request, string $source = 'web'): array
    {
        $user = $this->verifyCredentials($email, $password, $request);

        /*
         * Token logins verify the second factor inline rather than through a
         * challenge round trip (SEC-AUTH-07). There is no session to park a
         * half-authenticated state in, and issuing the token first and gating
         * it afterwards would mean a valid bearer token exists before the
         * factor is proved.
         *
         * The code is read off the request instead of being a parameter, so
         * every existing caller keeps working unchanged and an account with 2FA
         * switched off - which is all of them by default - behaves exactly as
         * before. Clients send `two_factor_code` alongside the credentials.
         */
        if ($user->hasTwoFactorEnabled()) {
            $this->twoFactor->challenge(
                $user,
                (string) $request->input('two_factor_code', ''),
                $request,
            );
        }

        return DB::transaction(function () use ($user, $request, $source) {
            $token = $user->createToken(
                $source.'-'.now()->timestamp,
                ['*'],
                // config(), not env(): once `config:cache` runs in production
                // env() no longer reads .env, so a configured lifetime would
                // silently revert to the default (T-10).
                now()->addDays((int) config('crm.auth.token_expiry_days', 30)),
            )->plainTextToken;

            $session = $this->openWorkSession($user, $request, $source);

            $user->forceFill(['last_login_at' => now()])->save();

            $this->audit($user->id, 'login', $request, ['source' => $source]);

            return ['user' => $user, 'token' => $token, 'session' => $session];
        });
    }

    /**
     * Browser login for the Web CRM (ADR-A).
     *
     * Same credential checks, same work session, same audit trail as the token
     * path - only the credential that comes out differs: a server-side session
     * rather than a bearer token. The Web CRM is same-origin, so a session
     * cookie is both simpler and safer than storing a token in the browser
     * where any XSS could read it.
     *
     * When the account carries a confirmed second factor (SEC-AUTH-07) this
     * stops half way: the session is authenticated but flagged pending, and
     * RequireTwoFactorChallenge holds it at the challenge page until
     * `completeTwoFactorLogin()` runs. Nothing that represents "signed in" -
     * the work session, `last_login_at`, the `login` audit entry - is written
     * on that path, because somebody who abandons the challenge did not sign
     * in, and attendance reporting (FR-ATT-01) would otherwise be paid on it.
     *
     * @return array{user: User, session: UserWorkSession|null, two_factor_required: bool}
     *
     * @throws ApiException on invalid credentials or a disabled account
     */
    public function loginWeb(string $email, string $password, Request $request, bool $remember = false): array
    {
        $user = $this->verifyCredentials($email, $password, $request);

        return DB::transaction(function () use ($user, $request, $remember) {
            Auth::login($user, $remember);

            // Fixation defence: the pre-login session id must not survive
            // authentication (SEC-AUTH-06). Done BEFORE the pending flag is
            // written, or regeneration would discard it.
            $request->session()->regenerate();

            if ($user->hasTwoFactorEnabled()) {
                $request->session()->put(self::TWO_FACTOR_PENDING, true);

                $this->audit($user->id, 'login_two_factor_pending', $request, [
                    'source' => 'web_session',
                ]);

                return ['user' => $user, 'session' => null, 'two_factor_required' => true];
            }

            return [
                'user' => $user,
                'session' => $this->completeWebLogin($user, $request),
                'two_factor_required' => false,
            ];
        });
    }

    /**
     * Finishes a browser login once the second factor has been accepted.
     *
     * Split out of `loginWeb` so both paths - no 2FA, and 2FA passed - write
     * exactly the same three things. When this was inline, the challenge path
     * was one forgotten line away from a signed-in user with no work session,
     * which reads downstream as a telecaller who never came to work.
     */
    public function completeTwoFactorLogin(User $user, Request $request): UserWorkSession
    {
        $request->session()->forget(self::TWO_FACTOR_PENDING);

        // A new session id again: the id issued before the challenge was
        // handed out to a browser that had not yet proved the second factor.
        $request->session()->regenerate();

        return DB::transaction(fn () => $this->completeWebLogin($user, $request));
    }

    private function completeWebLogin(User $user, Request $request): UserWorkSession
    {
        $session = $this->openWorkSession($user, $request, 'web');

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit($user->id, 'login', $request, ['source' => 'web_session']);

        return $session;
    }

    /**
     * Ends a browser session.
     *
     * The work session is closed and audited here; invalidating the HTTP
     * session itself stays in the controller, since it is a request concern.
     */
    public function logoutWeb(User $user, Request $request): void
    {
        DB::transaction(function () use ($user, $request) {
            $this->closeWorkSession($user, 'logout');

            $this->audit($user->id, 'logout', $request, ['source' => 'web_session']);
        });
    }

    /**
     * Shared credential gate for every login path.
     *
     * Extracted so a new entry point - the browser today, SSO later - cannot
     * accidentally ship without the disabled-account check or the failure
     * audit that detects password spraying.
     *
     * @throws ApiException
     */
    private function verifyCredentials(string $email, string $password, Request $request): User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->audit($user?->id, 'login_failed', $request, [
                'email' => $email,   // stored to detect spraying; no password ever
            ]);

            // Deliberately identical whether the account exists or not - a
            // different message here is a user-enumeration oracle.
            throw new ApiException(
                ErrorCode::Unauthenticated,
                'The provided credentials are incorrect.',
            );
        }

        if (! $user->is_active) {
            $this->audit($user->id, 'login_blocked', $request, ['reason' => 'inactive']);

            throw new ApiException(
                ErrorCode::Forbidden,
                'This account is disabled. Contact your administrator.',
            );
        }

        return $user;
    }

    /**
     * Revokes only the presenting token, not every session (SEC-AUTH-04) -
     * logging out on a phone must not sign the user out at their desk.
     */
    public function logout(User $user, Request $request): void
    {
        DB::transaction(function () use ($user, $request) {
            $user->currentAccessToken()?->delete();

            $this->closeWorkSession($user, 'logout');

            $this->audit($user->id, 'logout', $request);
        });
    }

    public function logoutEverywhere(User $user, Request $request): void
    {
        DB::transaction(function () use ($user, $request) {
            $user->tokens()->delete();

            $this->closeWorkSession($user, 'forced');

            $this->audit($user->id, 'logout_all', $request);
        });
    }

    public function changePassword(User $user, string $current, string $new, Request $request): void
    {
        if (! Hash::check($current, $user->password)) {
            $this->audit($user->id, 'password_change_failed', $request);

            throw new ApiException(
                ErrorCode::ValidationFailed,
                'The current password is incorrect.',
                errors: [[
                    'field' => 'current_password',
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => 'The current password is incorrect.',
                ]],
            );
        }

        DB::transaction(function () use ($user, $new, $request) {
            $user->forceFill(['password' => Hash::make($new)])->save();

            // Every other session is invalidated: a password change usually
            // means the old one may be compromised.
            $current = $user->currentAccessToken();
            $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current->id))->delete();

            $this->audit($user->id, 'password_changed', $request);
        });
    }

    /**
     * Reuses an already-open session rather than stacking a new one - otherwise
     * a user with the web app and the Android app open would double-count their
     * logged-in time.
     */
    private function openWorkSession(User $user, Request $request, string $source): UserWorkSession
    {
        $open = $user->workSessions()->whereNull('ended_at')->where('source', $source)->first();

        if ($open) {
            return $open;
        }

        return $user->workSessions()->create([
            'started_at' => now(),
            'source' => $source,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'device_id' => $request->header('X-Device-Id'),
        ]);
    }

    private function closeWorkSession(User $user, string $reason): void
    {
        $user->workSessions()
            ->whereNull('ended_at')
            ->get()
            ->each(function (UserWorkSession $session) use ($reason) {
                $session->update([
                    'ended_at' => now(),
                    'end_reason' => $reason,
                ]);
            });
    }

    /** @param array<string, mixed> $context */
    /**
     * Emails a password reset link (SEC-AUTH-06).
     *
     * Until this existed, a user who forgot their password was locked out
     * permanently: there is no admin path to set somebody's password either,
     * by design, because an administrator who can set a password can sign in
     * as that person.
     *
     * **Nothing about the outcome is returned, deliberately.** The caller shows
     * the same message whether the address matched an account, matched a
     * disabled one, or matched nothing at all - the same refusal to answer
     * "does this account exist" that login already makes (SEC-AUTH-02). A reset
     * form that says "no such user" is a user-enumeration oracle that needs no
     * password to operate.
     *
     * Disabled accounts are excluded by passing `is_active` as a credential, so
     * the provider never finds them: a suspended user must not be able to let
     * themselves back in.
     */
    public function sendPasswordResetLink(string $email, Request $request): void
    {
        try {
            $status = Password::sendResetLink(['email' => $email, 'is_active' => true]);
        } catch (Throwable $e) {
            /*
             * A mail transport that throws must not become an oracle.
             *
             * Only an address that MATCHED an active account ever reaches the
             * send, so letting the exception escape produced a 500 for real
             * users and a clean 302 for everyone else - which tells an attacker
             * exactly what the identical flash message was written to hide.
             * The failure is logged for whoever operates the mailer instead.
             */
            Log::error('Password reset mail failed', ['exception' => $e->getMessage()]);

            $this->audit(null, 'password_reset_mail_failed', $request);

            return;
        }

        // Audited either way. A burst of requests for addresses that do not
        // exist is exactly what enumeration looks like, and it should be
        // visible to whoever reads the log (SEC-AUD-02).
        $this->audit(
            User::where('email', $email)->value('id'),
            $status === Password::RESET_LINK_SENT ? 'password_reset_requested' : 'password_reset_request_ignored',
            $request,
            ['email' => $email],
        );
    }

    /**
     * Completes a reset (SEC-AUTH-06).
     *
     * Single-use and short-lived are both structural rather than checked here:
     * the broker deletes the token row as it consumes it, and refuses one older
     * than `config('auth.passwords.users.expire')`. Neither property depends on
     * this method remembering to enforce it.
     *
     * Everything else the account was signed in with is invalidated. A reset is
     * usually a response to a password believed compromised, so leaving the
     * attacker's existing session or mobile token alive would defeat the point
     * - the same reasoning as `changePassword`, applied harder because here we
     * cannot know the requester was ever legitimate.
     *
     * @param  array<string, string>  $credentials  email, password, password_confirmation, token
     *
     * @throws ApiException when the token is invalid, expired or already used
     */
    public function resetPassword(array $credentials, Request $request): void
    {
        /*
         * `is_active` is re-asserted here, not only when the link was sent.
         *
         * The broker looks the user up again by these credentials, so without
         * it a token issued while an account was live stays redeemable after
         * the account is suspended - and suspending somebody is usually the
         * response to needing them out NOW. The provider strips only keys
         * containing "password", so this becomes a real WHERE clause.
         */
        $credentials['is_active'] = true;

        $status = Password::reset($credentials, function (User $user, string $password) use ($request) {
            DB::transaction(function () use ($user, $password, $request) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // Invalidates every "remember me" cookie already issued.
                    'remember_token' => Str::random(60),
                ])->save();

                // Every mobile token too - a reset must not leave a device
                // signed in that the real owner cannot see or revoke.
                $user->tokens()->delete();

                /*
                 * And every browser session. Revoking tokens and rotating the
                 * remember_token is not enough on its own: the session driver
                 * is `database`, and a live session cookie authenticates from
                 * the `sessions` row alone without ever re-reading the password
                 * hash. Leaving those rows behind means the person the reset
                 * was meant to evict simply stays signed in (SEC-AUTH-04).
                 */
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))
                        ->where('user_id', $user->id)
                        ->delete();
                }

                $this->audit($user->id, 'password_reset_completed', $request);
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            $this->audit(null, 'password_reset_failed', $request, ['status' => $status]);

            throw new ApiException(
                ErrorCode::ValidationFailed,
                'This reset link is invalid or has expired. Please request a new one.',
                errors: [[
                    'field' => 'email',
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => 'This reset link is invalid or has expired. Please request a new one.',
                ]],
            );
        }
    }

    private function audit(?int $userId, string $action, Request $request, array $context = []): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'new_values' => $context ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
