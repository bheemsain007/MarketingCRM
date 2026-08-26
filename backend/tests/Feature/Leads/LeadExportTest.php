<?php

namespace Tests\Feature\Leads;

use App\Enums\ExportStatus;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadExport;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bulk lead CSV export (FR-LEAD-12, SEC-PII-04, SEC-IN-07, SEC-AUD-02).
 *
 * The suite runs with QUEUE_CONNECTION=sync (phpunit.xml), so a requested
 * export completes inside the request/response cycle and end-to-end outcomes
 * are assertable without faking the queue.
 */
class LeadExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // The generated CSV never touches the real filesystem in tests
        // (mirrors LeadImportTest's own Storage::fake for the upload side).
        Storage::fake('local');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function actingAsRole(RoleName $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['team_id' => $team?->id]);
        $user->roles()->attach(Role::where('name', $role->value)->first());
        $user = $user->fresh();

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function latestExport(): LeadExport
    {
        return LeadExport::latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------------

    #[Test]
    public function a_manager_can_request_list_and_download_an_export(): void
    {
        $this->actingAsRole(RoleName::Manager);

        // Unassigned - the shared pool, visible at Team scope regardless of
        // which team the requester is on (HasRolesAndPermissions::applyDataScope).
        Lead::factory()->count(3)->create();

        $response = $this->postJson('/api/v1/leads/export', []);

        // 202, because the request accepted the job - it did not promise the
        // file exists yet (API_DOCUMENTATION §5, NFR-06).
        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message', 'errors',
                'data' => ['id', 'status', 'row_count', 'requested_at'],
            ]);

        $export = $this->latestExport();

        // QUEUE_CONNECTION=sync means the job already ran by the time the
        // response was built.
        $this->assertSame(ExportStatus::Completed, $export->status);
        $this->assertSame(3, $export->row_count);
        $this->assertNotNull($export->file_path);
        $this->assertNotNull($export->expires_at);

        $this->getJson('/api/v1/leads/exports')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $export->id)
            ->assertJsonPath('data.items.0.status', 'completed');

        $download = $this->get("/api/v1/leads/exports/{$export->id}/download");

        $download->assertOk();
        $this->assertStringContainsString('text/csv', $download->headers->get('content-type'));

        $rows = explode("\n", trim($download->streamedContent()));
        // Header + 3 leads.
        $this->assertCount(4, $rows);
        $this->assertStringStartsWith('id,name,company,phone', $rows[0]);
    }

    #[Test]
    public function a_telecaller_cannot_request_an_export(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson('/api/v1/leads/export', [])->assertStatus(403);

        $this->assertSame(0, LeadExport::count());
    }

    #[Test]
    public function a_telecaller_cannot_list_or_download_exports(): void
    {
        $this->actingAsRole(RoleName::Manager);
        Lead::factory()->create();
        $this->postJson('/api/v1/leads/export', [])->assertStatus(202);
        $export = $this->latestExport();

        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/leads/exports')->assertStatus(403);
        $this->get("/api/v1/leads/exports/{$export->id}/download")->assertStatus(403);
    }

    #[Test]
    public function a_user_cannot_download_another_users_export(): void
    {
        $team = Team::factory()->create();
        $owner = $this->actingAsRole(RoleName::Manager, $team);
        Lead::factory()->create();
        $this->postJson('/api/v1/leads/export', [])->assertStatus(202);
        $export = $this->latestExport();

        // Same role, different team - and still refused. An export's rows are
        // somebody else's read of the lead database (SEC-AUTHZ-04).
        $other = $this->actingAsRole(RoleName::Manager, Team::factory()->create());
        $this->assertNotSame($owner->id, $other->id);

        $this->get("/api/v1/leads/exports/{$export->id}/download")->assertStatus(403);

        // ...and it does not appear in their list either, or the list would
        // leak what the download endpoint refuses.
        $this->getJson('/api/v1/leads/exports')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    #[Test]
    public function a_full_scope_user_can_download_any_export(): void
    {
        $this->actingAsRole(RoleName::Manager);
        Lead::factory()->create();
        $this->postJson('/api/v1/leads/export', [])->assertStatus(202);
        $export = $this->latestExport();

        $this->actingAsRole(RoleName::Admin);

        $this->get("/api/v1/leads/exports/{$export->id}/download")->assertOk();
    }

    // -----------------------------------------------------------------------
    // Content
    // -----------------------------------------------------------------------

    #[Test]
    public function a_leading_formula_character_is_neutralised_in_every_cell(): void
    {
        $this->actingAsRole(RoleName::Manager);

        // Each of the four documented Excel/Sheets formula triggers
        // (SEC-IN-07), planted in a field that reaches the CSV untouched.
        Lead::factory()->create([
            'name' => '=cmd|calc!A0',
            'company' => '+1+1',
            'city' => '-2+3',
            'state' => '@SUM(A1)',
        ]);

        $this->postJson('/api/v1/leads/export', [])->assertStatus(202);
        $export = $this->latestExport();

        $content = $this->get("/api/v1/leads/exports/{$export->id}/download")->streamedContent();

        // Parsed with str_getcsv rather than matched as raw text: fputcsv is
        // free to wrap a field in quotes for reasons unrelated to formula
        // injection (this PHP build quotes any field containing a space,
        // among other things), so asserting on the exact byte sequence would
        // be testing an implementation detail instead of the actual cell
        // values a spreadsheet app would read.
        $lines = array_values(array_filter(explode("\n", trim($content))));
        $row = str_getcsv($lines[1]);

        // A raw formula character never leads a cell - the payload survives
        // as visible data, prefixed so Excel/Sheets render it as text.
        $this->assertSame("'=cmd|calc!A0", $row[1]);   // name
        $this->assertSame("'+1+1", $row[2]);            // company
        $this->assertSame("'-2+3", $row[7]);            // city
        $this->assertSame("'@SUM(A1)", $row[8]);        // state
    }

    #[Test]
    public function the_phone_number_is_written_raw_matching_the_api_precedent(): void
    {
        $this->actingAsRole(RoleName::Manager);

        Lead::factory()->create(['phone_e164' => '+919876543210']);

        $this->postJson('/api/v1/leads/export', [])->assertStatus(202);
        $export = $this->latestExport();

        $content = $this->get("/api/v1/leads/exports/{$export->id}/download")->streamedContent();

        // LeadResource exposes the raw E.164 number too - `leads.export`
        // being a separate, audited permission is the control (SEC-PII-04),
        // not masking the number a second time on top of it.
        $this->assertStringContainsString('+919876543210', $content);
    }

    // -----------------------------------------------------------------------
    // Filters and scoping
    // -----------------------------------------------------------------------

    #[Test]
    public function the_export_matches_the_same_filters_the_lead_list_accepts(): void
    {
        $this->actingAsRole(RoleName::Manager);

        Lead::factory()->create(['status' => LeadStatus::New->value]);
        Lead::factory()->create(['status' => LeadStatus::Interested->value]);

        $this->postJson('/api/v1/leads/export', ['filter' => ['status' => LeadStatus::Interested->value]])
            ->assertStatus(202);

        $export = $this->latestExport();

        $this->assertSame(1, $export->row_count);
        $this->assertSame(['status' => LeadStatus::Interested->value], $export->filters['filter']);
    }

    #[Test]
    public function an_unsupported_filter_field_is_rejected_before_anything_is_queued(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/export', ['filter' => ['nonsense' => '1']])
            ->assertStatus(422);

        $this->assertSame(0, LeadExport::count());
    }

    #[Test]
    public function a_manager_only_exports_leads_within_their_own_team_scope(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $managerA = $this->actingAsRole(RoleName::Manager, $teamA);
        $telecallerA = User::factory()->create(['team_id' => $teamA->id]);
        $telecallerA->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        $telecallerB = User::factory()->create(['team_id' => $teamB->id]);
        $telecallerB->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        Lead::factory()->create(['assigned_to' => $telecallerA->id]);
        Lead::factory()->create(['assigned_to' => $telecallerB->id]);

        $this->actingAs($managerA, 'sanctum');
        $this->postJson('/api/v1/leads/export', [])->assertStatus(202);

        // Team scope, not the whole database - the export must not let a
        // Manager see leads outside their normal scope just because an
        // export is running (SEC-AUTHZ-03).
        $this->assertSame(1, $this->latestExport()->row_count);
    }

    // -----------------------------------------------------------------------
    // Auditing
    // -----------------------------------------------------------------------

    #[Test]
    public function requesting_an_export_writes_an_audit_log_entry(): void
    {
        $manager = $this->actingAsRole(RoleName::Manager);
        Lead::factory()->create();

        $this->postJson('/api/v1/leads/export', [])->assertStatus(202);

        // `leads.export` is in Permission::isAudited(); EnsurePermission
        // writes this before the controller ever runs (SEC-AUD-02).
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->id,
            'action' => 'permission_used',
            'description' => 'leads.export',
        ]);

        $log = AuditLog::where('user_id', $manager->id)->where('description', 'leads.export')->firstOrFail();
        // Request::path() has no leading slash.
        $this->assertSame('api/v1/leads/export', $log->new_values['route'] ?? null);
    }
}
