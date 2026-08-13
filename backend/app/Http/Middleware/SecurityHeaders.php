<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers for the Web CRM (SEC-OPS-02, T-39).
 *
 * Every value comes from config/security.php, which is where the reasoning for
 * each one is written. This class only decides *when* a header applies:
 *
 *   - HSTS is sent on HTTPS responses only. Browsers ignore it over plain HTTP,
 *     and emitting it in local development is noise that trains people to
 *     ignore the header.
 *   - CSP is sent on HTML responses only. A `Content-Security-Policy` on a JSON
 *     body or a streamed call recording costs bytes on every download and
 *     protects nothing, because there is no document for the browser to apply
 *     it to.
 *   - Nothing overwrites a header a response already set deliberately. A route
 *     that must be framed (none today) can set its own X-Frame-Options and this
 *     middleware will leave it alone.
 *
 * Registered on the web group in bootstrap/app.php. The API group is left out
 * on purpose: it answers JSON to a Flutter client and to same-origin jQuery,
 * neither of which is a browsing context, so the only header with any meaning
 * there is nosniff - and ForceJsonResponse already fixes the content type.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        $this->apply($response, 'X-Content-Type-Options', 'nosniff');

        $this->apply(
            $response,
            'X-Frame-Options',
            (string) config('security.headers.frame_options', 'DENY'),
        );

        $this->apply(
            $response,
            'Referrer-Policy',
            (string) config('security.headers.referrer_policy', 'strict-origin-when-cross-origin'),
        );

        $this->apply(
            $response,
            'X-Permitted-Cross-Domain-Policies',
            (string) config('security.headers.permitted_cross_domain_policies', 'none'),
        );

        if ($permissions = config('security.headers.permissions_policy')) {
            $this->apply($response, 'Permissions-Policy', (string) $permissions);
        }

        if ($request->secure() && config('security.headers.hsts.enabled', true)) {
            $this->apply($response, 'Strict-Transport-Security', $this->hsts());
        }

        if ($this->wantsCsp($response)) {
            $this->apply(
                $response,
                config('security.csp.report_only', false)
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy',
                self::policy(),
            );
        }

        return $response;
    }

    /**
     * The assembled Content-Security-Policy string.
     *
     * Static and public so a test can assert on the policy itself rather than
     * on a response that happens to carry it - the interesting failure is a
     * directive quietly losing the CDN host, which is invisible in a smoke test
     * because the page still renders from the browser cache.
     */
    public static function policy(): string
    {
        /** @var array<string, array<int, string>> $directives */
        $directives = config('security.csp.directives', []);

        $cdn = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('security.csp.cdn_hosts', '')),
        )));

        $inline = config('security.csp.allow_inline', true) ? ["'unsafe-inline'"] : [];

        /*
         * The CDN serves Bootstrap's CSS and its icon font as well as jQuery,
         * Bootstrap's JS bundle and Chart.js, so the same host list has to
         * reach script, style and font. Missing font-src is the classic version
         * of this bug: everything works and the icons render as empty boxes.
         */
        $directives['script-src'] = array_merge($directives['script-src'] ?? [], $cdn, $inline);
        $directives['style-src'] = array_merge($directives['style-src'] ?? [], $cdn, $inline);
        $directives['font-src'] = array_merge($directives['font-src'] ?? [], $cdn);

        /*
         * Derived from X-Frame-Options rather than configured separately. The
         * two headers say the same thing to different browser generations, and
         * every install where they disagree got there by editing one of them.
         */
        $directives['frame-ancestors'] = [
            strtoupper((string) config('security.headers.frame_options', 'DENY')) === 'SAMEORIGIN'
                ? "'self'"
                : "'none'",
        ];

        $parts = [];

        foreach ($directives as $directive => $sources) {
            $sources = array_values(array_unique($sources));

            if ($sources !== []) {
                $parts[] = $directive.' '.implode(' ', $sources);
            }
        }

        if ($reportUri = config('security.csp.report_uri')) {
            $parts[] = 'report-uri '.$reportUri;
        }

        return implode('; ', $parts);
    }

    private function hsts(): string
    {
        $value = 'max-age='.(int) config('security.headers.hsts.max_age', 31536000);

        if (config('security.headers.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        // Preload is meaningless without includeSubDomains, and the preload
        // list rejects submissions that carry it alone - so it is only appended
        // when both are set rather than trusting the operator to pair them.
        if (config('security.headers.hsts.preload', false)
            && config('security.headers.hsts.include_subdomains', true)) {
            $value .= '; preload';
        }

        return $value;
    }

    private function wantsCsp(Response $response): bool
    {
        if (! config('security.csp.enabled', true)) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        // An empty Content-Type means a redirect or a 204 - no document, so no
        // policy. Anything HTML gets one.
        return str_contains($contentType, 'text/html');
    }

    /** Sets a header only if the response has not already chosen a value. */
    private function apply(Response $response, string $header, string $value): void
    {
        if ($value !== '' && ! $response->headers->has($header)) {
            $response->headers->set($header, $value);
        }
    }
}
