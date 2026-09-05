<?php

namespace Tests\Browser;

use App\Enums\RoleName;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\CreatesRoleUsers;
use Tests\DuskTestCase;

/**
 * Creating a lead through the web form and seeing it in the list (T-42,
 * FR-LEAD-01).
 *
 * `leads/form.blade.php` is a shell over `POST /api/v1/leads` and
 * `leads/index.blade.php` is a shell over `GET /api/v1/leads` - neither page
 * does anything itself. A Feature test can hit both endpoints directly and
 * prove the API round trip works, but it cannot prove the FORM actually
 * produces the payload the API expects, or that the list's own AJAX call
 * picks up a record created a moment ago through a completely different page.
 * That handoff - write on one screen, read on another, both through the same
 * API - is what this proves.
 */
class LeadCreationTest extends DuskTestCase
{
    use CreatesRoleUsers;

    public function test_a_lead_created_through_the_form_appears_in_the_list_via_ajax(): void
    {
        // Manager: Team data scope, so the unassigned lead this test creates
        // is visible both immediately after creation (PageController's
        // `viewableAfterCreate`) and in the list's own AJAX fetch afterwards.
        // A Telecaller (Own scope) would create a lead it could not itself see -
        // a real, separate finding (T-47), not a fixture problem to route around.
        $user = $this->userWithRole(RoleName::Manager);
        $name = 'Dusk Journey Lead '.uniqid();
        $phone = '9'.random_int(100000000, 999999999);

        $this->browse(function (Browser $browser) use ($user, $name, $phone) {
            $browser->loginAs($user)
                ->visit('/leads/create')
                ->waitFor('#lead-form')
                ->type('#f-name', $name)
                ->type('#f-phone', $phone)
                ->press('Create lead')
                // viewableAfterCreate = true for a Manager, so success redirects
                // straight to the new lead's own detail page. The id is only
                // known after the API assigns it, so this waits on a marker
                // that is always on the detail page rather than a fixed path.
                ->waitFor('#status-badge')
                ->assertSee($name);

            $this->assertStringContainsString('/leads/', parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH));

            // Now prove the SEPARATE list screen's own AJAX read picks up what
            // the form just wrote - not the same request, not the same page.
            $browser->visit('/leads')
                ->waitFor('#lead-rows')
                ->type('#f-q', $name)
                ->click('#f-apply')
                ->waitForText($name)
                ->assertSee($name);
        });
    }

    public function test_a_duplicate_phone_is_refused_with_a_field_level_error_in_the_browser(): void
    {
        // BR-DUP-02: the API refuses a second lead on the same number. The
        // form's own error-mapping (showErrors() in leads/form.blade.php)
        // is what turns that 422 into `.is-invalid` on the phone field - a
        // Feature test sees the 422 body; it cannot see whether the browser
        // painted it anywhere a person would notice.
        $user = $this->userWithRole(RoleName::Manager);
        $phone = '9'.random_int(100000000, 999999999);

        \App\Models\Lead::factory()->create(['phone_e164' => '+91'.$phone, 'phone_raw' => $phone]);

        $this->browse(function (Browser $browser) use ($user, $phone) {
            $browser->loginAs($user)
                ->visit('/leads/create')
                ->waitFor('#lead-form')
                ->type('#f-name', 'Duplicate Phone Attempt')
                ->type('#f-phone', $phone)
                ->press('Create lead')
                ->waitFor('#f-phone.is-invalid')
                ->assertVisible('#f-phone.is-invalid')
                ->assertPathIs('/leads/create');
        });
    }
}
