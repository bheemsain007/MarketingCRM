<?php

namespace Tests\Browser;

use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Infrastructure smoke test (T-42): proves ChromeDriver, the served app and
 * the Dusk-only database are wired together correctly before any real
 * journey is trusted. If this fails, nothing else in tests/Browser can be
 * believed either.
 */
class ExampleTest extends DuskTestCase
{
    public function test_the_guest_login_page_renders_in_a_real_browser(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->assertSee('Sign in');
        });
    }
}
