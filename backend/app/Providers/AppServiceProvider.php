<?php

namespace App\Providers;

use App\Enums\ErrorCode;
use App\Services\Settings\SettingsService;
use App\Support\ApiResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Scoped, so one instance serves a whole request.
         *
         * The service memoises decrypted secrets in memory rather than in the
         * cache store (SEC-CFG-07). Per-request is the point: with a fresh
         * instance per injection, a credential written through one and read
         * through another within the same request would serve a stale value.
         */
        $this->app->scoped(SettingsService::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureRateLimiting();
        $this->configureBladeDirectives();
        $this->configurePasswordReset();
    }

    /**
     * Points the reset email at our own route (SEC-AUTH-06).
     *
     * Laravel's notification builds its link from a route named
     * `password.reset`; every route in this application is named `web.*`, so
     * without this the mail would either 500 or - worse - be silently wrong.
     * Stated here rather than by renaming the route, so the convention holds
     * and the coupling is visible.
     */
    private function configurePasswordReset(): void
    {
        ResetPassword::createUrlUsing(fn ($user, string $token) => url(route(
            'web.password.reset',
            ['token' => $token, 'email' => $user->getEmailForPasswordReset()],
            absolute: false,
        )));
    }

    /**
     * `@permission('leads.import') ... @endpermission` (Phase 8).
     *
     * Navigation and buttons are hidden from users who cannot use them. This
     * is a USABILITY measure, never a security one - the route middleware and
     * the policies are what actually stop the action. A hidden button is still
     * a reachable URL.
     */
    private function configureBladeDirectives(): void
    {
        Blade::if('permission', function (string ...$permissions) {
            return auth()->check() && auth()->user()->hasAnyPermission($permissions);
        });
    }

    private function configureModels(): void
    {
        /*
         * Fail loudly in development instead of silently in production.
         *
         * - preventLazyLoading catches N+1 queries at the point they are written
         *   rather than when a report times out at Phase 27.
         * - preventSilentlyDiscardingAttributes turns a typo'd or non-fillable
         *   attribute into an exception. Without it, an update that tries to set
         *   a guarded field (status, score, is_suppressed) is silently dropped
         *   and looks like it worked - a dangerous failure mode given those are
         *   exactly the fields protected by SEC-IN-06.
         */
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        Model::unguard(false);
    }

    /**
     * Named rate limiters (API_DOCUMENTATION §7, NFR-07).
     *
     * PROPOSED values, configurable via config/crm.php (T-04).
     */
    private function configureRateLimiting(): void
    {
        $limits = config('crm.api.rate_limits');

        // Authentication: limited per IP *and* per submitted email, so an
        // attacker rotating IPs cannot brute-force one account, and one IP
        // cannot spray many accounts (SEC-AUTH-03).
        RateLimiter::for('api-auth', function (Request $request) use ($limits) {
            return [
                Limit::perMinute($limits['auth'])->by('auth-ip:'.$request->ip()),
                Limit::perMinute($limits['auth'])->by('auth-user:'.$this->accountKey($request)),
            ];
        });

        /*
         * Password recovery gets its OWN buckets (SEC-AUTH-03).
         *
         * Per-account throttling is what the requirement asks for, and its
         * unavoidable cost is that anyone who knows an address can fill that
         * account's bucket. Sharing one bucket with login turned that into a
         * TOTAL lockout: the victim could neither sign in nor recover, because
         * the attack that blocked the first also blocked the escape hatch.
         * Separate namespaces mean jamming one never closes the other.
         */
        RateLimiter::for('password-recovery', function (Request $request) use ($limits) {
            return [
                Limit::perMinute($limits['auth'])->by('recover-ip:'.$request->ip()),
                Limit::perMinute($limits['auth'])->by('recover-user:'.$this->accountKey($request)),
            ];
        });

        RateLimiter::for('api-standard', fn (Request $request) => Limit::perMinute($limits['standard'])
            ->by($this->identify($request))
            ->response($this->tooManyRequests(...)));

        // Bulk triggers (campaign start, CSV import) are expensive downstream,
        // so they are throttled well below normal traffic.
        RateLimiter::for('api-bulk', fn (Request $request) => Limit::perMinute($limits['bulk'])
            ->by($this->identify($request))
            ->response($this->tooManyRequests(...)));

        // Generous: throttling a provider's valid retry would lose delivery
        // receipts and inbound leads (SEC-WH-05).
        RateLimiter::for('api-webhook', fn (Request $request) => Limit::perMinute($limits['webhook'])
            ->by($request->ip()));
    }

    /** Authenticated users are limited per account; guests per IP. */
    private function identify(Request $request): string
    {
        return $request->user()?->id
            ? 'user:'.$request->user()->id
            : 'ip:'.$request->ip();
    }

    /**
     * The per-account throttle key for a submitted email (SEC-AUTH-03).
     *
     * Lower-casing alone is not enough. Unicode gives many spellings that reach
     * the same mailbox and the same user row - accented and combining-mark
     * forms, full-width Latin - and each distinct STRING got its own fresh
     * bucket, so the per-account half of the limiter could be walked around by
     * varying the spelling. NFKC folds those to one canonical form first, so
     * one account is one bucket however the address is typed.
     */
    private function accountKey(Request $request): string
    {
        $email = trim((string) $request->input('email'));

        if (class_exists(\Normalizer::class)) {
            $email = \Normalizer::normalize($email, \Normalizer::FORM_KC) ?: $email;
        }

        return mb_strtolower($email);
    }

    /** Throttled responses use the standard envelope like every other error. */
    private function tooManyRequests(Request $request, array $headers)
    {
        return ApiResponse::error(ErrorCode::RateLimitExceeded)
            ->withHeaders($headers);
    }
}
