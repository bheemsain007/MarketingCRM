<?php

namespace Tests\Feature\Ops;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards that reported PASS while guarding nothing (T-30, SEC-OPS-*).
 *
 * Three settings in this application were wired to a value that could never
 * arrive. TRUSTED_PROXIES was read with env() from bootstrap/app.php, which runs
 * before .env is parsed, so the trust-everything branch was taken on every
 * deploy. The PII disk check read a config key that does not exist, so it
 * compared null against 'public' for ever. The mail from-address shipped empty
 * with nothing checking it. None of the three errored; all three passed.
 *
 * These tests fail if any of them goes back to reading nothing.
 */
class SecurityConfigTest extends TestCase
{
    use RefreshDatabase;

    /** Forced into config before the application boots - see createApplication(). */
    private static ?string $trustedProxies = null;

    protected function setUp(): void
    {
        // parent::setUp() builds the application, so an override has to be in
        // place before it runs. Each test starts on the shipped value.
        self::$trustedProxies = null;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        self::$trustedProxies = null;

        parent::tearDown();
    }

    /**
     * The framework's own, plus a pre-boot override of the trusted proxy list.
     *
     * The list is applied once, at boot - config does not exist any earlier -
     * so a test that wants a different one cannot just call config() in its
     * body. It has to be there before the application boots.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        // The parent also memoises $traitsUsedByTest here, for the two cached-
        // config traits this class does not use. setUpTraits() computes it
        // itself when it is unset, so leaving it out changes nothing.
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        if (self::$trustedProxies !== null) {
            $app->booting(function (Application $app): void {
                $app['config']->set('security.trusted_proxies', self::$trustedProxies);
            });
        }

        $app->make(ConsoleKernel::class)->bootstrap();

        return $app;
    }

    private function bootWithTrustedProxies(string $proxies): void
    {
        self::$trustedProxies = $proxies;

        $this->refreshApplication();
    }

    /**
     * The IP the application believes a request came from.
     *
     * That single value is what the rate-limit key and the address on every
     * audit row are built from (NFR-04, SEC-AUD-02), which is why it matters
     * who is allowed to set X-Forwarded-For.
     */
    private function reportedIpFor(string $remoteAddr, string $forwardedFor): string
    {
        Route::get('/_trusted-proxy-probe', fn () => request()->ip());

        return (string) $this->call('GET', '/_trusted-proxy-probe', server: [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ])->assertOk()->getContent();
    }

    /**
     * Run the checklist and hand back what it printed.
     *
     * Not `$this->artisan()->assertFailed()`: the suite's own environment makes
     * the command exit non-zero regardless (sync queue, http:// APP_URL), so the
     * exit code proves nothing. What has to be asserted is whether one named
     * check printed PASS or FAIL, which means reading the output.
     */
    private function runCheck(): string
    {
        Artisan::call('crm:production-check');

        return Artisan::output();
    }

    // -----------------------------------------------------------------------
    // Trusted proxies (T-30)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_pinned_proxy_list_is_actually_applied(): void
    {
        // The regression test. With the list read through env() in
        // bootstrap/app.php it was ignored on every deploy and every caller was
        // trusted, so this request's X-Forwarded-For was believed.
        $this->bootWithTrustedProxies('10.10.10.10');

        $this->assertSame(
            '203.0.113.7',
            $this->reportedIpFor(remoteAddr: '203.0.113.7', forwardedFor: '198.51.100.4'),
            'A caller absent from the trusted list must not be able to choose its own IP.',
        );
    }

    #[Test]
    public function a_caller_that_is_on_the_pinned_list_is_still_believed(): void
    {
        // The other half: pinning must not break the proxy that is real, or the
        // fix is a rollback waiting to happen.
        $this->bootWithTrustedProxies('203.0.113.7');

        $this->assertSame(
            '198.51.100.4',
            $this->reportedIpFor(remoteAddr: '203.0.113.7', forwardedFor: '198.51.100.4'),
        );
    }

    #[Test]
    public function the_shared_hosting_default_trusts_whoever_connected(): void
    {
        // `*` is deliberate on the shared-hosting target (DEPLOYMENT §3A): TLS
        // terminates at the provider's proxy, and without trusting it every
        // url() is built as http:// and the secure session cookie never comes
        // back - which looks exactly like "login does nothing".
        $this->bootWithTrustedProxies('*');

        $this->assertSame(
            '198.51.100.4',
            $this->reportedIpFor(remoteAddr: '203.0.113.7', forwardedFor: '198.51.100.4'),
        );
    }

    #[Test]
    public function an_empty_proxy_list_trusts_nothing(): void
    {
        // Documented in config/security.php, and worth pinning down: an empty
        // value is the one setting that ignores every forwarded header.
        $this->bootWithTrustedProxies('');

        $this->assertSame(
            '203.0.113.7',
            $this->reportedIpFor(remoteAddr: '203.0.113.7', forwardedFor: '198.51.100.4'),
        );
    }

