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
     * @return array{user: User, token: string, session: UserWorkSession}
     *
     * @throws ApiException on invalid credentials or a disabled account
     */
    public function login(string $email, string $password, Request $request, string $source = 'web'): array
    {
        $user = $this->verifyCredentials($email, $password, $request);

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
     * @return array{user: User, session: UserWorkSession}
     *
     * @throws ApiException on invalid credentials or a disabled account
     */
    public function loginWeb(string $email, string $password, Request $request, bool $remember = false): array
    {
        $user = $this->verifyCredentials($email, $password, $request);

        return DB::transaction(function () use ($user, $request, $remember) {
            Auth::login($user, $remember);

            // Fixation defence: the pre-login session id must not survive
            // authentication (SEC-AUTH-06).
            $request->session()->regenerate();

            $session = $this->openWorkSession($user, $request, 'web');

            $user->forceFill(['last_login_at' => now()])->save();

            $this->audit($user->id, 'login', $request, ['source' => 'web_session']);

            return ['user' => $user, 'session' => $session];
        });
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
