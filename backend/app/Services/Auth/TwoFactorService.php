<?php

namespace App\Services\Auth;

use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Two-factor authentication for Admin and Super Admin (SEC-AUTH-07, T-09).
 *
 * **Optional and off by default.** SEC-AUTH-07 says "recommended", not
 * "mandatory", and the difference matters operationally: this system has one
 * account-recovery path (SEC-AUTH-06, a mailed reset link) and no administrator
 * screen that sets somebody else's credentials. Forcing enrolment on deploy day
 * would mean every existing admin arrives at a challenge page for a device they
 * have not set up, with the only escape being a Super Admin who is in the same
 * position. So enrolment is a deliberate act, and `two_factor_confirmed_at` is
 * only set once the user has proved a code works.
 *
 * **Why only these two roles.** They are the accounts that can read every lead,
 * export in bulk, and change provider credentials - the ones worth phishing. A
 * telecaller's password protects their own queue. Offering 2FA to everyone
 * would be a larger support surface for a smaller gain, so `mayEnrol()` is
 * where that judgement lives and it is a single method to widen.
 *
 * **What is stored, and how.**
 *   - the TOTP secret: encrypted with APP_KEY, because it must be readable to
 *     verify a code. Encryption protects a stolen backup, not a compromised app
 *     server - that is the honest limit of it.
 *   - recovery codes: HASHED, never encrypted-and-readable. They are bearer
 *     credentials exactly like a password, and nothing in this system ever
 *     needs to display them again after enrolment. A recovery code that can be
 *     read back out of the database is a password reset that skips the mailbox.
 *   - the last accepted time step: so a code cannot be replayed inside its own
 *     window. See Totp::match().
 */
class TwoFactorService
{
    /** How many recovery codes an enrolment issues. */
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Roles for which 2FA is offered (SEC-AUTH-07).
     *
     * @return array<int, RoleName>
     */
    public static function eligibleRoles(): array
    {
        return [RoleName::SuperAdmin, RoleName::Admin];
    }

    public function mayEnrol(User $user): bool
    {
        foreach (self::eligibleRoles() as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Starts enrolment: issues a secret and the URI an authenticator scans.
     *
     * Nothing is confirmed here, so the user is NOT yet challenged at login -
     * closing the browser tab mid-enrolment leaves the account exactly as it
     * was. Calling this again replaces an unconfirmed secret, which is what
     * "the QR code did not scan, let me try again" needs to do.
     *
     * An already-confirmed enrolment is refused rather than silently reset:
     * overwriting a working secret because a request arrived twice is how
     * people lose access to their own account.
     *
     * @return array{secret: string, otpauth_uri: string}
     *
     * @throws ApiException
     */
    public function beginEnrolment(User $user, Request $request): array
    {
        $this->assertEligible($user);

        if ($user->hasTwoFactorEnabled()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Two-factor authentication is already enabled. Disable it first to enrol a new device.',
            );
        }

        $secret = Totp::generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_timestep' => null,
        ])->save();

