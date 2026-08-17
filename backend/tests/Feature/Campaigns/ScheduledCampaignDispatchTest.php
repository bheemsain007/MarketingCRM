<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Jobs\DispatchCampaign;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Lead;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Scheduled campaigns actually launch (FR-CAMP-02, BR-CAMP-03/05).
 *
 * Scheduling used to be half-built: a campaign could be created with a future
 * `scheduled_at` and status `scheduled`, and nothing ever picked it up. It sat
 * there for ever while every screen said it was scheduled - the worst shape of
 * failure, because it looks exactly like success until the day somebody asks
 * why the offer never went out.
 *
 * Time is frozen throughout. "Its time has come" is the entire subject of these
 * tests, so a wall-clock that moved between arranging and asserting would be
 * testing something else.
 */
class ScheduledCampaignDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scheduledCampaign(?Carbon $at, array $attributes = []): Campaign
    {
        return Campaign::create(array_merge([
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => 'Independence week offer',
            'channel' => Channel::Email->value,
            'status' => CampaignStatus::Scheduled->value,
            'audience_filters' => [],
            'scheduled_at' => $at,
        ], $attributes));
    }

    #[Test]
    public function a_campaign_whose_scheduled_time_has_passed_is_started_by_the_sweep(): void
    {
        Bus::fake();
        Lead::factory()->create(['email' => 'reachable@example.com']);

        // Scheduled for one minute ago: the ordinary case of a per-minute sweep
        // arriving just after the moment the operator chose.
        $campaign = $this->scheduledCampaign(now()->subMinute());

        $this->artisan('crm:dispatch-scheduled-campaigns')->assertSuccessful();

        $campaign->refresh();

        // Running, started, and with an audience - anything less means the
        // scheduled time passed and the campaign is still only a promise.
        $this->assertSame(CampaignStatus::Running, $campaign->status);
        $this->assertNotNull($campaign->started_at);
        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->count());

        // BR-CAMP-03: the sweep queues the fan-out, it never sends inline.
        Bus::assertDispatched(DispatchCampaign::class);
    }

    #[Test]
    public function a_campaign_scheduled_for_later_is_left_alone(): void
    {
        Bus::fake();
        $campaign = $this->scheduledCampaign(now()->addHour());

        $this->artisan('crm:dispatch-scheduled-campaigns')->assertSuccessful();

        // Starting early is not a smaller mistake than starting late: the
        // operator picked that hour for a reason a sweep knows nothing about.
        $this->assertSame(CampaignStatus::Scheduled, $campaign->refresh()->status);
        Bus::assertNotDispatched(DispatchCampaign::class);
    }

    #[Test]
    public function a_campaign_already_running_is_never_re_dispatched(): void
    {
        Bus::fake();

        // The shape a backlog after downtime produces: a due timestamp still on
        // the row of a campaign that is already under way. Re-dispatching it
        // would fan the same audience out twice.
        $campaign = $this->scheduledCampaign(now()->subHour(), [
            'status' => CampaignStatus::Running->value,
        ]);

        $this->artisan('crm:dispatch-scheduled-campaigns')->assertSuccessful();

        Bus::assertNotDispatched(DispatchCampaign::class);
    }

    #[Test]
    public function a_stopped_campaign_with_a_past_schedule_is_never_resurrected(): void
    {
        Bus::fake();

        // Stop is terminal (BR-CAMP-05). A scheduler that restarted a campaign
        // somebody deliberately stopped would make "stop" meaningless.
        $campaign = $this->scheduledCampaign(now()->subDay(), [
            'status' => CampaignStatus::Stopped->value,
        ]);

        $this->artisan('crm:dispatch-scheduled-campaigns')->assertSuccessful();

        $this->assertSame(CampaignStatus::Stopped, $campaign->refresh()->status);
        Bus::assertNotDispatched(DispatchCampaign::class);
    }

    #[Test]
    public function the_sweep_starts_each_due_campaign_exactly_once_across_consecutive_ticks(): void
    {
        Bus::fake();
        Lead::factory()->create(['email' => 'reachable@example.com']);
        $campaign = $this->scheduledCampaign(now()->subMinute());

        // Two ticks in a row - what the scheduler does every minute of the day.
        $this->artisan('crm:dispatch-scheduled-campaigns')->assertSuccessful();
        $this->artisan('crm:dispatch-scheduled-campaigns')->assertSuccessful();

        // Once. The status filter is what makes the second tick a no-op, so a
        // campaign is not fanned out again every minute until it completes.
        Bus::assertDispatchedTimes(DispatchCampaign::class, 1);
        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->count());
    }

    #[Test]
    public function every_due_campaign_in_one_tick_is_started_not_just_the_first(): void
    {
        Bus::fake();
        Lead::factory()->create(['email' => 'reachable@example.com']);

        // The backlog case: the scheduler was down over two send windows. Both
        // campaigns belong to somebody, and a sweep that starts only the oldest
        // leaves the second silently unsent all over again.
        $first = $this->scheduledCampaign(now()->subHours(2), ['name' => 'Morning offer']);
        $second = $this->scheduledCampaign(now()->subMinute(), ['name' => 'Second in line']);

        $this->artisan('crm:dispatch-scheduled-campaigns')->assertSuccessful();

        $this->assertSame(CampaignStatus::Running, $first->refresh()->status);
        $this->assertSame(CampaignStatus::Running, $second->refresh()->status);
        Bus::assertDispatchedTimes(DispatchCampaign::class, 2);
    }

    #[Test]
    public function a_dry_run_reports_what_would_start_without_starting_it(): void
    {
        Bus::fake();
        $campaign = $this->scheduledCampaign(now()->subMinute());

        // The rehearsal an operator wants before trusting a sweep with twelve
        // thousand sends: it must be genuinely read-only.
        $this->artisan('crm:dispatch-scheduled-campaigns --dry-run')->assertSuccessful();

        $this->assertSame(CampaignStatus::Scheduled, $campaign->refresh()->status);
        Bus::assertNotDispatched(DispatchCampaign::class);
    }

    #[Test]
    public function the_sweep_is_registered_on_the_scheduler_every_minute_without_overlapping(): void
    {
        // Without a scheduler entry the command exists and never runs, which is
        // the same silent failure the bug had in the first place.
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command ?? '', 'crm:dispatch-scheduled-campaigns'));

        $this->assertNotNull($event, 'crm:dispatch-scheduled-campaigns is not on the schedule.');

        // Per minute: a campaign scheduled for 09:00 that starts at 09:59 has
        // already missed the reason somebody chose 09:00.
        $this->assertSame('* * * * *', $event->expression);

        // withoutOverlapping: after downtime this faces a backlog, and two
        // overlapping runs could hand the same campaign to the queue twice.
        $this->assertTrue($event->withoutOverlapping);
    }
}
