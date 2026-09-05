<?php

namespace Tests\Browser;

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\CreatesRoleUsers;
use Tests\DuskTestCase;

/**
 * Changing a lead's status from the detail page and watching the page catch
 * up with itself (T-42, BR-STAT-02).
 *
 * `leads/show.blade.php`'s status control is not one request - `apply-status`
 * on success chains `loadTransitions()`, `loadHistory()` and `loadTimeline()`
 * (see the `#apply-status` click handler), each its own AJAX call reading
 * back what the PATCH just wrote. A Feature test can call
 * `PATCH /leads/{id}/status` directly and assert the database changed; it
 * cannot see whether the four calls this page makes after a write actually
 * fire in the browser, in order, against DOM elements that still exist by
 * the time each response lands. That orchestration - not the transition rule
 * itself, which `Feature\Leads\LeadStatusTest` already owns - is what a real
 * browser is for here.
 */
class LeadStatusTransitionTest extends DuskTestCase
{
    use CreatesRoleUsers;

    public function test_changing_status_updates_the_badge_and_history_live(): void
    {
        $user = $this->userWithRole(RoleName::Manager);
        $lead = Lead::factory()->create(['status' => LeadStatus::New->value]);

        $this->browse(function (Browser $browser) use ($user, $lead) {
            $browser->loginAs($user)
                ->visit('/leads/'.$lead->id)
                // loadTransitions() on page load fills #next-status from
                // GET /leads/{id}/transitions - waiting for a real <option>
                // proves that first AJAX call already landed.
                ->waitFor('#next-status option')
                ->assertSeeIn('#status-badge', 'New');

            $browser->select('#next-status', LeadStatus::Contacted->value)
                ->click('#apply-status')
                // The badge text and its Bootstrap colour class both come from
                // the SECOND /transitions call loadTransitions() re-runs after
                // the PATCH - not from the option the browser just picked.
                ->waitForTextIn('#status-badge', 'Contacted');

            $this->assertStringContainsString('text-bg-info', $browser->attribute('#status-badge', 'class'));

            // loadHistory() fires in parallel with loadTransitions() inside the
            // same .done() handler (not after it), so waiting only for the
            // badge above does not guarantee this call has landed too - hence
            // waiting for the text itself rather than merely a <tr> existing
            // (a stale "no status changes yet" placeholder row would satisfy
            // that and pass for the wrong reason).
            $browser->click('button[data-bs-target="#tab-history"]')
                ->waitForTextIn('#history-rows', 'Contacted');

            // loadTimeline() likewise re-reads the combined activity feed -
            // switch tabs to where it actually rendered.
            $browser->click('button[data-bs-target="#tab-timeline"]')
                ->waitFor('#timeline-list > div');
        });

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    public function test_a_lead_with_no_legal_moves_offers_no_transition_control(): void
    {
        // Converted is terminal (BR-STAT-02) - the API returns an empty
        // `available` list, and the page's own `loadTransitions()` is what
        // decides whether to hide the form or show it empty. Worth a real
        // browser because that branch runs only after the AJAX response is
        // in hand, not from anything Blade decided at render time.
        $user = $this->userWithRole(RoleName::Manager);
        $lead = Lead::factory()->create(['status' => LeadStatus::Converted->value]);

        $this->browse(function (Browser $browser) use ($user, $lead) {
            $browser->loginAs($user)
                ->visit('/leads/'.$lead->id)
                ->waitUntil('document.getElementById("transition-empty") !== null'
                    .' && !document.getElementById("transition-empty").classList.contains("d-none")')
                ->assertVisible('#transition-empty');

            $this->assertStringContainsString('d-none', $browser->attribute('#transition-form', 'class'));
        });
    }
}
