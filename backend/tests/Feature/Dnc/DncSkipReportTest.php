<?php

namespace Tests\Feature\Dnc;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\Reports\SuppressionReportService;
use App\Support\Reporting\ReportPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suppression / skip reporting (Phase 19, BR-DNC-05, FR-DNC-03).
 *
 * A send refused because a lead is suppressed must be visible, not a silent
 * nothing. These tests prove the aggregate counts, that the two skip reasons
 * stay distinct (BR-DNC-03's post-queue window is not collapsed into the
 * pre-queue skip), and that lifted suppressions leave the active snapshot.
 */
class DncSkipReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());
        $this->actingAs($user->fresh(), 'sanctum');

        return $user->fresh();
    }

    private function reports(): SuppressionReportService
    {
        return app(SuppressionReportService::class);
    }

    private function thisMonth(): ReportPeriod
    {
        return ReportPeriod::between(Carbon::now()->startOfMonth(), Carbon::now()->endOfDay());
    }

    private function skip(Channel $channel, string $reason, ?Carbon $at = null): Message
    {
        return Message::factory()->for(Lead::factory())->create([
            'channel' => $channel->value,
            'status' => 'skipped',
            'skip_reason' => $reason,
            'created_at' => $at ?? now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Skips (period-scoped events)
    // -----------------------------------------------------------------------

    #[Test]
    public function it_counts_skips_by_channel_and_reason(): void
    {
        $this->skip(Channel::Sms, 'suppressed');
        $this->skip(Channel::Sms, 'suppressed');
        $this->skip(Channel::WhatsApp, 'suppressed_after_queueing');

        // Not a skip - a delivered send must not inflate the refusal count.
        Message::factory()->for(Lead::factory())->create([
            'channel' => Channel::Sms->value, 'status' => 'sent',
        ]);

        // A real skip, but last quarter - out of the window.
        $this->skip(Channel::Sms, 'suppressed', Carbon::now()->subMonths(3));

        $skips = $this->reports()->report($this->thisMonth())['skips'];

        $this->assertSame(3, $skips['total']);

        $byChannel = collect($skips['by_channel'])->keyBy('channel');
        $this->assertSame(2, $byChannel['sms']['total']);
        $this->assertSame(1, $byChannel['whatsapp']['total']);
        $this->assertSame('WhatsApp', $byChannel['whatsapp']['channel_label']);

        // The two reasons stay distinct: 'suppressed' (refused at queue time) and
        // 'suppressed_after_queueing' (BR-DNC-03: opted out while queued) are
        // different events and collapsing them would hide the window.
        $byReason = collect($skips['by_reason'])->keyBy('reason');
        $this->assertSame(2, $byReason['suppressed']['total']);
        $this->assertSame(1, $byReason['suppressed_after_queueing']['total']);
    }

    #[Test]
    public function a_quiet_period_reports_zero_not_an_error(): void
    {
        $report = $this->reports()->report($this->thisMonth());

        $this->assertSame(0, $report['skips']['total']);
        $this->assertSame([], $report['skips']['by_channel']);
        $this->assertSame(0, $report['active_suppressions']['total']);
    }

    // -----------------------------------------------------------------------
    // Active suppressions (a snapshot, never period-scoped)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_active_snapshot_counts_only_live_suppressions(): void
    {
        DncEntry::factory()->for(Lead::factory())->reason(DncReason::DoNotContact)->create();
        DncEntry::factory()->for(Lead::factory())->reason(DncReason::DoNotContact)->create();
        DncEntry::factory()->for(Lead::factory())->reason(DncReason::OptedOut)->create();

        // Lifted, but kept for the audit trail - it must not be counted as though
        // the person were still on the list.
        DncEntry::factory()->for(Lead::factory())->reason(DncReason::DoNotContact)->create(['active' => false]);

        $active = $this->reports()->report($this->thisMonth())['active_suppressions'];

        $this->assertSame(3, $active['total']);

        $byReason = collect($active['by_reason'])->keyBy('reason');
        $this->assertSame(2, $byReason['do_not_contact']['total']);
        $this->assertSame(1, $byReason['opted_out']['total']);
    }

    // -----------------------------------------------------------------------
    // The endpoint
    // -----------------------------------------------------------------------

    #[Test]
    public function the_endpoint_is_gated_on_dnc_view(): void
    {
        $this->skip(Channel::Sms, 'suppressed');
        $this->actingAsRole(RoleName::Manager);

        $this->getJson('/api/v1/dnc/skips')
            ->assertOk()
            ->assertJsonPath('data.report.skips.total', 1)
            ->assertJsonStructure(['data' => ['period', 'report' => ['skips' => ['total', 'by_channel', 'by_reason'], 'active_suppressions']]]);
    }

    #[Test]
    public function a_role_without_dnc_view_cannot_open_the_report(): void
    {
        // Accounts is read-only on leads and holds no DNC permission at all.
        $this->actingAsRole(RoleName::Accounts);

        $this->getJson('/api/v1/dnc/skips')->assertStatus(403);
    }
}
