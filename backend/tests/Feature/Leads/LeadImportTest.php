<?php

namespace Tests\Feature\Leads;

use App\Enums\ImportRowStatus;
use App\Enums\ImportStatus;
use App\Enums\RoleName;
use App\Jobs\ProcessLeadImport;
use App\Models\Lead;
use App\Models\LeadImport;
use App\Models\LeadSource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\Leads\LeadImportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bulk lead import (FR-LEAD-07, BR-DUP-01/02, BR-ASSIGN-01, SEC-AUTHZ-04,
 * SEC-PII-05).
 *
 * The suite runs with QUEUE_CONNECTION=sync, so an upload completes inside the
 * request and end-to-end outcomes are assertable. The one test that cares
 * about queueing fakes the queue explicitly.
 */
class LeadImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // The uploaded file never touches the real filesystem in tests.
        Storage::fake('local');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function user(RoleName $role, ?Team $team = null): User
    {
        $user = User::factory()->create(['team_id' => $team?->id]);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function actingAsRole(RoleName $role, ?Team $team = null): User
    {
        $user = $this->user($role, $team);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function csv(string $content, string $name = 'leads.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** A well-formed three-row file using the plainest possible headers. */
    private function goodFile(): UploadedFile
    {
        return $this->csv(<<<'CSV'
        name,phone,email,city
        Ramesh Kumar,9876543210,ramesh@example.com,Jaipur
        Sunita Devi,9876543211,sunita@example.com,Kota
        Arun Mehta,9876543212,,Udaipur
        CSV);
    }

    private function import(): LeadImport
    {
        return LeadImport::latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------------
    // Intake
    // -----------------------------------------------------------------------

    #[Test]
    public function a_csv_upload_is_accepted_with_202_not_200(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $response = $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()]);

        // 202, because the request accepted the file - it did not promise the
        // work is done (API_DOCUMENTATION §5, NFR-06).
        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message', 'errors',
                'data' => ['id', 'filename', 'status', 'progress', 'totals' => ['rows', 'imported', 'duplicates', 'invalid']],
            ]);

        $this->assertSame(3, $this->import()->total_rows);
    }

    #[Test]
    public function the_file_is_queued_rather_than_processed_in_the_request(): void
    {
        Queue::fake();

        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])
            ->assertStatus(202);

        // On the low-priority `imports` queue, never inline and never on a
        // queue shared with latency-sensitive work (ARCH §4).
        Queue::assertPushed(ProcessLeadImport::class, function (ProcessLeadImport $job) {
            return $job->importId === $this->import()->id
                && $job->queue === 'imports';
        });

        $this->assertSame(ImportStatus::Pending, $this->import()->status);
        $this->assertSame(0, Lead::count());
    }

    #[Test]
    public function the_uploaded_file_is_stored_privately_under_a_generated_name(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("name,phone\nRamesh,9876543210", 'my customer list.csv'),
        ])->assertStatus(202);

        $import = $this->import();

        // The private disk, never `public` - the file is a bulk PII payload
        // and must not be reachable over HTTP (SEC-FILE-02).
        $this->assertSame('local', $import->disk);
        Storage::disk('local')->assertExists($import->stored_path);

        // The original name is kept as data but never used as a path
        // (SEC-FILE-01).
        $this->assertSame('my customer list.csv', $import->original_filename);
        $this->assertStringNotContainsString('my customer list', $import->stored_path);
    }

    // -----------------------------------------------------------------------
    // Row outcomes
    // -----------------------------------------------------------------------

    #[Test]
    public function every_valid_row_becomes_a_lead(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);

        $import = $this->import();

        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(3, $import->imported_rows);
        $this->assertSame(0, $import->invalid_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(100, $import->progress());

        $this->assertSame(3, Lead::count());
        // Phone normalisation is not skipped by the import path - it runs in
        // LeadService, which every entry path shares (BR-DUP-01).
        $this->assertDatabaseHas('leads', [
            'name' => 'Ramesh Kumar',
            'phone_e164' => '+919876543210',
            'phone_raw' => '9876543210',
            'city' => 'Jaipur',
        ]);
    }

    #[Test]
    public function an_imported_lead_gets_a_creation_timeline_entry(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);

        $lead = Lead::where('phone_e164', '+919876543210')->firstOrFail();

        // FR-LEAD-09: an imported lead is not a second-class record - it has
        // the same history as one typed in by hand.
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'activity_type' => 'lead_created',
        ]);
    }

    #[Test]
    public function a_row_whose_number_already_exists_is_reported_as_duplicate_not_imported_twice(): void
    {
        $user = $this->actingAsRole(RoleName::Manager);

        $existing = Lead::factory()->create([
            'name' => 'Ramesh Kumar',
            'phone_e164' => '+919876543210',
            'assigned_to' => $user->id,
        ]);

        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);

        $import = $this->import();

        // BR-DUP-02: the lead already exists and already has an owner. That is
        // an expected outcome of a real import, not an error in the file.
        $this->assertSame(1, $import->duplicate_rows);
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame(0, $import->invalid_rows);
        $this->assertSame(ImportStatus::CompletedWithErrors, $import->status);

        $this->assertSame(1, Lead::where('phone_e164', '+919876543210')->count());

        // The report points at the record that already held the number, so the
        // operator can go straight to it.
        $this->assertDatabaseHas('lead_import_rows', [
            'lead_import_id' => $import->id,
            'status' => ImportRowStatus::Duplicate->value,
            'lead_id' => $existing->id,
        ]);
    }

    #[Test]
    public function a_duplicate_inside_the_same_file_is_caught_too(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv(<<<'CSV'
            name,phone
            Ramesh Kumar,9876543210
            Ramesh K,98765 43210
            CSV),
        ])->assertStatus(202);

        // The second row normalises to the same E.164 string as the first, so
        // the duplicate is caught even though the raw text differs.
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, $this->import()->duplicate_rows);
    }

    #[Test]
    public function an_invalid_row_is_reported_and_does_not_stop_the_import(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv(<<<'CSV'
            name,phone,email
            Ramesh Kumar,9876543210,ramesh@example.com
            Bad Number,12345,nope@example.com
            ,9876543211,noname@example.com
            Bad Email,9876543212,not-an-email
            Sunita Devi,9876543213,sunita@example.com
            CSV),
        ])->assertStatus(202);

        $import = $this->import();

        // FR-LEAD-07: "never partially corrupts on failure" - the three bad
        // rows are reported, the two good ones are still leads.
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame(3, $import->invalid_rows);
        $this->assertSame(5, $import->processed_rows);
        $this->assertSame(ImportStatus::CompletedWithErrors, $import->status);
        $this->assertSame(2, Lead::count());

        $rows = $import->rows()->where('status', ImportRowStatus::Invalid->value)->get();

        // The message names the actual problem - the operator has to be able
        // to fix the file from the report alone.
        $this->assertStringContainsString('mobile number', $rows->firstWhere('row_number', 2)->message);
        $this->assertStringContainsString('name', strtolower($rows->firstWhere('row_number', 3)->message));
        $this->assertStringContainsString('email', strtolower($rows->firstWhere('row_number', 4)->message));
    }

    #[Test]
    public function a_rejected_row_keeps_its_source_data_but_an_imported_row_does_not(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("name,phone\nRamesh Kumar,9876543210\nBad Number,12345"),
        ])->assertStatus(202);

        $rows = $this->import()->rows()->orderBy('row_number')->get();

        // Imported rows are already in `leads`; storing a second copy of their
        // PII in the report would be duplication for no purpose (SEC-PII-05).
        $this->assertNull($rows->firstWhere('row_number', 1)->data);
        $this->assertSame('12345', $rows->firstWhere('row_number', 2)->data['phone']);
    }

    #[Test]
    public function blank_lines_are_ignored_rather_than_counted_as_invalid(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("name,phone\nRamesh Kumar,9876543210\n\n\nSunita Devi,9876543211\n\n"),
        ])->assertStatus(202);

        $import = $this->import();

        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame(0, $import->invalid_rows);
    }

    // -----------------------------------------------------------------------
    // Header detection and mapping
    // -----------------------------------------------------------------------

    #[Test]
    public function common_header_labels_are_detected_automatically(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv(<<<'CSV'
            Full Name,Mobile No.,Email Address,Company Name,Town
            Ramesh Kumar,9876543210,ramesh@example.com,Kumar News,Jaipur
            CSV),
        ])->assertStatus(202);

        // assertEquals, not assertSame: MySQL's JSON type reorders object keys
        // on storage, so key order is not ours to assert.
        $this->assertEquals([
            'name' => 'Full Name',
            'phone' => 'Mobile No.',
            'email' => 'Email Address',
            'company' => 'Company Name',
            'city' => 'Town',
        ], $this->import()->column_map);

        $this->assertDatabaseHas('leads', [
            'phone_e164' => '+919876543210',
            'company' => 'Kumar News',
            'city' => 'Jaipur',
        ]);
    }

    #[Test]
    public function an_explicit_column_map_overrides_detection(): void
    {
        $this->actingAsRole(RoleName::Manager);

        // Two phone-ish columns: detection would pick "Contact", but the real
        // number is in "Contact 2".
        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv(<<<'CSV'
            Full Name,Contact,Contact 2
            Ramesh Kumar,landline,9876543210
            CSV),
            'column_map' => ['phone' => 'Contact 2'],
        ])->assertStatus(202);

        $this->assertSame('Contact 2', $this->import()->column_map['phone']);
        $this->assertDatabaseHas('leads', ['phone_e164' => '+919876543210']);
    }

    #[Test]
    public function a_file_with_no_phone_column_is_rejected_before_anything_is_queued(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $response = $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("Full Name,Email\nRamesh Kumar,ramesh@example.com"),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            // The detected header goes back so a client can render a mapping
            // screen instead of making the user guess what went wrong.
            ->assertJsonPath('data.detected_header', ['Full Name', 'Email']);

        $this->assertSame(0, LeadImport::count());
        // A file we will never process must not be left holding PII on disk.
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function a_file_with_a_header_but_no_rows_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', ['file' => $this->csv("name,phone\n")])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, LeadImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function a_file_that_is_not_readable_as_csv_is_rejected_with_a_usable_message(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', ['file' => $this->csv('', 'empty.csv')])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, LeadImport::count());
    }

    #[Test]
    public function a_semicolon_delimited_file_with_a_bom_is_read_correctly(): void
    {
        $this->actingAsRole(RoleName::Manager);

        // What Excel produces on a European locale: a UTF-8 BOM in front of
        // the first header and semicolons throughout. Without BOM stripping,
        // "Name" never matches and the file looks like it has no name column.
        $content = "\xEF\xBB\xBFName;Mobile;City\nRamesh Kumar;9876543210;Jaipur";

        $this->postJson('/api/v1/leads/import', ['file' => $this->csv($content)])
            ->assertStatus(202);

        $this->assertSame('Name', $this->import()->column_map['name']);
        $this->assertDatabaseHas('leads', ['name' => 'Ramesh Kumar', 'phone_e164' => '+919876543210']);
    }

    #[Test]
    public function a_short_row_is_padded_rather_than_dropped(): void
    {
        $this->actingAsRole(RoleName::Manager);

        // The trailing city column is missing on row 1 - extremely common in
        // hand-edited files, and not a reason to lose the lead.
        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("name,phone,city\nRamesh Kumar,9876543210\nSunita Devi,9876543211,Kota"),
        ])->assertStatus(202);

        $this->assertSame(2, $this->import()->imported_rows);
        $this->assertDatabaseHas('leads', ['phone_e164' => '+919876543210', 'city' => null]);
    }

    // -----------------------------------------------------------------------
    // Import-wide options
    // -----------------------------------------------------------------------

    #[Test]
    public function import_options_are_applied_to_every_created_lead(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $source = LeadSource::create(['code' => 'trade_show', 'name' => 'Trade show', 'category' => 'offline']);
        $tag = Tag::create(['name' => 'Expo 2026', 'slug' => 'expo-2026', 'color' => '#ff0000']);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->goodFile(),
            'lead_source_id' => $source->id,
            'tag_ids' => [$tag->id],
        ])->assertStatus(202);

        $this->assertSame(3, Lead::where('lead_source_id', $source->id)->count());
        $this->assertSame(3, $tag->leads()->count());
    }

    #[Test]
    public function a_note_column_becomes_a_lead_note(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("name,phone,remarks\nRamesh Kumar,9876543210,Asked about pricing"),
        ])->assertStatus(202);

        $lead = Lead::where('phone_e164', '+919876543210')->firstOrFail();

        $this->assertDatabaseHas('lead_notes', [
            'lead_id' => $lead->id,
            'body' => 'Asked about pricing',
        ]);
    }

    #[Test]
    public function status_and_score_cannot_be_set_from_a_file(): void
    {
        $this->actingAsRole(RoleName::Manager);

        // A file that could set status=converted would walk straight past the
        // transition matrix and every rule attached to it (SEC-IN-06,
        // BR-STAT-02). Unknown columns are simply not mapped.
        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("name,phone,status,score,is_suppressed\nRamesh Kumar,9876543210,converted,99,1"),
        ])->assertStatus(202);

        $lead = Lead::where('phone_e164', '+919876543210')->firstOrFail();

        $this->assertSame('new', $lead->status->value);
        $this->assertSame(0, $lead->score);
        $this->assertFalse($lead->is_suppressed);
    }

    // -----------------------------------------------------------------------
    // Permissions and scoping
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_cannot_import_leads(): void
    {
        $this->actingAsRole(RoleName::Telecaller);

        // Telecallers create leads one at a time; bulk intake is supervisory.
        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])
            ->assertStatus(403);

        $this->assertSame(0, LeadImport::count());
    }

    #[Test]
    public function auto_assignment_during_import_requires_the_assign_permission(): void
    {
        // Manager holds both leads.import and leads.assign, so build a user
        // that can import but not assign to prove the gate is the permission
        // and not the role.
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Manager->value)->first());
        $user->roles()->first()->permissions()->detach(
            Permission::where('name', 'leads.assign')->first()
        );
        $this->actingAs($user->fresh(), 'sanctum');

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->goodFile(),
            'auto_assign' => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'auto_assign');

        $this->assertSame(0, LeadImport::count());
    }

    #[Test]
    public function an_import_report_is_not_readable_by_another_user(): void
    {
        $team = Team::factory()->create();
        $owner = $this->actingAsRole(RoleName::Manager, $team);

        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);
        $import = $this->import();

        // Same role, same team - and still refused. The rejected rows in a
        // report are somebody else's uploaded PII (SEC-AUTHZ-04).
        $other = $this->actingAsRole(RoleName::Manager, $team);
        $this->assertNotSame($owner->id, $other->id);

        $this->getJson("/api/v1/leads/imports/{$import->id}")->assertStatus(403);
        $this->getJson("/api/v1/leads/imports/{$import->id}/rows")->assertStatus(403);

        // ...and it does not appear in their list either, or the list would
        // leak what the record endpoint refuses.
        $this->getJson('/api/v1/leads/imports')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    #[Test]
    public function an_admin_can_read_any_import(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);
        $import = $this->import();

        $this->actingAsRole(RoleName::Admin);

        $this->getJson("/api/v1/leads/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id);
    }

    // -----------------------------------------------------------------------
    // The report
    // -----------------------------------------------------------------------

    #[Test]
    public function the_row_report_can_be_filtered_to_the_failures(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv(<<<'CSV'
            name,phone
            Ramesh Kumar,9876543210
            Bad Number,12345
            Sunita Devi,9876543211
            CSV),
        ])->assertStatus(202);

        $import = $this->import();

        // The only question worth asking of a large import.
        $this->getJson("/api/v1/leads/imports/{$import->id}/rows?filter[status]=invalid")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.row_number', 2)
            ->assertJsonPath('data.items.0.status', 'invalid');

        $this->getJson("/api/v1/leads/imports/{$import->id}/rows")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3);
    }

    #[Test]
    public function an_unknown_filter_on_the_report_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);

        $this->getJson("/api/v1/leads/imports/{$this->import()->id}/rows?filter[nonsense]=1")
            ->assertStatus(422);
    }

    #[Test]
    public function the_import_list_shows_the_users_own_uploads_newest_first(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', ['file' => $this->csv("name,phone\nA,9876543210", 'first.csv')]);
        $this->postJson('/api/v1/leads/import', ['file' => $this->csv("name,phone\nB,9876543211", 'second.csv')]);

        $this->getJson('/api/v1/leads/imports')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.items.0.filename', 'second.csv');
    }

    // -----------------------------------------------------------------------
    // Idempotency and retention
    // -----------------------------------------------------------------------

    #[Test]
    public function reprocessing_a_row_does_not_create_a_second_lead_or_double_count(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);

        $import = $this->import();
        $service = app(LeadImportService::class);

        // Simulates a row job that succeeded but failed to acknowledge and was
        // redelivered. Without the (import, row) unique key this would either
        // duplicate the lead or corrupt the counters.
        $service->importRow($import, 1, ['name' => 'Ramesh Kumar', 'phone' => '9876543210']);

        $import->refresh();

        $this->assertSame(3, $import->imported_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(3, Lead::count());
        $this->assertSame(3, $import->rows()->count());
    }

    #[Test]
    public function purging_deletes_the_file_and_the_rejected_row_data_but_keeps_the_report(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->postJson('/api/v1/leads/import', [
            'file' => $this->csv("name,phone\nRamesh Kumar,9876543210\nBad Number,12345"),
        ])->assertStatus(202);

        $import = $this->import();
        $path = $import->stored_path;

        // Finished 40 days ago, retention is 30.
        $import->forceFill(['finished_at' => now()->subDays(40)])->save();

        $this->artisan('leads:purge-import-files')->assertExitCode(0);

        Storage::disk('local')->assertMissing($path);

        $import->refresh();

        // The file goes; the audit trail stays (SEC-PII-05).
        $this->assertNull($import->stored_path);
        $this->assertSame(1, $import->imported_rows);
        $this->assertSame(1, $import->invalid_rows);
        $this->assertSame(2, $import->rows()->count());
        // ...but the rejected row's raw PII goes with the file.
        $this->assertNull($import->rows()->where('row_number', 2)->first()->data);
    }

    #[Test]
    public function a_recent_import_is_not_purged(): void
    {
        $this->actingAsRole(RoleName::Manager);
        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);

        $this->artisan('leads:purge-import-files')->assertExitCode(0);

        Storage::disk('local')->assertExists($this->import()->stored_path);
    }

    #[Test]
    public function an_import_whose_file_has_vanished_fails_cleanly_instead_of_hanging(): void
    {
        Queue::fake();

        $this->actingAsRole(RoleName::Manager);
        $this->postJson('/api/v1/leads/import', ['file' => $this->goodFile()])->assertStatus(202);

        $import = $this->import();
        Storage::disk('local')->delete($import->stored_path);

        app(LeadImportService::class)->dispatchRows($import);

        $import->refresh();

        // An import stuck at `processing` forever is worse than a failed one:
        // nobody can tell it from a slow file.
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->failure_reason);
        $this->assertNotNull($import->finished_at);
    }
}