    #[Test]
    public function bootstrap_app_never_reads_the_environment_directly(): void
    {
        /*
         * Every closure in bootstrap/app.php runs while a kernel is being
         * RESOLVED, which is before LoadEnvironmentVariables and
         * LoadConfiguration have run. An env() there reads nothing and returns
         * its own default without saying so - which is the whole of T-30, and
         * would be equally silent for the next setting somebody puts here.
         *
         * Comments are stripped first so the explanation above the fix does not
         * trip its own test.
         */
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path('bootstrap/app.php'))) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];

                continue;
            }

            $code .= $token;
        }

        $this->assertStringNotContainsString(
            'env(',
            $code,
            'bootstrap/app.php runs before .env is loaded; read the value from config instead.',
        );
    }

    // -----------------------------------------------------------------------
    // Private disks (SEC-FILE-03, SEC-PII-04/05)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_public_lead_import_disk_fails_the_check(): void
    {
        // The check read `crm.lead_import.disk` for its whole life. No such key
        // exists - it is `crm.imports.disk` - so the lookup returned null, null
        // is never 'public', and this reported PASS every single time.
        config(['crm.imports.disk' => 'public']);

        $this->assertMatchesRegularExpression('/FAIL\s+lead imports disk/', $this->runCheck());
    }

    #[Test]
    public function a_public_lead_export_disk_fails_the_check(): void
    {
        // An export is the entire lead database in one file (SEC-PII-04) and
        // was not checked at all.
        config(['crm.exports.disk' => 'public']);

        $this->assertMatchesRegularExpression('/FAIL\s+lead exports disk/', $this->runCheck());
    }

    #[Test]
    public function a_public_recordings_disk_fails_the_check(): void
    {
        config(['crm.recordings.disk' => 'public']);

        $this->assertMatchesRegularExpression('/FAIL\s+recordings disk/', $this->runCheck());
    }

    #[Test]
    public function a_disk_key_that_resolves_to_nothing_fails_rather_than_passing_quietly(): void
    {
        // The shape of the original defect, now caught: a key that is not there
        // reads as null, and a check that only compares against 'public' waves
        // null through. A missing key is now louder than a wrong one.
        config(['crm.exports.disk' => null]);

        $this->assertMatchesRegularExpression('/FAIL\s+lead exports disk/', $this->runCheck());
    }

    #[Test]
    public function private_disks_pass_the_check(): void
    {
        config([
            'crm.recordings.disk' => 'recordings',
            'crm.imports.disk' => 'local',
            'crm.exports.disk' => 'local',
        ]);

        $output = $this->runCheck();

        $this->assertMatchesRegularExpression('/PASS\s+recordings disk/', $output);
        $this->assertMatchesRegularExpression('/PASS\s+lead imports disk/', $output);
        $this->assertMatchesRegularExpression('/PASS\s+lead exports disk/', $output);
    }

    // -----------------------------------------------------------------------
    // Mail (SEC-AUTH-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_empty_mail_from_address_fails_the_check(): void
    {
        // `.env.example` shipped MAIL_FROM_ADDRESS empty, an empty line resolves
        // to '' rather than falling through to the config default, and Symfony
        // then refuses to build the message - so password reset, the only way
        // back into an account here, sent nothing and said nothing.
        config(['mail.from.address' => '']);

        $this->assertMatchesRegularExpression('/FAIL\s+MAIL_FROM_ADDRESS/', $this->runCheck());
    }

    #[Test]
    public function a_configured_from_address_passes_the_check(): void
    {
        config(['mail.from.address' => 'no-reply@crm.test']);

        $this->assertMatchesRegularExpression('/PASS\s+MAIL_FROM_ADDRESS\s+no-reply@crm\.test/', $this->runCheck());
    }

    #[Test]
    public function a_log_mailer_on_a_production_host_fails_the_check(): void
    {
        config(['mail.default' => 'log']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertMatchesRegularExpression('/FAIL\s+MAIL_MAILER/', $this->runCheck());
    }

    #[Test]
    public function a_log_mailer_off_production_is_only_a_warning(): void
    {
        // A local box is meant to be on `log`. Failing there is how a check
        // becomes something people run with `|| true`.
        config(['mail.default' => 'log']);

        $this->assertMatchesRegularExpression('/WARN\s+MAIL_MAILER/', $this->runCheck());
    }

    #[Test]
    public function a_real_mailer_passes_the_check(): void
    {
        config(['mail.default' => 'smtp']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertMatchesRegularExpression('/PASS\s+MAIL_MAILER\s+smtp/', $this->runCheck());
    }
}
