<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Browser hardening headers (SEC-OPS-02, T-39).
 *
 * The property worth testing is not "a header is present" - it is that the
 * policy still permits the four vendor libraries the Blade views actually load.
 * A CSP that blocks Bootstrap does not fail loudly: the page returns 200 with
 * every style stripped, which passes any smoke test written against a status
 * code and is discovered by a user.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function every_page_carries_the_baseline_hardening_headers(): void
    {
        // The login page, because it is the one page reachable before auth -
        // so it is the one an attacker frames or sniffs first.
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    #[Test]
    public function hsts_is_sent_only_over_https(): void
    {
        /*
         * A browser ignores HSTS on a plain HTTP response, so sending it there
         * is harmless but useless. The reason to assert it is the reverse case:
         * local development would otherwise pin `localhost` to HTTPS in the
         * developer's browser for a year, and there is no way to undo that from
         * inside the application.
         */
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    #[Test]
    public function the_policy_permits_the_cdn_the_views_actually_load_from(): void
    {
        /*
         * Bootstrap, Bootstrap Icons, jQuery and Chart.js all come from
         * jsdelivr (ADR-A), and the icon font is a separate fetch that
         * `script-src` does not cover. Losing `font-src` is the classic
         * version of this bug: everything works and the icons render as boxes.
         */
        $policy = SecurityHeaders::policy();

        $this->assertStringContainsString("script-src 'self' https://cdn.jsdelivr.net", $policy);
        $this->assertStringContainsString("style-src 'self' https://cdn.jsdelivr.net", $policy);
        $this->assertStringContainsString("font-src 'self' data: https://cdn.jsdelivr.net", $policy);
    }

    #[Test]
    public function the_policy_is_honest_about_inline_scripts_and_still_blocks_exfiltration(): void
    {
        /*
         * This is the T-39 trade-off, asserted so nobody "tightens" it by
         * accident and takes the product down: every Blade page carries inline
         * <script> blocks, so 'unsafe-inline' is required for the app to run.
         *
         * `connect-src 'self'` is what still earns the policy its place. It is
         * the directive 'unsafe-inline' does NOT re-open, and it is the one
         * that stops injected JavaScript POSTing the lead list to somebody
         * else's server - the highest-value outcome of an XSS in a CRM.
         */
        $policy = SecurityHeaders::policy();

        $this->assertStringContainsString("'unsafe-inline'", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
    }

    #[Test]
    public function turning_inline_scripts_off_removes_the_allowance_rather_than_needing_a_deploy(): void
    {
        // The point of the config switch: the day the inline scripts move into
        // asset files, tightening the policy is one .env line (T-39).
        config(['security.csp.allow_inline' => false]);

        $this->assertStringNotContainsString("'unsafe-inline'", SecurityHeaders::policy());
    }

    #[Test]
    public function report_only_mode_swaps_the_header_so_nothing_is_blocked(): void
    {
        // How an operator finds out what a stricter policy would break, before
        // it breaks it for a user.
        config(['security.csp.report_only' => true]);

        $this->get('/login')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('Content-Security-Policy-Report-Only');
    }

    #[Test]
    public function json_responses_do_not_carry_a_content_security_policy(): void
    {
        /*
         * There is no document for a browser to apply a policy to, so the
         * header would be bytes on every API response and protection on none.
         * Asserting it keeps somebody from "improving" the middleware by
         * dropping the content-type check.
         */
        $this->getJson('/api/v1/health')->assertHeaderMissing('Content-Security-Policy');
    }

    #[Test]
    public function the_whole_set_can_be_switched_off_from_config(): void
    {
        // Not an invitation - an escape hatch. A header that breaks a
        // customer's browser must be removable without a code deploy.
        config(['security.headers.enabled' => false]);

        $this->get('/login')->assertHeaderMissing('X-Content-Type-Options');
    }
}
