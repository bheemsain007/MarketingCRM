<?php

namespace Tests\Feature\Calls;

use App\Enums\DialerSkipReason;
use App\Enums\DncReason;
use App\Enums\LeadStatus;
use App\Enums\QueueItemState;
use App\Enums\RoleName;
use App\Models\AutoDialerQueueItem;
use App\Models\AutoDialerSession;
use App\Models\Call;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Calls\AutoDialerService;
use App\Services\Dnc\DncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auto dialer (FR-CALL-06/07, BR-CALL-02/03).
 *
 * The two properties worth protecting: a skip is always recorded with a
 * reason, and no two telecallers are ever handed the same lead.
 */
class AutoDialerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function telecaller(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());

        return $user->fresh();
    }

    private function actingAsTelecaller(): User
    {
        $user = $this->telecaller();
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function leadsFor(User $user, int $count = 3, array $attributes = []): void
    {
        Lead::factory()->count($count)->create(array_merge([
            'assigned_to' => $user->id,
            'timezone' => 'Asia/Kolkata',
            'status' => LeadStatus::New->value,
        ], $attributes));
    }

    // -----------------------------------------------------------------------
    // FR-CALL-06 — session lifecycle
    // -----------------------------------------------------------------------

    #[Test]
    public function starting_a_session_builds_a_queue_from_the_callers_own_leads(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 3);
        Lead::factory()->count(4)->create();     // Somebody else's book.

        $this->postJson('/api/v1/dialer/sessions')
            ->assertStatus(201)
            ->assertJsonPath('data.state', 'running')
            // SEC-AUTHZ-03: a run contains the caller's leads, not the database.
            ->assertJsonPath('data.totals.queued', 3);

        $this->assertSame(3, AutoDialerQueueItem::count());
    }

    #[Test]
    public function a_telecaller_cannot_open_two_sessions_at_once(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $this->postJson('/api/v1/dialer/sessions')->assertStatus(201);

        // Two open runs would double-claim leads and make "resume where I left
        // off" meaningless.
        $this->postJson('/api/v1/dialer/sessions')
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'resource.conflict');

        $this->assertSame(1, AutoDialerSession::count());
    }

    #[Test]
    public function a_session_with_no_matching_leads_is_refused_rather_than_opened_empty(): void
    {
        $this->actingAsTelecaller();

        $this->postJson('/api/v1/dialer/sessions')->assertStatus(422);

        $this->assertSame(0, AutoDialerSession::count());
    }

    #[Test]
    public function the_open_session_survives_a_page_reload(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $sessionId = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        // FR-CALL-06: state lives in the database, not in a browser variable.
        $this->getJson('/api/v1/dialer/current')
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.is_active', true);
    }

    #[Test]
    public function pausing_keeps_the_queue_position_and_resuming_continues(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 3);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        $this->postJson("/api/v1/dialer/sessions/{$id}/pause")
            ->assertOk()
            ->assertJsonPath('data.state', 'paused')
            // One dialled, two still waiting - the position is kept.
            ->assertJsonPath('data.totals.remaining', 2);

        // A paused session does not hand out leads.
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertStatus(409);

        $this->postJson("/api/v1/dialer/sessions/{$id}/resume")
            ->assertOk()
            ->assertJsonPath('data.state', 'running');

        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();
    }

    #[Test]
    public function pausing_releases_the_lead_being_held(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        $this->postJson("/api/v1/dialer/sessions/{$id}/pause")->assertOk();

        // A telecaller who steps away must not sit on a lead nobody else can
        // call (BR-CALL-03).
        $this->assertSame(
            0,
            AutoDialerQueueItem::where('state', QueueItemState::Dialling->value)->count(),
        );
    }

    #[Test]
    public function a_run_completes_when_the_queue_is_exhausted(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        $this->postJson("/api/v1/dialer/sessions/{$id}/next")
            ->assertOk()
            ->assertJsonPath('message', 'The queue is finished.')
            ->assertJsonPath('data.session.state', 'completed');

        // ...and it is no longer somebody's open work.
        $this->getJson('/api/v1/dialer/current')->assertOk()->assertJsonPath('data', null);
    }

    #[Test]
    public function a_stopped_session_cannot_be_resumed(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        $this->postJson("/api/v1/dialer/sessions/{$id}/stop")
            ->assertOk()
            ->assertJsonPath('data.state', 'stopped');

        $this->postJson("/api/v1/dialer/sessions/{$id}/resume")->assertStatus(409);
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertStatus(409);
    }

    // -----------------------------------------------------------------------
    // FR-CALL-07 / BR-CALL-02 — skip rules, always logged
    // -----------------------------------------------------------------------

    #[Test]
    public function a_suppressed_lead_is_skipped_and_the_reason_recorded(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $blocked = Lead::query()->orderBy('id')->first();

        // Suppressed AFTER the queue was built. That is the case that matters:
        // an already-suppressed lead is filtered out at build time, but a queue
        // is worked over the following hour and a lead can be suppressed
        // mid-run — by a colleague, a webhook, or a call outcome.
        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');
        app(DncService::class)->suppress($blocked, DncReason::DoNotContact);

        $response = $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        // FR-CALL-07: skipped AND logged. A lead that silently vanished is
        // indistinguishable from one that was never queued.
        $this->assertSame('suppressed', $response->json('data.skipped.0.reason'));
        $this->assertNotSame($blocked->id, $response->json('data.lead.id'));

        $this->assertDatabaseHas('auto_dialer_queue_items', [
            'lead_id' => $blocked->id,
            'state' => QueueItemState::Skipped->value,
            'skip_reason' => DialerSkipReason::Suppressed->value,
        ]);

        // No dial intent was created for them.
        $this->assertSame(0, Call::where('lead_id', $blocked->id)->count());
    }

    #[Test]
    public function a_lead_contacted_within_the_cooldown_is_skipped(): void
    {
        $user = $this->actingAsTelecaller();

        // BR-CALL-02: calling somebody twice in a day turns a warm lead cold.
        // One lead, contacted two hours ago — so the run has nothing else to
        // fall back to and the skip is what the caller sees.
        $this->leadsFor($user, 1);
        Lead::query()->first()->forceFill(['last_contacted_at' => now()->subHours(2)])->save();

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');
        $response = $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        // The run ends, and it says why rather than just going quiet.
        $response->assertJsonPath('data.lead', null);

        $this->assertSame('cooldown', $response->json('data.skipped.0.reason'));
        // Temporary: the lead becomes dialable again once the window passes.
        $this->assertTrue($response->json('data.skipped.0.is_temporary'));
    }

    #[Test]
    public function a_lead_with_a_future_follow_up_is_left_alone(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $booked = Lead::query()->orderBy('id')->first();
        FollowUp::create([
            'tenant_id' => 0,
            'lead_id' => $booked->id,
            'assigned_to' => $user->id,
            'channel' => 'call',
            'scheduled_at' => now()->addDays(2),
            'status' => 'open',
        ]);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');
        $response = $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        // Somebody already agreed a time. The dialer must not pre-empt it.
        $this->assertSame('follow_up_scheduled', $response->json('data.skipped.0.reason'));
    }

    #[Test]
    public function a_lead_outside_its_own_calling_hours_is_skipped(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 1, ['timezone' => 'Asia/Kolkata']);
        // 11:00 in Kolkata is the middle of the night in Los Angeles.
        $this->leadsFor($user, 1, ['timezone' => 'America/Los_Angeles']);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        // Two leads queued. Walking the run to the end reaches both.
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        // BR-CALL-04 is evaluated per lead, in the lead's own timezone — so
        // one run can legitimately call one lead and defer the next.
        $this->assertDatabaseHas('auto_dialer_queue_items', [
            'skip_reason' => DialerSkipReason::OutsideCallingHours->value,
        ]);
    }

    #[Test]
    public function converted_and_closed_leads_never_enter_the_queue(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);
        $this->leadsFor($user, 1, ['status' => LeadStatus::Converted->value]);
        $this->leadsFor($user, 1, ['status' => LeadStatus::NotInterested->value]);
        $this->leadsFor($user, 1, ['status' => LeadStatus::Lost->value]);

        // Finished work is not dialling work - filtered at build time rather
        // than skipped one at a time.
        $this->postJson('/api/v1/dialer/sessions')
            ->assertStatus(201)
            ->assertJsonPath('data.totals.queued', 2);
    }

    #[Test]
    public function every_skip_reason_is_visible_on_the_session_queue(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');
        app(DncService::class)->suppress(Lead::query()->orderBy('id')->first(), DncReason::OptedOut);
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        $queue = $this->getJson("/api/v1/dialer/sessions/{$id}")->assertOk()->json('data.queue');

        $skipped = collect($queue)->firstWhere('state', 'skipped');

        // "Why did the dialer never ring this person?" has an answer.
        $this->assertSame('suppressed', $skipped['skip_reason']);
        $this->assertSame('On the do-not-contact list', $skipped['skip_reason_label']);
        $this->assertFalse($skipped['is_temporary_skip']);
    }

    // -----------------------------------------------------------------------
    // BR-CALL-03 — single-assignment guarantee
    // -----------------------------------------------------------------------

    #[Test]
    public function two_telecallers_are_never_handed_the_same_lead(): void
    {
        // One shared lead, unassigned, so both runs can legitimately include
        // it. Managers see the unassigned pool (BR-ASSIGN-06).
        $first = User::factory()->create();
        $first->roles()->attach(Role::where('name', RoleName::Manager->value)->first());
        $second = User::factory()->create();
        $second->roles()->attach(Role::where('name', RoleName::Manager->value)->first());

        $shared = Lead::factory()->create(['assigned_to' => null, 'timezone' => 'Asia/Kolkata']);

        $dialer = app(AutoDialerService::class);

        $sessionA = $dialer->start($first->fresh());
        $sessionB = $dialer->start($second->fresh());

        $a = $dialer->next($sessionA);
        $b = $dialer->next($sessionB);

        // The first run gets the lead; the second is told it is taken, rather
        // than two people ringing the same person a second apart.
        $this->assertSame($shared->id, $a['lead']->id);
        $this->assertNull($b['lead']);
        $this->assertSame(DialerSkipReason::ClaimedElsewhere->value, $b['skipped'][0]['reason']);

        $this->assertDatabaseHas('auto_dialer_queue_items', [
            'auto_dialer_session_id' => $sessionB->id,
            'lead_id' => $shared->id,
            'skip_reason' => DialerSkipReason::ClaimedElsewhere->value,
        ]);

        $this->assertSame(1, Call::where('lead_id', $shared->id)->count());
    }

    #[Test]
    public function a_claim_left_dangling_is_released_after_the_ttl(): void
    {
        $first = User::factory()->create();
        $first->roles()->attach(Role::where('name', RoleName::Manager->value)->first());
        $second = User::factory()->create();
        $second->roles()->attach(Role::where('name', RoleName::Manager->value)->first());

        $shared = Lead::factory()->create(['assigned_to' => null, 'timezone' => 'Asia/Kolkata']);

        $dialer = app(AutoDialerService::class);
        $sessionA = $dialer->start($first->fresh());
        $dialer->next($sessionA);

        // The first telecaller closed their laptop. Without a TTL that lead is
        // out of circulation forever.
        Carbon::setTestNow(Carbon::now()->addMinutes(20));

        $sessionB = $dialer->start($second->fresh());
        $result = $dialer->next($sessionB);

        $this->assertNotNull($result['lead']);
        $this->assertSame($shared->id, $result['lead']->id);
    }

    // -----------------------------------------------------------------------
    // Dialling produces a real, gated call
    // -----------------------------------------------------------------------

    #[Test]
    public function the_next_lead_comes_with_a_dial_intent_marked_as_auto_dialer(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 1);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        $response = $this->postJson("/api/v1/dialer/sessions/{$id}/next")
            ->assertOk()
            ->assertJsonPath('data.call.is_pending', true);

        // Runs through the same CallService gate as a manual call, so the
        // dialer cannot produce a call the manual path would have refused.
        $this->assertDatabaseHas('calls', [
            'id' => $response->json('data.call.id'),
            'dial_source' => 'auto_dialer',
            'auto_dialer_session_id' => $id,
            'status' => null,
        ]);
    }

    #[Test]
    public function asking_for_the_next_lead_closes_out_the_previous_one(): void
    {
        $user = $this->actingAsTelecaller();
        $this->leadsFor($user, 2);

        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        $firstLeadId = $this->postJson("/api/v1/dialer/sessions/{$id}/next")->json('data.lead.id');
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertOk();

        // No cross-service callback needed: moving on IS the completion.
        $this->assertDatabaseHas('auto_dialer_queue_items', [
            'lead_id' => $firstLeadId,
            'state' => QueueItemState::Dialled->value,
        ]);
    }

    // -----------------------------------------------------------------------
    // Authorisation
    // -----------------------------------------------------------------------

    #[Test]
    public function a_role_without_the_dialer_permission_cannot_start_a_run(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::Accounts->value)->first());
        $this->actingAs($user->fresh(), 'sanctum');

        $this->postJson('/api/v1/dialer/sessions')->assertStatus(403);
    }

    #[Test]
    public function a_colleague_cannot_drive_someone_elses_session(): void
    {
        $owner = $this->actingAsTelecaller();
        $this->leadsFor($owner, 2);
        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        // Taking the next lead for somebody else would claim a lead for a
        // person who is not on the phone.
        $this->actingAsTelecaller();

        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertStatus(403);
        $this->postJson("/api/v1/dialer/sessions/{$id}/pause")->assertStatus(403);
    }

    #[Test]
    public function a_manager_can_stop_a_run_but_not_drive_it(): void
    {
        $owner = $this->actingAsTelecaller();
        $this->leadsFor($owner, 2);
        $id = $this->postJson('/api/v1/dialer/sessions')->json('data.id');

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', RoleName::Admin->value)->first());
        $this->actingAs($admin->fresh(), 'sanctum');

        // A supervisor must be able to release the leads held by a telecaller
        // who has gone home.
        $this->postJson("/api/v1/dialer/sessions/{$id}/next")->assertStatus(403);
        $this->postJson("/api/v1/dialer/sessions/{$id}/stop")->assertOk();
    }
}