        $this->audit($user->id, 'two_factor_enrolment_started', $request);

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri(
                $secret,
                $user->email,
                (string) config('app.name', 'CRM'),
            ),
        ];
    }

    /**
     * Completes enrolment against a code the user's app produced.
     *
     * The code is proof the device is set up and its clock agrees with ours.
     * Skipping this step - trusting that a displayed QR was scanned - is how a
     * mistyped secret becomes a permanent lockout discovered at the next login.
     *
     * Returns the plaintext recovery codes. This is the ONLY time they exist in
     * readable form; only their hashes are stored.
     *
     * @return array<int, string>
     *
     * @throws ApiException
     */
    public function confirmEnrolment(User $user, string $code, Request $request): array
    {
        $this->assertEligible($user);

        if ($user->two_factor_secret === null) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Start enrolment before confirming a code.',
            );
        }

        $step = Totp::match($user->two_factor_secret, $code);

        if ($step === null) {
            $this->audit($user->id, 'two_factor_enrolment_failed', $request);

            throw $this->invalidCode();
        }

        $plain = $this->freshRecoveryCodes();

        DB::transaction(function () use ($user, $step, $plain) {
            $user->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_timestep' => $step,
                'two_factor_recovery_codes' => $this->hashAll($plain),
            ])->save();
        });

        $this->audit($user->id, 'two_factor_enabled', $request);

        return $plain;
    }

    /**
     * Verifies a challenge, accepting either a TOTP code or a recovery code.
     *
     * One entry point for both, because the login form has one field and the
     * user should not have to tell us which kind of secret they are holding.
     *
     * Every acceptance is a write - the time step, or the consumed recovery
     * code - so this is never safe to call speculatively on a read path.
     *
     * @throws ApiException when neither form of credential matches
     */
    public function challenge(User $user, string $code, Request $request): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            // Nothing to verify. Refusing rather than passing: reaching here
            // means a caller thinks this account is protected and it is not.
            throw new ApiException(
                ErrorCode::Conflict,
                'Two-factor authentication is not enabled for this account.',
            );
        }

        if ($this->consumeTotp($user, $code)) {
            $this->audit($user->id, 'two_factor_verified', $request);

            return;
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            $this->audit($user->id, 'two_factor_recovery_code_used', $request, [
                'remaining' => count($user->two_factor_recovery_codes ?? []),
            ]);

            return;
        }

        $this->audit($user->id, 'two_factor_failed', $request);

        throw $this->invalidCode();
    }

    /**
     * Turns 2FA off for the account's own owner, on proof of password.
     *
     * The password check is not ceremony: without it, an unattended logged-in
     * browser is enough to strip the second factor off the account, which
     * reduces 2FA to a speed bump for exactly the attacker it exists to stop.
     *
     * @throws ApiException
     */
    public function disable(User $user, string $password, Request $request): void
    {
        if (! Hash::check($password, $user->password)) {
            $this->audit($user->id, 'two_factor_disable_failed', $request);

            throw new ApiException(
                ErrorCode::ValidationFailed,
                'The password is incorrect.',
                errors: [[
                    'field' => 'password',
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => 'The password is incorrect.',
                ]],
            );
        }

        $this->clear($user);

        $this->audit($user->id, 'two_factor_disabled', $request);
    }

    /**
     * Issues a new set of recovery codes, invalidating the old ones.
     *
     * All-or-nothing on purpose: a user regenerating codes has usually lost
     * track of which of the old ones were used or seen, and topping the list up
     * would leave those still live.
     *
     * @return array<int, string>
     *
     * @throws ApiException
     */
    public function regenerateRecoveryCodes(User $user, Request $request): array
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Two-factor authentication is not enabled for this account.',
            );
        }

        $plain = $this->freshRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $this->hashAll($plain)])->save();

        $this->audit($user->id, 'two_factor_recovery_codes_regenerated', $request);

        return $plain;
    }

    /**
     * Super Admin clears another account's 2FA - the break-glass path (T-09).
     *
     * Somebody has to be able to do this. An admin who loses their phone and
     * their printed recovery codes is otherwise locked out permanently: the
     * password reset flow (SEC-AUTH-06) sets a password, and the challenge
     * still stands behind it.
     *
     * Restricted to Super Admin, never self-service, and audited against BOTH
     * users - the actor and the subject. This is the single most abusable
     * action in the module: whoever performs it can then sign in as that person
     * with a password reset, so the audit trail is the only control left.
     *
     * @throws ApiException
     */
    public function resetFor(User $actor, User $target, Request $request): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Only a Super Admin can reset another user\'s two-factor authentication.',
            );
        }

        if ($actor->is($target)) {
            /*
             * Deliberately refused. A Super Admin removing their own second
             * factor from an already-authenticated session is the "unattended
             * browser" hole that `disable()` closes with a password check - so
             * they use that path, with the password, like everyone else.
             */
            throw new ApiException(
                ErrorCode::Forbidden,
                'Use your own security settings to disable two-factor authentication on your account.',
            );
        }

        $this->clear($target);

        $this->audit($actor->id, 'two_factor_reset_for_user', $request, [
            'target_user_id' => $target->id,
            'target_email' => $target->email,
        ]);

        $this->audit($target->id, 'two_factor_reset_by_admin', $request, [
            'actor_user_id' => $actor->id,
        ]);
    }

    /**
     * A valid, not-yet-used TOTP code.
     *
     * The `<=` comparison is the anti-replay rule: a code is valid for its
     * whole 30-second window plus drift, so accepting the same step twice would
     * let anyone who saw the code over a shoulder, in a screenshot, or in a
     * proxy log present it again seconds later.
     */
    private function consumeTotp(User $user, string $code): bool
    {
        $step = Totp::match((string) $user->two_factor_secret, $code);

        if ($step === null || ($user->two_factor_last_timestep !== null
            && $step <= $user->two_factor_last_timestep)) {
            return false;
        }

        $user->forceFill(['two_factor_last_timestep' => $step])->save();

        return true;
    }

    /**
     * A recovery code, removed from the stored set as it is accepted.
     *
     * Single-use is structural - the row is rewritten without it inside the
     * same transaction that accepts it - rather than a flag somebody has to
     * remember to check.
     */
    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $stored = $user->two_factor_recovery_codes ?? [];
        $candidate = trim(strtolower($code));

        foreach ($stored as $index => $hash) {
            if (Hash::check($candidate, $hash)) {
                unset($stored[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($stored),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function freshRecoveryCodes(): array
    {
        return array_map(
            fn () => Totp::recoveryCode(),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    private function hashAll(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make($code), $codes);
    }

    private function clear(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();
    }

    /** @throws ApiException */
    private function assertEligible(User $user): void
    {
        if (! $this->mayEnrol($user)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Two-factor authentication is available to administrators only.',
            );
        }
    }

    private function invalidCode(): ApiException
    {
        // One message for a wrong TOTP code, an expired one, a replayed one and
        // a spent recovery code. Distinguishing them tells an attacker which of
        // their guesses was structurally close.
        return new ApiException(
            ErrorCode::ValidationFailed,
            'That code is not valid. Check your authenticator app and try again.',
            errors: [[
                'field' => 'code',
                'code' => ErrorCode::ValidationFailed->value,
                'message' => 'That code is not valid. Check your authenticator app and try again.',
            ]],
        );
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
