<?php

namespace Tests\Feature\Web;

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\LeadExport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The export controls on the leads list (FR-LEAD-12, SEC-PII-04).
 *
 * The three export endpoints existed with no way to reach them from any screen.
 * What is worth asserting here is therefore not the markup but the three
 * promises the screen makes: it is drawn only for `leads.export`, it exports the
 * filter set the list is currently showing rather than a second interpretation
 * of it, and - because the job is queued - it never offers a download for a file
 * that is not there.
 *
 * Nothing here depends on the clock, so no time is frozen.
 */
class LeadExportScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** @param array<string, mixed> $attributes */
    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    // -----------------------------------------------------------------------
    // Who is offered the control
    // -----------------------------------------------------------------------

    #[Test]
    public function a_manager_sees_the_export_control_and_the_panel_of_past_exports(): void
    {
        // Manager holds leads.export; it is an audited permission (SEC-AUD-02).
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads')
            ->assertOk()
            ->assertSee('data-bs-target="#export-modal"', false)
            ->assertSee('Export leads to CSV')
            ->assertSee('Export current view')
            ->assertSee('/api/v1/leads/exports', false);
    }

    #[Test]
    public function a_telecaller_who_may_read_leads_is_offered_no_way_to_export_them(): void
    {
        /*
         * Telecallers hold leads.view and not leads.export. The API refuses the
         * request either way (SEC-AUTHZ-02); this asserts the screen does not
         * draw a button whose only outcome is a 403 - and, because bulk PII is
         * the point of the permission, that the export panel is not merely
         * hidden in markup they can read.
         */
        $response = $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/leads')
            ->assertOk()
            // The list itself still works for them.
            ->assertSee('id="lead-rows"', false);

        $response->assertDontSee('id="export-modal"', false)
            ->assertDontSee('Export current view')
            ->assertDontSee('/api/v1/leads/exports', false)
            ->assertDontSee('/api/v1/leads/export\'', false);
    }

    // -----------------------------------------------------------------------
    // What it exports
    // -----------------------------------------------------------------------

    #[Test]
    public function the_export_posts_the_same_filter_set_the_list_is_showing(): void
    {
        /*
         * One `filters()` builder feeds both the list request and the export
         * request. Two copies would drift, and the failure mode of that drift
         * is a CSV containing people the operator was not looking at
         * (FR-LEAD-12).
         */
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads')
            ->assertOk()
            ->assertSee("\$.post('/api/v1/leads/export', filters())", false)
            ->assertSee("\$.extend({ page: page, sort: \$('#f-sort').val() }, filters())", false)
            // Blank filters are omitted, not sent empty - the API rejects an
            // unknown or empty filter field outright.
            ->assertSee("if (status) params['filter[status]'] = status;", false);
    }

    #[Test]
    public function the_panel_says_plainly_when_no_filter_is_narrowing_the_export(): void
    {
        // An unfiltered export is the entire lead database in one file
        // (SEC-PII-04) - the screen says so before the click, not after.
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads')
            ->assertOk()
            ->assertSee('No filters — this exports every lead you can see.', false);
    }

    // -----------------------------------------------------------------------
    // Honesty about the queue (NFR-06)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_unfinished_export_is_shown_as_unfinished_rather_than_as_a_download(): void
    {
        /*
         * The API answers 202 and writes the file on the queue, so a download
         * link is drawn only for a `completed` export still inside its window.
         * Anything else is described - never linked, which would hand the user
         * a 404 dressed up as a file.
         */
        $this->actingAs($this->user(RoleName::Manager))
            ->get('/leads')
            ->assertOk()
            ->assertSee("if (row.status === 'completed' && !expired) {", false)
            ->assertSee('Still preparing…', false)
            ->assertSee('File deleted')
            // Polls while anything is running, and stops when the panel closes.
            ->assertSee('exportPoll = setTimeout(loadExports, 3000);', false)
            ->assertSee("on('hidden.bs.modal', function () { clearTimeout(exportPoll); });", false);
    }

    // -----------------------------------------------------------------------
    // The endpoints the screen actually calls
    // -----------------------------------------------------------------------

    #[Test]
    public function the_panels_own_requests_succeed_under_the_web_session(): void
    {
        /*
         * The screen is jQuery against the v1 API with the session cookie, not
         * a token - so the controls are only real if these three calls answer
         * for a signed-in web user. The download is asserted as a plain GET
         * because that is what the rendered anchor is.
         */
        Storage::fake('local');

        $manager = $this->user(RoleName::Manager);

        Lead::factory()->create(['status' => LeadStatus::Interested->value]);
        Lead::factory()->create(['status' => LeadStatus::New->value]);

        // Exactly the body `filters()` produces for "status = Interested".
        $this->actingAs($manager)
            ->post('/api/v1/leads/export', ['filter' => ['status' => LeadStatus::Interested->value]])
            ->assertStatus(202);

        $export = LeadExport::latest('id')->firstOrFail();

        // QUEUE_CONNECTION=sync in the suite, so the file already exists - and
        // the filter reached the job rather than being dropped in transit.
        $this->assertSame(1, $export->row_count);

        $this->actingAs($manager)
            ->getJson('/api/v1/leads/exports')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $export->id)
            ->assertJsonPath('data.items.0.status', 'completed');

        $this->actingAs($manager)
            ->get("/api/v1/leads/exports/{$export->id}/download")
            ->assertOk();
    }
}
