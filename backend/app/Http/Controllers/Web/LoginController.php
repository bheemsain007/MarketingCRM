<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Browser sign-in for the Web CRM (ADR-A, Phase 8).
 *
 * Session-based, not token-based. The Web CRM is same-origin with the API, so
 * a server-side session is both simpler and safer than a token sitting in
 * browser storage where any XSS could read it. The Flutter app keeps using
 * bearer tokens against the same endpoints - one API, two credential styles
 * (ARCHITECTURE §1).
 */
class LoginController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function show(): View|RedirectResponse
    {
        if (auth()->check()) {
            return redirect()->route('web.dashboard');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        try {
            $this->auth->loginWeb(
                $credentials['email'],
                $credentials['password'],
                $request,
                $request->boolean('remember'),
            );
        } catch (ApiException $e) {
            /*
             * Rendered as a form error rather than the API envelope. The
             * message comes from the service unchanged, so the browser sees
             * exactly what the API would - including the deliberate refusal to
             * distinguish "no such account" from "wrong password", which would
             * otherwise be a user-enumeration oracle (SEC-AUTH-02).
             */
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()->intended(route('web.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            $this->auth->logoutWeb($user, $request);
        }

        auth()->logout();

        // Both are required: invalidate drops the session data, regenerating
        // the CSRF token stops the old one being replayed against the next
        // session (SEC-AUTH-06).
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('web.login')->with('status', 'You have been signed out.');
    }
}
