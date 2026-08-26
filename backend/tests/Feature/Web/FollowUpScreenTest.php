<?php

namespace Tests\Feature\Web;

use App\Enums\DataScope;
use App\Enums\FollowUpStatus;
use App\Enums\Permission as PermissionEnum;
use App\Enums\RoleName;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cross-lead follow-up queue (FR-FUP-04).
 *
 * A shell like every other page (ADR-A), so what is worth asserting is who can
 * reach it and which controls it draws - plus the one thing the markup alone
 * cannot show: that the window the screen asks the API for really is "due today
 * and overdue". A queue that quietly listed next month's reminders would look
 * identical in the HTML and be useless to the person working it.
 */
class FollowUpScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // The whole screen is a statement about "now": it stamps the server's
        // day boundary into the page and colours rows by it. Run at 23:59 on an
        // unfrozen clock, the boundary moves mid-test - a test that can flake on
        // the wall clock is a bug.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function user(RoleName $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    /**
     * Someone who may READ the queue but not work it.
     *
     * No seeded role is shaped that way - every role holding `follow_ups.view`
     * also holds `follow_ups.manage` - so the negative case needs a role built
     * for it, or "the control is hidden when you cannot use it" goes untested.
     */
    private function readOnlyFollowUpUser(): User
    {
        $role = Role::create([
            'tenant_id' => 0,
            'name' => 'follow_up_reader',
            'label' => 'Follow-up Reader',
            'data_scope' => DataScope::All->value,
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::where('name', PermissionEnum::FollowUpsView->value)->first(),
        );

        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user->fresh();
    }

    // -----------------------------------------------------------------------
    // Reaching the screen
    // -----------------------------------------------------------------------

    #[Test]
    public function the_follow_up_queue_renders_for_a_telecaller(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/follow-ups')
            ->assertOk()
            ->assertSee('Due today and overdue')
            ->assertSee('id="followup-rows"', false)
            // ADR-A: the rows come from the API, not from the view.
            ->assertSee('/api/v1/follow-ups', false);
    }

    #[Test]
    public function a_role_without_follow_ups_view_cannot_open_the_queue(): void
    {
        // Accounts is read-only on leads and holds no follow-up permission.
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/follow-ups')
            ->assertStatus(403);
    }

    #[Test]
    public function the_follow_ups_nav_link_is_shown_to_a_telecaller(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/follow-ups')
            ->assertOk()
            ->assertSee('/follow-ups"', false);
    }

    #[Test]
    public function the_follow_ups_nav_link_is_hidden_from_a_role_without_the_permission(): void
    {
        $this->actingAs($this->user(RoleName::Accounts))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/follow-ups"', false);
    }

    // -----------------------------------------------------------------------
    // The write controls
    // -----------------------------------------------------------------------

    #[Test]
    public function the_queue_draws_the_write_controls_for_a_holder_of_follow_ups_manage(): void
    {
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/follow-ups')
            ->assertOk()
            ->assertSee('Reschedule follow-up')
            ->assertSee('id="reschedule-modal"', false)
            ->assertSee('id="cancel-modal"', false);
    }

    #[Test]
    public function the_write_controls_are_hidden_from_a_user_who_may_only_read_the_queue(): void
    {
        $this->actingAs($this->readOnlyFollowUpUser())
            ->get('/follow-ups')
            ->assertOk()
            // The list itself is still there - only the ways to change it go.
            ->assertSee('id="followup-rows"', false)
            ->assertDontSee('Reschedule follow-up')
            ->assertDontSee('Cancel follow-up')
            ->assertDontSee('id="reschedule-modal"', false)
            ->assertDontSee('id="cancel-modal"', false);
    }

    #[Test]
    public function the_screen_stamps_the_servers_own_day_boundary_into_the_page(): void
    {
        // The browser must not decide where "today" ends: the API filters on the
        // server clock, so a machine an hour out would colour rows by a window
        // the query never used.
        $this->actingAs($this->user(RoleName::Telecaller))
            ->get('/follow-ups')
            ->assertOk()
            ->assertSee('2026-08-15 23:59:59', false)
            ->assertSee('2026-08-15 00:00:00', false);
    }

    // -----------------------------------------------------------------------
    // The window the screen actually asks for
    // -----------------------------------------------------------------------

    #[Test]
    public function the_default_query_returns_todays_work_and_everything_already_late(): void
    {
        $me = $this->user(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        $lead = Lead::factory()->create(['assigned_to' => $me->id, 'name' => 'Due Today Devi']);

        FollowUp::factory()->for($lead)->create([
            'assigned_to' => $me->id,
            'scheduled_at' => Carbon::parse('2026-08-14 09:00:00'),
            'status' => FollowUpStatus::Open->value,
        ]);
        FollowUp::factory()->for($lead)->create([
            'assigned_to' => $me->id,
            'scheduled_at' => Carbon::parse('2026-08-15 16:00:00'),
            'status' => FollowUpStatus::Open->value,
        ]);

        // Next week is not today's work.
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $me->id,
            'scheduled_at' => Carbon::parse('2026-08-20 09:00:00'),
            'status' => FollowUpStatus::Open->value,
        ]);

        // Already dealt with, and somebody else's book: neither is mine to do.
        FollowUp::factory()->for($lead)->create([
            'assigned_to' => $me->id,
            'scheduled_at' => Carbon::parse('2026-08-13 09:00:00'),
            'status' => FollowUpStatus::Completed->value,
        ]);
        FollowUp::factory()->for(Lead::factory())->create([
            'assigned_to' => $colleague->id,
            'scheduled_at' => Carbon::parse('2026-08-14 09:00:00'),
            'status' => FollowUpStatus::Open->value,
        ]);

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/follow-ups?filter[scheduled_at][lte]='.urlencode('2026-08-15 23:59:59'))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            // Soonest first, so the late one is the first thing you see.
            ->assertJsonPath('data.items.0.is_overdue', true)
            ->assertJsonPath('data.items.1.is_overdue', false);
    }

    #[Test]
    public function a_missed_follow_up_stays_in_the_queue(): void
    {
        $me = $this->user(RoleName::Telecaller);

        // BR-FUP-02: missed is late, not void. Dropping it from the working list
        // is how a commitment quietly disappears.
        FollowUp::factory()->for(Lead::factory())->missed()->create(['assigned_to' => $me->id]);

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/follow-ups?filter[scheduled_at][lte]='.urlencode('2026-08-15 23:59:59'))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.status', 'missed');
    }

    #[Test]
    public function an_unsupported_filter_on_the_queue_is_still_rejected(): void
    {
        // Widening the allowlist for `scheduled_at` must not have opened it up:
        // a silently dropped filter shows the caller more than they asked for.
        $this->actingAs($this->user(RoleName::Telecaller), 'sanctum')
            ->getJson('/api/v1/follow-ups?filter[assigned_to]=1')
            ->assertStatus(422);
    }
}
