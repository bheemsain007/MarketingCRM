<?php

namespace Tests\Feature\Calls;

use App\Enums\CallStatus;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\Lead;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-lead call history — GET /api/v1/calls (FR-CALL-04/05).
 *
 * The per-lead endpoint is scoped by the lead you asked for, so it cannot leak
 * across books. This one has no lead to lean on: the ONLY thing standing
 * between a telecaller and every call in the organisation is the data scope
 * (SEC-AUTHZ-03). That is what most of this file is about — the pagination
 * envelope and the filters are the easy half.
 */
class CallHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // The date filters below select on `started_at`, so "yesterday" and
        // "last month" must not move with the calendar the suite runs on.
        Carbon::setTestNow(Carbon::parse('2026-08-20 11:00:00', 'Asia/Kolkata'));
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

    /** @param array<string, mixed> $attributes */
    private function callBy(User $caller, array $attributes = []): Call
    {
        return Call::factory()->create(array_merge([
            'lead_id' => Lead::factory()->create(['assigned_to' => $caller->id])->id,
            'user_id' => $caller->id,
        ], $attributes));
    }

    // -----------------------------------------------------------------------
    // Envelope and pagination
    // -----------------------------------------------------------------------

    #[Test]
    public function the_history_returns_the_standard_envelope_with_the_lead_and_the_caller(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id, 'name' => 'Ramesh Kumar']);

        $this->callBy($telecaller, [
            'lead_id' => $lead->id,
            'status' => CallStatus::Connected->value,
            'duration_seconds' => 143,
            'notes' => 'Asked for a quote.',
        ]);

        $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/calls')
            ->assertOk()
            ->assertJsonStructure([
                'success', 'message', 'errors',
                'data' => [
                    'items' => [['id', 'lead_id', 'status', 'status_label', 'duration_seconds', 'notes', 'user', 'lead']],
                    'meta' => ['total', 'current_page', 'last_page'],
                ],
            ])
            ->assertJsonPath('success', true)
            // The screen names the lead per row; an id alone is unreadable.
            ->assertJsonPath('data.items.0.lead.name', 'Ramesh Kumar')
            ->assertJsonPath('data.items.0.user.name', $telecaller->name)
            ->assertJsonPath('data.items.0.duration_seconds', 143);
    }

    #[Test]
    public function the_history_paginates_and_reports_the_totals(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        for ($i = 0; $i < 5; $i++) {
            $this->callBy($telecaller, ['started_at' => now()->subMinutes($i)]);
        }

        $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/calls?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.total', 5)
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.last_page', 3);
    }

    #[Test]
    public function the_history_is_newest_first_without_being_asked(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $older = $this->callBy($telecaller, ['started_at' => now()->subDays(3)]);
        $newer = $this->callBy($telecaller, ['started_at' => now()->subHour()]);

        $items = $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/calls')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$newer->id, $older->id], array_column($items, 'id'));
    }

    // -----------------------------------------------------------------------
    // Filters — the ones the screen exposes
    // -----------------------------------------------------------------------

    #[Test]
    public function the_history_filters_by_outcome(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $connected = $this->callBy($telecaller, ['status' => CallStatus::Connected->value]);
        $this->callBy($telecaller, ['status' => CallStatus::Busy->value]);

        $items = $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/calls?filter[status]=connected')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$connected->id], array_column($items, 'id'));
    }

    #[Test]
    public function the_history_filters_by_dial_source(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $this->callBy($telecaller, ['dial_source' => 'manual']);
        $dialled = $this->callBy($telecaller, ['dial_source' => 'auto_dialer']);

        $items = $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/calls?filter[dial_source]=auto_dialer')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$dialled->id], array_column($items, 'id'));
    }

    #[Test]
    public function the_history_filters_to_a_date_range(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $this->callBy($telecaller, ['started_at' => Carbon::parse('2026-08-01 09:30:00')]);
        $inRange = $this->callBy($telecaller, ['started_at' => Carbon::parse('2026-08-19 23:40:00')]);

        // The screen sends the day's whole span, because `started_at` is a
        // timestamp and a bare date would drop everything after midnight.
        $items = $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/calls?filter[started_at][between]=2026-08-15 00:00:00,2026-08-19 23:59:59')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$inRange->id], array_column($items, 'id'));
    }

    #[Test]
    public function an_unsupported_filter_is_refused_rather_than_ignored(): void
    {
        // A silently dropped filter on a scoped list shows MORE than was asked
        // for, so the endpoint must 422 (QueryOptions).
        $this->actingAs($this->user(RoleName::Telecaller), 'sanctum')
            ->getJson('/api/v1/calls?filter[tenant_id]=0')
            ->assertStatus(422);
    }

    #[Test]
    public function the_history_can_be_sorted_by_talk_time(): void
    {
        $telecaller = $this->user(RoleName::Telecaller);

        $short = $this->callBy($telecaller, ['duration_seconds' => 30]);
        $long = $this->callBy($telecaller, ['duration_seconds' => 900]);

        $items = $this->actingAs($telecaller, 'sanctum')
            ->getJson('/api/v1/calls?sort=-duration_seconds')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([$long->id, $short->id], array_column($items, 'id'));
    }

    // -----------------------------------------------------------------------
    // Data scope (SEC-AUTHZ-03) — the reason this endpoint is risky
    // -----------------------------------------------------------------------

    #[Test]
    public function a_telecaller_sees_only_the_calls_they_made(): void
    {
        $mine = $this->user(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        $ownCall = $this->callBy($mine);
        $this->callBy($colleague, ['notes' => 'A conversation that is not mine.']);

        $response = $this->actingAs($mine, 'sanctum')
            ->getJson('/api/v1/calls')
            ->assertOk();

        $this->assertSame([$ownCall->id], array_column($response->json('data.items'), 'id'));

        // The total is part of the leak: a count that includes rows you cannot
        // see still tells you how busy somebody else was.
        $this->assertSame(1, $response->json('data.meta.total'));

        // Notes carry what the lead actually said, so the body of the response
        // is checked too and not just the ids.
        $response->assertDontSee('A conversation that is not mine.', false);
    }

    #[Test]
    public function a_telecaller_cannot_reach_another_callers_rows_by_asking_for_them(): void
    {
        $mine = $this->user(RoleName::Telecaller);
        $colleague = $this->user(RoleName::Telecaller);

        $this->callBy($colleague);

        // `user_id` is a supported filter, so the obvious probe is a filter for
        // somebody else. The scope is applied to the query regardless, so this
        // is an empty list rather than a leak.
        $this->actingAs($mine, 'sanctum')
            ->getJson("/api/v1/calls?filter[user_id]={$colleague->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.meta.total', 0);
    }

    #[Test]
    public function a_manager_sees_their_teams_calls_and_not_another_teams(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();

        $manager = $this->user(RoleName::Manager, ['team_id' => $team->id]);
        $teammate = $this->user(RoleName::Telecaller, ['team_id' => $team->id]);
        $outsider = $this->user(RoleName::Telecaller, ['team_id' => $otherTeam->id]);

        $teamCall = $this->callBy($teammate);
        $this->callBy($outsider);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/calls')
            ->assertOk();

        $this->assertSame([$teamCall->id], array_column($response->json('data.items'), 'id'));
        $this->assertSame(1, $response->json('data.meta.total'));
    }

    #[Test]
    public function a_full_scope_role_sees_every_callers_history(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();

        $one = $this->user(RoleName::Telecaller, ['team_id' => $team->id]);
        $two = $this->user(RoleName::Telecaller, ['team_id' => $otherTeam->id]);

        $first = $this->callBy($one);
        $second = $this->callBy($two);

        $items = $this->actingAs($this->user(RoleName::Admin), 'sanctum')
            ->getJson('/api/v1/calls')
            ->assertOk()
            ->json('data.items');

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column($items, 'id'),
        );
    }

    #[Test]
    public function a_role_without_calls_view_is_refused(): void
    {
        // Accounts works on payments and holds no call permission at all.
        $this->actingAs($this->user(RoleName::Accounts), 'sanctum')
            ->getJson('/api/v1/calls')
            ->assertStatus(403);
    }

    #[Test]
    public function the_history_requires_authentication(): void
    {
        $this->getJson('/api/v1/calls')->assertStatus(401);
    }
}
