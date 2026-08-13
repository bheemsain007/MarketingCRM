<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Browser screens for two-factor authentication (SEC-AUTH-07, T-09).
 *
 * Thin, like every other web controller here: it translates HTTP into calls on
 * TwoFactorService and renders what comes back. Every rule - who may enrol,
 * what counts as a valid code, what a replay is - lives in the service, so the
 * token login path and these pages cannot drift apart.
 *
 * Two screens, doing different jobs:
 *
 *   `/account/two-factor`     enrolment and recovery codes, for a fully
 *                             authenticated administrator
 *   `/two-factor-challenge`   the gate a pending session sits behind
 *
 * The challenge deliberately does NOT sit behind the `auth` middleware alias
 * plus a permission - a pending session IS authenticated, and gating it any
 * further would make the page unreachable by the only people who need it.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuthService $auth,
    ) {}

    // -----------------------------------------------------------------------
    // Enrolment (/account/two-factor)
    // -----------------------------------------------------------------------

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.two-factor', [
            'user' => $user,
            'mayEnrol' => $this->twoFactor->mayEnrol($user),
            'enabled' => $user->hasTwoFactorEnabled(),
            'remaining' => count($user->two_factor_recovery_codes ?? []),
            // Present only for the one request that just generated them: they
            // are stored hashed, so this page can never render them again.
            'secret' => $request->session()->get('two_factor_secret'),
            'otpauthUri' => $request->session()->get('two_factor_otpauth_uri'),
            'recoveryCodes' => $request->session()->get('two_factor_recovery_codes'),
        ]);
    }

    public function begin(Request $request): RedirectResponse
    {
        $enrolment = $this->service(
            fn () => $this->twoFactor->beginEnrolment($request->user(), $request),
        );

        /*
         * Flashed, not persisted. The secret has to survive exactly one
         * redirect so the page can draw it next to the code field; leaving it
         * in the session afterwards would keep a password-equivalent in the
         * sessions table for the rest of the day.
         */
        return redirect()->route('web.account.two-factor')
            ->with('two_factor_secret', $enrolment['secret'])
            ->with('two_factor_otpauth_uri', $enrolment['otpauth_uri']);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string']]);

        $codes = $this->service(
            fn () => $this->twoFactor->confirmEnrolment($request->user(), $validated['code'], $request),
        );

        return redirect()->route('web.account.two-factor')
            ->with('two_factor_recovery_codes', $codes)
            ->with('status', 'Two-factor authentication is now on. Save the recovery codes below - they are not shown again.');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $codes = $this->service(
            fn () => $this->twoFactor->regenerateRecoveryCodes($request->user(), $request),
        );

        return redirect()->route('web.account.two-factor')
            ->with('two_factor_recovery_codes', $codes)
            ->with('status', 'New recovery codes issued. The previous set no longer works.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $validated = $request->validate(['password' => ['required', 'string']]);

        $this->service(
            fn () => $this->twoFactor->disable($request->user(), $validated['password'], $request),
            'password',
        );

        return redirect()->route('web.account.two-factor')
            ->with('status', 'Two-factor authentication is off.');
    }

    // -----------------------------------------------------------------------
    // Challenge (/two-factor-challenge)
    // -----------------------------------------------------------------------

    public function challenge(Request $request): View|RedirectResponse
    {
        // Reached without a pending session - already past the gate, or never
        // in it. Sending them on rather than rendering a form that would
        // refuse everything.
        if (! $request->session()->get(AuthService::TWO_FACTOR_PENDING, false)) {
            return redirect()->route('web.dashboard');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        if (! $request->session()->get(AuthService::TWO_FACTOR_PENDING, false)) {
            return redirect()->route('web.dashboard');
        }

        $validated = $request->validate(['code' => ['required', 'string']]);

        /** @var User $user */
        $user = $request->user();

        $this->service(fn () => $this->twoFactor->challenge($user, $validated['code'], $request), 'code');

        // Only now does the login count: the work session, `last_login_at` and
        // the `login` audit entry are all written here (FR-ATT-01).
        $this->auth->completeTwoFactorLogin($user, $request);

        return redirect()->intended(route('web.dashboard'));
    }

    /**
     * Runs a service call and renders its refusal as a form error.
     *
     * The message comes from the service unchanged, so the browser sees exactly
     * what the API would - including the single indistinguishable message for a
     * wrong, expired, replayed or already-spent code, which is what stops the
     * form telling an attacker which of those their guess was.
     */
    private function service(callable $call, string $field = 'code'): mixed
    {
        try {
            return $call();
        } catch (ApiException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
