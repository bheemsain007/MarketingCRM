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
 * The DNC removal modal - a destructive action behind a Bootstrap modal
 * (T-42, BR-DNC-06).
 *
 * `dnc/index.blade.php` requires a reason before it will lift a suppression,
 * and the modal's own `#confirm-remove` handler maps a 422's `reason` field
 * error onto `.is-invalid` / `.invalid-feedback` by hand - jQuery reading a
 * JSON error body and mutating a Bootstrap modal that is already open. A
 * Feature test can prove the API rejects a blank reason; it cannot prove the
 * modal painted that rejection anywhere, or that a corrected resubmission
 * actually closes the modal and refreshes the row underneath it rather than
 * leaving a stale "Suppressed" button a person would click again.
 */
class DncRemovalModalTest extends DuskTestCase
{
    use CreatesRoleUsers;

    public function test_a_blank_reason_is_rejected_in_the_modal_and_a_valid_one_removes_the_suppression(): void
    {
        // Manager holds dnc.remove; Telecaller deliberately does not
        // (Permission::defaultsFor) - BR-DNC-06 keeps removal supervisory.
        $user = $this->userWithRole(RoleName::Manager);
        $lead = Lead::factory()->create(['name' => 'Dusk Suppressed Contact']);
        DncEntry::factory()->reason(DncReason::NotInterested)->create(['lead_id' => $lead->id]);

        $this->browse(function (Browser $browser) use ($user, $lead) {
            $browser->loginAs($user)
                ->visit('/dnc')
                ->waitForText($lead->name)
                ->click('.remove-entry')
                ->waitFor('#remove-modal.show')
                // Confirm with the reason field left blank.
                ->click('#confirm-remove')
                ->waitFor('#remove-reason.is-invalid')
                ->assertVisible('#remove-reason.is-invalid');

            $this->assertNotSame('', trim((string) $browser->text('#remove-modal .invalid-feedback')));

            // The modal is still open - a 422 must not have silently closed it.
            $browser->assertVisible('#remove-modal.show')
                ->type('#remove-reason', 'Customer called and asked to be reinstated')
                ->click('#confirm-remove')
                // Success closes the modal (modal.hide() in the done() handler).
                ->waitUntilMissing('#remove-modal.show')
                // And the list re-fetched: the row that was "Suppressed" now
                // reads "Removed" without a page reload.
                ->waitForText('Removed');
        });

        $this->assertFalse($lead->dncEntries()->first()->fresh()->active);
    }

    public function test_removing_an_absolute_reason_shows_the_elevated_warning(): void
    {
        // DoNotContact is one of the reasons DncReason::requiresElevatedRemoval()
        // flags (T-50) - the modal's #elevated-warning is drawn purely from a
        // data attribute the row carries, so this is genuinely a client-side
        // rendering decision worth checking in a real DOM.
        $user = $this->userWithRole(RoleName::Manager);
        $lead = Lead::factory()->create(['name' => 'Dusk Elevated Contact']);
        DncEntry::factory()->reason(DncReason::DoNotContact)->create(['lead_id' => $lead->id]);

        $this->browse(function (Browser $browser) use ($user, $lead) {
            $browser->loginAs($user)
                ->visit('/dnc')
                ->waitForText($lead->name)
                ->click('.remove-entry')
                ->waitFor('#remove-modal.show')
                ->assertVisible('#elevated-warning');
        });
    }
}
