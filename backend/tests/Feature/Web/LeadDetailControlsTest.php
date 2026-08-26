<?php

namespace Tests\Feature\Web;

use App\Enums\RoleName;
use App\Models\Call;
use App\Models\CallRecording;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The five controls added to the lead detail page (ADR-A, Phase 8).
 *
 * The page is a shell - its rows arrive by AJAX - but the CONTROLS are rendered
 * server-side inside `@permission` blocks, so what is worth asserting here is
 * which role is offered which control. That is the usability half of the gate;
 * the route middleware and the policies are the half that actually refuses.
 *
 * Nothing below depends on the clock, so no time is frozen: the markup a role
 * sees is the same at 3am as at noon.
 */
class LeadDetailControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    /**
     * A telecaller is scoped to their OWN leads (DataScope::Own), so a lead they
     * are meant to open has to be assigned to them - otherwise the 403 under
     * test would be a scoping 403 rather than a permission one.
     */
    private function lead(?User $owner = null): Lead
    {
        return Lead::factory()->create(['assigned_to' => $owner?->id]);
    }

    // -----------------------------------------------------------------------
    // 1. Score explanation (BR-SCORE-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_score_breakdown_panel_is_drawn_for_anyone_who_can_open_the_lead(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->assertSee('Interest score')
            // The breakdown, not just the number: a bare score cannot answer
            // "why is this lead hot".
            ->assertSee('id="score-lines"', false)
            ->assertSee('id="score-temperature"', false);
    }

    // -----------------------------------------------------------------------
    // 2. Archive / restore (FR-LEAD-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_role_holding_leads_archive_is_offered_the_archive_confirmation(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertSee('id="lead-archive"', false)
            // Destructive-feeling, so it asks rather than firing on one click.
            ->assertSee('id="archive-modal"', false)
            ->assertSee('Archive this lead');
    }

    #[Test]
    public function a_telecaller_is_not_offered_the_archive_control(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->assertDontSee('id="lead-archive"', false)
            ->assertDontSee('id="archive-modal"', false);
    }

    #[Test]
    public function an_archived_lead_offers_restore_instead_of_archive(): void
    {
        $lead = $this->lead();
        $lead->delete();

        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads/'.$lead->id)
            ->assertOk()
            ->assertSee('id="lead-restore"', false)
            // Offering "Archive" on something already archived is how a page
            // teaches people not to trust it.
            ->assertDontSee('id="lead-archive"', false)
            ->assertSee('Archived.');
    }

    #[Test]
    public function an_archived_lead_renders_none_of_the_write_controls(): void
    {
        $lead = $this->lead();
        $lead->delete();

        // Every /leads/{lead}/* endpoint resolves the lead by implicit binding
        // and answers 404 for a trashed one, so a control drawn here could only
        // fail. Restore is the one thing that still works.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads/'.$lead->id)
            ->assertOk()
            ->assertDontSee('id="call-form"', false)
            ->assertDontSee('id="signal-modal"', false)
            ->assertDontSee('Change status')
            ->assertSee('id="lead-restore"', false);
    }

    #[Test]
    public function an_archived_lead_that_is_out_of_scope_is_still_refused(): void
    {
        $lead = $this->lead();
        $lead->delete();

        // Resolving with withTrashed() must not become a way around the policy:
        // a telecaller has no claim on a lead that was never theirs.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/leads/'.$lead->id)
            ->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // 3. Recording playback (FR-REC-04, SEC-FILE-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_role_holding_recordings_listen_gets_the_recording_column(): void
    {
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertSee('<th>Recording</th>', false);
    }

    #[Test]
    public function a_role_without_recordings_listen_gets_no_recording_column(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        // A role that may not listen does not even get a column telling it
        // which calls have audio.
        $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->assertDontSee('<th>Recording</th>', false);
    }

    #[Test]
    public function only_a_role_holding_recordings_delete_is_offered_the_deletion_confirmation(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->user(RoleName::Admin))
            ->get('/leads/'.$lead->id)
            ->assertOk()
            ->assertSee('id="rec-delete-modal"', false)
            ->assertSee('Delete this recording');

        // A manager may listen to a recording without being trusted to destroy
        // one - the two permissions are deliberately separate (SEC-PII-05).
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads/'.$lead->id)
            ->assertOk()
            ->assertDontSee('id="rec-delete-modal"', false);
    }

    /**
     * The flag the Recording column is drawn from.
     *
     * Asserted here because the screen depends on it: without `has_recording`
     * the page would have to ask `/calls/{call}/recording` once per row, and
     * that endpoint is audited (SEC-FILE-04) - probing it would record an access
     * for every call nobody listened to.
     */
    #[Test]
    public function the_calls_list_says_which_calls_carry_a_recording(): void
    {
        $manager = $this->user(RoleName::Manager);
        $lead = $this->lead();

        $withAudio = Call::factory()->create(['lead_id' => $lead->id, 'user_id' => $manager->id]);
        $withoutAudio = Call::factory()->create(['lead_id' => $lead->id, 'user_id' => $manager->id]);

        CallRecording::create([
            'call_id' => $withAudio->id,
            'lead_id' => $lead->id,
            'user_id' => $manager->id,
            'storage_disk' => 'recordings',
            'storage_path' => 'calls/'.$withAudio->id.'/audio.m4a',
            'upload_status' => 'uploaded',
        ]);

        $items = collect(
            $this->actingAs($manager, 'sanctum')
                ->getJson('/api/v1/leads/'.$lead->id.'/calls')
                ->assertOk()
                ->json('data.items')
        )->keyBy('id');

        $this->assertTrue($items[$withAudio->id]['has_recording']);
        $this->assertFalse($items[$withoutAudio->id]['has_recording']);
    }

    #[Test]
    public function deleting_a_recording_needs_its_own_permission_and_keeps_the_row(): void
    {
        Storage::fake('recordings');

        $admin = $this->user(RoleName::Admin);
        $lead = $this->lead();
        $call = Call::factory()->create(['lead_id' => $lead->id, 'user_id' => $admin->id]);

        $path = 'calls/'.$call->id.'/audio.m4a';
        Storage::disk('recordings')->put($path, 'AUDIO');

        $recording = CallRecording::create([
            'call_id' => $call->id,
            'lead_id' => $lead->id,
            'user_id' => $admin->id,
            'storage_disk' => 'recordings',
            'storage_path' => $path,
            'upload_status' => 'uploaded',
        ]);

        // A manager may listen without being trusted to destroy (SEC-PII-05).
        $this->actingAs($this->user(RoleName::Manager), 'sanctum')
            ->deleteJson('/api/v1/calls/'.$call->id.'/recording')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/calls/'.$call->id.'/recording')
            ->assertOk();

        Storage::disk('recordings')->assertMissing($path);

        // The row survives its audio: "there was a recording and it was deleted"
        // is a different fact from "there never was one" (BR-REC-02).
        $recording->refresh();
        $this->assertSame('purged', $recording->upload_status);
        $this->assertNull($recording->storage_path);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recording_deleted',
            'user_id' => $admin->id,
        ]);
    }

    // -----------------------------------------------------------------------
    // 4. AI calling (FR-AI-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_role_that_may_call_gets_the_ai_call_button_inside_the_callability_gated_form(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->assertSee('id="ai-call"', false)
            // Inside #call-form, which /callability shows and hides - so DNC and
            // calling hours refuse the AI dial with the manual dial's verdict.
            ->assertSee('id="call-form"', false)
            // The unconfigured-provider message is a standing condition and gets
            // a panel on the page, not a toast.
            ->assertSee('id="ai-call-blocked"', false);
    }

    #[Test]
    public function a_read_only_role_gets_no_ai_call_button(): void
    {
        // Viewer holds leads.view and calls.view, but not calls.create.
        $this->actingAs($this->user(RoleName::Viewer))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertDontSee('id="ai-call"', false)
            ->assertDontSee('id="ai-call-blocked"', false);
    }

    // -----------------------------------------------------------------------
    // 5. Manual interest capture (FR-INT-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_role_holding_leads_update_can_record_an_interest_signal(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $this->actingAs($telecaller)
            ->get('/leads/'.$this->lead($telecaller)->id)
            ->assertOk()
            ->assertSee('id="signal-open"', false)
            ->assertSee('Record an interest signal')
            // The AI signal carries a model confidence only the provider can
            // supply, so a human is never offered it (BR-INT-04).
            ->assertDontSee('AI detected interest');
    }

    #[Test]
    public function a_read_only_role_cannot_record_an_interest_signal(): void
    {
        // Accounts holds leads.view but deliberately not leads.update.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/leads/'.$this->lead()->id)
            ->assertOk()
            ->assertDontSee('id="signal-open"', false)
            ->assertDontSee('id="signal-modal"', false);
    }
}
