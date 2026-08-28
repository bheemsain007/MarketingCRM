<?php

namespace Tests\Feature\Ops;

use App\Enums\ExportStatus;
use App\Enums\RoleName;
use App\Models\Lead;
use App\Models\LeadExport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Retention on generated lead export files (SEC-PII-05, FR-LEAD-12).
 *
 * The export file is the lead database in plain text, so the fact under test is
 * narrow and total: after the download window closes the BYTES are gone from
 * disk, and the row that says who exported what is not.
 *
 * The clock is frozen throughout - "expired" is `expires_at` against `now()`,
 * so a real wall clock would make every assertion here a race - and the tests
 * move the frozen instant forward rather than back-dating `expires_at` by hand,
 * which keeps the actual retention arithmetic (config days added at completion)
 * inside what is being tested.
 */
class ExportPurgeTest extends TestCase
{
    use RefreshDatabase;

    /** Any fixed instant; nothing here depends on the date itself. */
    private const NOW = '2026-03-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(self::NOW);

        // The generated CSV never touches the real filesystem (mirrors
        // LeadExportTest).
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * One completed export, generated the way a real one is.
     *
     * Requested through the API rather than built with a factory, because the
     * thing being purged is a file the JOB wrote - a hand-made row pointing at
     * a path nothing created would pass this suite while the sweep deleted
     * nothing. The suite runs on QUEUE_CONNECTION=sync, so it is finished by
     * the time the request returns.
     */
    private function completedExport(): LeadExport
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Manager->value)->first());

        Lead::factory()->count(2)->create();

        $this->actingAs($user->fresh(), 'sanctum')
            ->postJson('/api/v1/leads/export', [])
            ->assertStatus(202);

        $export = LeadExport::latest('id')->firstOrFail();

        $this->assertSame(ExportStatus::Completed, $export->status);
        $this->assertNotNull($export->file_path);
        Storage::disk('local')->assertExists($export->file_path);

        return $export;
    }

    private function retentionDays(): int
    {
        return max(1, (int) config('crm.exports.retention_days'));
    }

    // -----------------------------------------------------------------------
    // The sweep
    // -----------------------------------------------------------------------

    #[Test]
    public function an_export_past_its_download_window_loses_its_file_and_keeps_its_record(): void
    {
        $export = $this->completedExport();
        $path = $export->file_path;

        // The window the requester was actually promised at completion time.
        $this->assertTrue($export->expires_at->equalTo(Carbon::parse(self::NOW)->addDays($this->retentionDays())));

        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays($this->retentionDays() + 1));

        $this->artisan('leads:purge-export-files')
            ->expectsOutputToContain('1 export file(s) purged.')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing($path);

        $export->refresh();

        // The bytes go; the audit trail - who exported which filters, when, and
        // how many rows came out - stays (SEC-PII-05).
        $this->assertNull($export->file_path);
        $this->assertTrue($export->fileIsGone());
        $this->assertSame(ExportStatus::Completed, $export->status);
        $this->assertSame(2, $export->row_count);
        $this->assertNotNull($export->requested_at);
        $this->assertNotNull($export->completed_at);
        $this->assertDatabaseHas('lead_exports', ['id' => $export->id, 'file_path' => null]);
    }

    #[Test]
    public function an_export_still_inside_its_download_window_is_left_alone(): void
    {
        $export = $this->completedExport();

        // One day short of expiry - a sweep that deleted this would be taking a
        // file away from someone still entitled to download it.
        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays($this->retentionDays() - 1));

        $this->artisan('leads:purge-export-files')
            ->expectsOutputToContain('0 export file(s) purged.')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($export->file_path);
        $this->assertNotNull($export->fresh()->file_path);
    }

    #[Test]
    public function an_export_whose_window_closes_on_this_very_tick_is_purged(): void
    {
        $export = $this->completedExport();
        $path = $export->file_path;

        // The boundary: `expires_at` is the moment the download endpoint starts
        // refusing, so it is also the moment the file stops being worth keeping.
        Carbon::setTestNow($export->expires_at);

        $this->artisan('leads:purge-export-files')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($export->fresh()->file_path);
    }

    #[Test]
    public function a_dry_run_reports_what_would_go_without_deleting_anything(): void
    {
        $export = $this->completedExport();

        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays($this->retentionDays() + 1));

        $this->artisan('leads:purge-export-files --dry-run')
            ->expectsOutputToContain('[dry run] 1 export file(s) would be purged.')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($export->file_path);
        $this->assertNotNull($export->fresh()->file_path);
    }

    #[Test]
    public function a_second_sweep_finds_nothing_left_to_do(): void
    {
        $this->completedExport();

        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays($this->retentionDays() + 1));

        $this->artisan('leads:purge-export-files')->assertSuccessful();

        // An already-purged row still matches "expired" for ever after, so the
        // path check is what keeps the nightly run from re-reporting the same
        // exports (and from deleting a path that is now null).
        $this->artisan('leads:purge-export-files')
            ->expectsOutputToContain('0 export file(s) purged.')
            ->assertSuccessful();
    }

    #[Test]
    public function an_export_that_never_produced_a_file_is_not_touched(): void
    {
        // A failed run: `completed_at` set, no path, no expiry. Nothing on disk
        // to delete, and nothing about it should make the sweep fail.
        $export = LeadExport::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'user_id' => User::factory()->create()->id,
            'filters' => [],
            'requested_at' => now(),
        ]);
        $export->forceFill(['status' => ExportStatus::Failed, 'completed_at' => now()])->save();

        Carbon::setTestNow(Carbon::parse(self::NOW)->addYear());

        $this->artisan('leads:purge-export-files')
            ->expectsOutputToContain('0 export file(s) purged.')
            ->assertSuccessful();

        $this->assertSame(ExportStatus::Failed, $export->fresh()->status);
    }

    // -----------------------------------------------------------------------
    // What the read side does afterwards
    // -----------------------------------------------------------------------

    #[Test]
    public function a_purged_export_is_refused_by_the_download_endpoint_rather_than_erroring(): void
    {
        $export = $this->completedExport();
        $owner = $export->requester;

        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays($this->retentionDays() + 1));

        $this->artisan('leads:purge-export-files')->assertSuccessful();

        // 404, not a 500 over a missing file: `completed` with no path is
        // already a state the download endpoint understands, which is why the
        // purge needs no new status of its own.
        $this->actingAs($owner, 'sanctum')
            ->get("/api/v1/leads/exports/{$export->id}/download")
            ->assertStatus(404);
    }

    #[Test]
    public function a_purged_export_still_appears_in_the_history_list(): void
    {
        $export = $this->completedExport();
        $owner = $export->requester;

        Carbon::setTestNow(Carbon::parse(self::NOW)->addDays($this->retentionDays() + 1));

        $this->artisan('leads:purge-export-files')->assertSuccessful();

        // The point of keeping the row: "you exported 2 leads on the 10th and
        // the file has since been deleted" is still answerable.
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/leads/exports')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $export->id)
            ->assertJsonPath('data.items.0.status', 'completed')
            ->assertJsonPath('data.items.0.row_count', 2);
    }
}
