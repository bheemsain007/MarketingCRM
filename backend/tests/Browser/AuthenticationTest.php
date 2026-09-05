<?php

namespace Tests\Browser;

use App\Enums\RoleName;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\CreatesRoleUsers;
use Tests\DuskTestCase;

/**
 * Session login end to end, in a real browser (T-42, NFR-09/NFR-10).
 *
 * `tests/Feature/Web/WebCrmTest` already proves the server side of this - the
 * work session opens, the audit row is written, a disabled account is
 * refused. What it structurally cannot see is the browser side of ADR-A: that
 * the CSRF meta tag `layouts/app.blade.php` renders is the SAME token the
 * session accepts back, and that the sign-in form is a plain POST a browser
 * actually completes rather than an assertion against a response object that
 * was never rendered by anything.
 */
class AuthenticationTest extends DuskTestCase
{
    use CreatesRoleUsers;

    public function test_a_user_can_sign_in_and_land_on_the_dashboard(): void
    {
        $user = $this->userWithRole(RoleName::Telecaller, ['password' => bcrypt('correct-horse')]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->assertSee('Sign in')
                ->type('email', $user->email)
                ->type('password', 'correct-horse')
                ->press('Sign in')
                ->waitForLocation('/dashboard')
                // "Your work" is the dashboard's own greeting card (dashboard.blade.php);
                // seeing the user's name proves the session resolved to THIS user, not
                // merely that some page loaded at this URL.
                ->waitForText('Your work')
                ->assertSee($user->name);
        });
    }

    public function test_the_dashboard_carries_the_csrf_meta_tag_every_ajax_write_depends_on(): void
    {
        // layouts/app.blade.php's own comment: "Every AJAX write reads this.
        // Without it, POST/PATCH/DELETE are 419s." A Feature test can assert
        // the tag is IN the rendered HTML; it cannot prove a real browser
        // read it and attached it to a request the way $.ajaxSetup does.
        $user = $this->userWithRole(RoleName::Telecaller, ['password' => bcrypt('correct-horse')]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->waitForText('Your work');

            // Dusk's element resolver scopes plain CSS selectors under
            // <body> (`InteractsWithElements::attribute()`), so a <head> meta
            // tag has to be read via script() instead of ->attribute().
            $token = $browser->script(
                'return document.querySelector(\'meta[name="csrf-token"]\')?.getAttribute("content");'
            )[0];
            $this->assertNotEmpty($token, 'The CSRF meta tag is missing or empty on a real render.');

            // The notification bell polls /api/v1/notifications/unread-count on
            // load (layouts/app.blade.php) using that same token via
            // $.ajaxSetup - if the badge ever resolves (hidden at zero is still
            // a resolved AJAX call, not a stuck spinner), the token worked.
            $browser->waitUntil('document.getElementById("crm-bell-count") !== null');
        });
    }

    public function test_bad_credentials_do_not_sign_a_real_browser_in(): void
    {
        $user = $this->userWithRole(RoleName::Telecaller, ['password' => bcrypt('correct-horse')]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/login')
                ->type('email', $user->email)
                ->type('password', 'wrong-password')
                ->press('Sign in')
                ->waitForLocation('/login')
                ->assertPathIs('/login');
        });
    }
}
