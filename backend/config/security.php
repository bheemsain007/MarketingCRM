<?php

/**
 * Transport and browser-side security configuration (SEC-OPS-02, T-39).
 *
 * Separate from config/crm.php on purpose: that file is business rules, tuned
 * by whoever runs the business. This one is tuned by whoever runs the server,
 * and getting it wrong shows up as a blank screen rather than a wrong number.
 *
 * Like every other config file this is the ONLY layer allowed to call env().
 * After `config:cache` runs in production env() returns null everywhere else,
 * so a header read through env() from a middleware would silently stop being
 * sent on exactly the deploy that turned caching on.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies (SEC-OPS-02, T-30)
    |--------------------------------------------------------------------------
    | Which upstream proxies may set X-Forwarded-*. Applied by bootstrap/app.php.
    |
    | It has to live here rather than being read with env() from
    | bootstrap/app.php: the withMiddleware() closure there runs when the HTTP
    | kernel is RESOLVED, which is before LoadEnvironmentVariables parses .env,
    | so an env() call in that closure returns its own default and a pinned list
    | is silently ignored. What that leaves behind is trust-everything, and a
    | client can then set X-Forwarded-For freely - which decides request()->ip(),
    | and so the rate-limit key and the address on every audit row.
    |
    | `*` means "trust whoever connected". That is correct on the shared-hosting
    | target (DEPLOYMENT §3A), where the app is only reachable through the
    | provider's own proxy so no attacker-supplied header arrives unfiltered, and
    | wrong on a public host. Behind your own load balancer, pin it to that
    | balancer's address; the value is comma-separated and the literal
    | `REMOTE_ADDR` is accepted. An empty value trusts no proxy at all.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),

    /*
    |--------------------------------------------------------------------------
    | Response headers (SEC-OPS-02)
    |--------------------------------------------------------------------------
    | Applied by App\Http\Middleware\SecurityHeaders on the web group.
    |
    | `enabled` exists so a single .env line turns the whole set off. That is
    | not an invitation to disable it - it is there because a header that
    | breaks a customer's browser at 2am must be removable without a deploy,
    | and the alternative (commenting out middleware and pushing code) is what
    | actually happens when there is no switch.
    */
    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

        /*
         * HSTS. Sent only over HTTPS - a browser ignores it on a plain HTTP
         * response anyway, and sending it in local development would be noise.
         *
         * `include_subdomains` is on by default because the Hostinger target
         * (T-03) serves the app from one hostname; if a subdomain of the same
         * domain ever has to stay HTTP, turn it off BEFORE the first deploy.
         * The header is cached by the browser for `max_age` seconds, so the
         * mistake outlives the fix by up to a year.
         *
         * `preload` is off by default and should stay off until somebody has
         * decided to submit the domain to the preload list, because removal
         * from that list takes months.
         */
        'hsts' => [
            'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
            'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),   // 1 year
            'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
            'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
        ],

        /*
         * Clickjacking. DENY rather than SAMEORIGIN: nothing in the CRM frames
         * itself, so the stricter value costs nothing and SAMEORIGIN would
         * still allow a same-origin XSS to build a framing attack.
         *
         * The CSP `frame-ancestors` directive below is derived from this value,
         * so the two cannot disagree - which is the usual way this pair breaks.
         */
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),

        /*
         * `strict-origin-when-cross-origin` and not `no-referrer`.
         *
         * Lead ids appear in CRM URLs (/leads/4211). Cross-origin, only the
         * origin is sent, so the id never leaves the building; same-origin
         * navigation keeps the full path, which the app's own logs want.
         */
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),

        // Blocks Adobe cross-domain policy files being honoured from this host.
        'permitted_cross_domain_policies' => env('SECURITY_CROSS_DOMAIN_POLICIES', 'none'),

        /*
         * Permissions-Policy is NOT sent by default (null).
         *
         * The obvious value would disable camera and microphone, and the
         * obvious value is the wrong one here: call recording and any future
         * browser-side dialer need the microphone, and a policy header that
         * blocks it fails in a way that looks like a broken headset rather than
         * a security setting. Set it deliberately, once somebody has confirmed
         * what the browser half actually uses.
         */
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy (SEC-OPS-02, T-39)
    |--------------------------------------------------------------------------
    | READ THIS BEFORE TIGHTENING IT.
    |
    | The Blade views load Bootstrap, Bootstrap Icons, jQuery and Chart.js from
    | cdn.jsdelivr.net, and every page carries inline <script> blocks that wire
    | jQuery to the /api/v1 endpoints (ADR-A). A textbook `script-src 'self'`
    | policy therefore breaks every screen in the product on the first request.
    |
    | So the default below is honest rather than aspirational: it permits
    | exactly what the app does today and blocks everything else. What it still
    | buys, even with 'unsafe-inline':
    |
    |   - `default-src 'self'` stops content being pulled from any host nobody
    |     listed here, which is what an injected <img>/<iframe>/<script src>
    |     beacon needs to exfiltrate data.
    |   - `connect-src 'self'` stops injected JavaScript POSTing the lead list
    |     to an attacker's server - the highest-value outcome of an XSS in a
    |     CRM, and the one 'unsafe-inline' does NOT re-open.
    |   - `object-src 'none'`, `base-uri 'self'` and `form-action 'self'` close
    |     plugin execution, <base> hijacking and credential-posting to a foreign
    |     origin.
    |
    | What it does NOT buy: protection against reflected/stored XSS executing
    | inline. That is bought by escaping, which Blade does by default, and it is
    | the reason this is a documented trade-off (T-39) and not a shrug.
    |
    | The route to a strict policy is to move the inline scripts into asset
    | files and self-host the four vendor libraries - a build-step change, not a
    | header change. Until somebody does that work, `SECURITY_CSP_ALLOW_INLINE`
    | is the switch, and `SECURITY_CSP_REPORT_ONLY=true` is how you find out
    | what would break before you flip it.
    */
    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),

        /*
         * Report-Only sends the policy as `Content-Security-Policy-Report-Only`:
         * browsers report violations and enforce nothing. This is the setting
         * to use for a week before tightening anything.
         */
        'report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),

        // Optional collector URL. Empty means violations are reported nowhere,
        // which is the honest default for a shared-hosting install with no
        // reporting endpoint to send them to.
        'report_uri' => env('SECURITY_CSP_REPORT_URI'),

        /*
         * Vendor asset hosts, comma-separated. Adding a CDN here is the ONLY
         * place a new script/style origin gets allowed - which is the point:
         * the list of hosts allowed to run code in the CRM is one config line
         * that a reviewer can read.
         */
        'cdn_hosts' => env('SECURITY_CSP_CDN_HOSTS', 'https://cdn.jsdelivr.net'),

        /*
         * `true` adds 'unsafe-inline' to script-src and style-src. See the
         * block above - with the current views, `false` renders the product
         * unusable. It is exposed so the flip is a config change on the day the
         * inline scripts are gone, not a code change.
         */
        'allow_inline' => (bool) env('SECURITY_CSP_ALLOW_INLINE', true),

        /*
         * Base directives, before CDN hosts and 'unsafe-inline' are merged in
         * by the middleware. Anything absent from this map is not emitted at
         * all, and `default-src` catches it.
         *
         * `img-src` allows data: because Chart.js renders to a canvas and
         * Bootstrap Icons inline a handful of SVG data URIs; `font-src` allows
         * data: for the same reason.
         */
        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'"],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ],
    ],

];
