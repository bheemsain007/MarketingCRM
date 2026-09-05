<?php

namespace Tests\Browser;

use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Models\DncEntry;
use App\Models\Lead;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\CreatesRoleUsers;
use Tests\DuskTestCase;

/**
 * A control a role holds no permission for is genuinely absent from the
 * rendered DOM, not merely missing from the raw HTML a Feature test can read
 * (T-42, BR-DNC-06, SEC-AUTHZ-03).
 *
 * `dnc/index.blade.php` never puts a "Remove" button in server-rendered
 * markup at all - every row in `#dnc-rows` is drawn by `statusCell()` inside
 * the page's OWN JavaScript, after `GET /api/v1/dnc` resolves, branching on a
 * `canRemove` flag baked into the script tag from
 * `auth()->user()->hasPermission(Permission::DncRemove)`. A Feature test
 * that never executes that script cannot tell the difference between "the
 * button is absent" and "the button would have appeared once the AJAX call
 * this test never made had finished" - which is exactly the gap Dusk closes.
 * `Permission::defaultsFor()` grants Telecaller `dnc.view` and `dnc.create`
 * but deliberately not `dnc.remove`, so a Telecaller is the role to prove
 * this against.
 */
class PermissionGatedDncControlTest extends DuskTestCase
{
    use CreatesRoleUsers;

    public function test_a_telecaller_sees_the_suppression_but_never_a_remove_button(): void
    {
        $user = $this->userWithRole(RoleName::Telecaller);
        // Assigned to the viewer: a Telecaller's data scope is Own
        // (DataScope::Own), and DncController::index scopes orphan/unassigned
        // leads' entries to All-scope roles only - an unassigned lead's
        // suppression would be invisible to a Telecaller for a reason that has
        // nothing to do with the permission this test is actually about.
        $lead = Lead::factory()->create(['name' => 'Dusk Gated Contact', 'assigned_to' => $user->id]);
        DncEntry::factory()->reason(DncReason::NotInterested)->create(['lead_id' => $lead->id]);

        $this->browse(function (Browser $browser) use ($user, $lead) {
            $browser->loginAs($user)
                ->visit('/dnc')
                // Wait on the row actually rendering, not merely the table
                // shell - the assertion below is only meaningful once the AJAX
                // response has been drawn.
                ->waitForText($lead->name)
                ->assertSeeIn('#dnc-rows', 'Suppressed')
                // Scoped to the table body, not the whole page: the filter
                // panel's own "Removed only" option legitimately contains the
                // substring "Remove" and is not permission-gated at all.
                ->assertDontSeeIn('#dnc-rows', 'Remove');

            // Belt and braces: query the live DOM directly for the button
            // class the create-permission branch would have produced, rather
            // than trusting text alone.
            $count = $browser->script('return document.querySelectorAll(".remove-entry").length;')[0];
            $this->assertSame(0, $count, 'A Telecaller must never be offered the DNC removal control.');

            // The Telecaller DOES hold dnc.create, so the opposite control is
            // present - proving the absence above is a real permission gate
            // and not, say, the whole page having failed to render.
            $browser->assertVisible('#add-open');
        });
    }

    public function test_a_manager_holding_dnc_remove_sees_the_button(): void
    {
        // Control case: the SAME row, a role that DOES hold the permission,
        // proves the button's absence above is the permission and not a
        // fixture or markup bug.
        $user = $this->userWithRole(RoleName::Manager);
        $lead = Lead::factory()->create(['name' => 'Dusk Gated Contact Two']);
        DncEntry::factory()->reason(DncReason::NotInterested)->create(['lead_id' => $lead->id]);

        $this->browse(function (Browser $browser) use ($user, $lead) {
            $browser->loginAs($user)
                ->visit('/dnc')
                ->waitForText($lead->name)
                ->assertSeeIn('#dnc-rows', 'Remove');

            $count = $browser->script('return document.querySelectorAll(".remove-entry").length;')[0];
            $this->assertGreaterThan(0, $count);
        });
    }
}
