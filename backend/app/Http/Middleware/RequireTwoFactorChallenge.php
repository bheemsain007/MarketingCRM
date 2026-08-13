<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Auth\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a half-authenticated browser session at the 2FA challenge
 * (SEC-AUTH-07, T-09).
 *
 * `AuthService::loginWeb()` signs the user in at the Laravel level and marks
 * the session pending, rather than inventing a second "partially logged in"
 * guard. That is the simpler design, but it has one sharp edge: a pending
 * session is a real session cookie, and the Web CRM's jQuery calls
 * `/api/v1/*` same-origin with it (ADR-A). Gating only the Blade routes would
 * leave every API endpoint open to a browser that has typed a password and
 * nothing else - which is the entire attack 2FA exists to stop.
 *
 * So this runs on BOTH groups and answers in the shape each one expects: a
 * redirect for a browser, the standard error envelope for the API. Token
 * requests carry no session and fall straight through, so the Flutter app is
 * untouched (its own factor check happens inline in `AuthService::login()`).
 *
 * The allow-list is deliberately tiny. Anything reachable from a pending
 * session is reachable without a second factor, so it holds only the challenge
 * itself and the way out.
 */
class RequireTwoFactorChallenge
{
    /**
     * Routes a pending session may still reach.
     *
     * Logout is on the list because the alternative - a user who cannot find
     * their phone and cannot sign out either - ends with them clearing cookies,
     * which is worse for everyone and teaches the wrong habit.
     */
    private const ALLOWED_ROUTES = [
        'web.two-factor.challenge',
        'web.two-factor.verify',
        'web.logout',
        'web.login',
        'web.login.attempt',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isPending($request)) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Two-factor authentication is required to continue.',
            );
        }

        return redirect()->route('web.two-factor.challenge');
    }

    /**
     * `hasSession()` first: a bearer-token API request has no session at all,
     * and asking one for a value raises rather than returning false.
     */
    private function isPending(Request $request): bool
    {
        return $request->hasSession()
            && $request->session()->get(AuthService::TWO_FACTOR_PENDING, false) === true;
    }
}
