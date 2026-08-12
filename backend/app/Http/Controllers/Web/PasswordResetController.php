<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Forgotten-password recovery for the Web CRM (SEC-AUTH-06).
 *
 * The one account-recovery path in the system, and deliberately the only one:
 * there is no administrator screen that sets another person's password,
 * because an administrator who can set a password can sign in as that person
 * and act as them - which would make every audit entry attributable to the
 * wrong human (SEC-AUD-02).
 *
 * The two POST actions carry the same 5/min limiter as login. They are
 * credential endpoints: one mails a token, the other consumes one, and both
 * are worth guessing at (SEC-AUTH-03).
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function showRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Always reports the same thing.
     *
     * Whether the address matched an active account, a disabled one, or
     * nothing at all, the response is identical - otherwise this form answers
     * "does this person work here?" for anyone who asks, without needing a
     * password (SEC-AUTH-02).
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        // `max:255` is not cosmetic: the address is written into an audit row,
        // and without a bound this endpoint is an unauthenticated way to push
        // an arbitrarily long attacker-controlled string into audit_logs.
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $this->auth->sendPasswordResetLink($validated['email'], $request);

        return back()->with('status', 'If that address belongs to an active account, a reset link is on its way. The link expires shortly, so use it soon.');
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            // Only to prefill the field; the token is what actually authorises
            // the reset, and the broker checks the two match.
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // `confirmed` requires password_confirmation; the strength rule is
            // the same one user creation and the account page apply, so a
            // password cannot be weakened by choosing the recovery route.
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        try {
            $this->auth->resetPassword($credentials, $request);
        } catch (ApiException $e) {
            // A spent, forged or expired token all land here and are told apart
            // by nobody - distinguishing them would say whether a given token
            // was ever real.
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()->route('web.login')
            ->with('status', 'Your password has been reset. Please sign in.');
    }
}
