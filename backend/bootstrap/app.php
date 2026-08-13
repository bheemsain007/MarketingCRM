<?php

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RequireTwoFactorChallenge;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Versioned API. routes/api.php is left in place for Laravel's own
        // conventions; all project endpoints live under /api/v1 (NFR-02).
        api: __DIR__.'/../routes/api_v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Same-origin browser requests authenticate by SESSION; everything else
         * by bearer token (ADR-A, Phase 8).
         *
         * Sanctum decides per request: a call carrying a stateful Origin/Referer
         * gets the session cookie treatment, anything else falls through to the
         * token guard. That is what lets the Web CRM and the Flutter app share
         * one API surface without a second auth story - and it keeps the
         * browser from having to hold a token where XSS could read it.
         */
        $middleware->statefulApi();

        $middleware->api(prepend: [
            ForceJsonResponse::class,
            AssignCorrelationId::class,
        ]);

        /*
         * Shared hosting terminates TLS at the host's proxy and forwards
         * plain HTTP to PHP (DEPLOYMENT §3A). Without this, every url() is
         * built as http://, `$request->secure()` is false - so HSTS is never
         * sent - and `SESSION_SECURE_COOKIE` on a request Laravel believes is
         * insecure produces a cookie the browser then refuses to return, which
         * looks exactly like "login does nothing".
         *
         * `*` is the correct value HERE and would be wrong on a public host:
         * the app is only reachable through the provider's own proxy, so there
         * is no path by which an attacker's X-Forwarded-For arrives unfiltered.
         * On a VPS behind your own load balancer, pin this to its address.
         * TRUSTED_PROXIES exists so that is a config change (T-30).
         */
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*') === '*'
                ? '*'
                : explode(',', (string) env('TRUSTED_PROXIES')),
        );

        /*
         * Browser hardening on the web group (SEC-OPS-02). The API group is
         * left out deliberately - see SecurityHeaders for why a CSP on a JSON
         * body protects nothing.
         */
        $middleware->web(append: [
            SecurityHeaders::class,

            /*
             * A browser session that has passed the password but not the second
             * factor is held at the challenge (SEC-AUTH-07).
             */
            RequireTwoFactorChallenge::class,
        ]);

        /*
         * The same gate on the API group. The Web CRM calls /api/v1 with that
         * same session cookie (ADR-A), so protecting only the Blade pages would
         * leave the entire API open to a half-authenticated browser. Token
         * requests carry no session and fall through untouched.
         */
        $middleware->api(append: [
            RequireTwoFactorChallenge::class,
        ]);

        $middleware->alias([
            // Route-level permission gate (SEC-AUTHZ-02).
            //   ->middleware('permission:leads.view')
            'permission' => EnsurePermission::class,
        ]);

        // Laravel's `auth` middleware looks for a route literally named
        // `login`; every web route here is namespaced `web.*`.
        $middleware->redirectGuestsTo(fn () => route('web.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Every API error is rendered through ApiResponse so the envelope holds
         * on failure paths too, not just happy paths (NFR-03).
         *
         * Internals - SQL, stack traces, file paths - never reach the client
         * (NFR-08, SEC-OPS-03). They are logged instead, correlated by
         * X-Correlation-ID.
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;    // Let web routes render normally.
            }

            return match (true) {
                /*
                 * Already-built responses pass straight through.
                 *
                 * HttpResponseException wraps a finished response and carries an
                 * EMPTY message, and it does not implement HttpExceptionInterface.
                 * Anything that throws one - a throttle limiter with a custom
                 * response callback, abort() with a response, some form-request
                 * paths - would otherwise fall to the default branch and be
                 * rewritten as a blank 500, discarding the real response.
                 */
                $e instanceof HttpResponseException => $e->getResponse(),

                // Domain exceptions carry their own code and status.
                $e instanceof ApiException => $e->render(),

                $e instanceof ValidationException => ApiResponse::validationFailed(
                    $e->errors(),
                ),

                $e instanceof AuthenticationException,
                $e instanceof RouteNotFoundException => ApiResponse::error(
                    ErrorCode::Unauthenticated,
                ),

                $e instanceof AuthorizationException => ApiResponse::error(
                    ErrorCode::Forbidden,
                ),

                // Model binding misses and unmatched routes look identical to a
                // client - both are simply "not found".
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    ErrorCode::NotFound,
                ),

                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    ErrorCode::RateLimitExceeded,
                ),

                // Any other HTTP exception keeps its status but is still wrapped
                // in the envelope rather than Symfony's default body.
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    ErrorCode::ServerError,
                    $e->getMessage() ?: null,
                    status: $e->getStatusCode(),
                ),

                // Unhandled: generic message to the client, full detail to logs.
                default => ApiResponse::error(
                    ErrorCode::ServerError,
                    // Debug builds surface the real message to speed up local
                    // work; production never does.
                    config('app.debug') ? $e->getMessage() : null,
                ),
            };
        });
    })->create();
