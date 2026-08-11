<?php

namespace Tests\Feature\Calls;

use App\Enums\CallStatus;
use App\Enums\Channel;
use App\Enums\DncReason;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\DncEntry;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\Calls\CallService;
use App\Services\Dnc\DncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Calling (FR-CALL-01..05, FR-CALL-08, BR-CALL-01/04/05, BR-DNC-07).
 *
 * §4.1 of TESTING makes the DNC gate a mandatory suite. It is first in this
 * file for the same reason it is first in the service: a suppressed lead being
 * dialled is a regulatory problem, not a bug report.
 */
class CallApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Fixed inside the 09:00-20:00 window so calling-hours behaviour is
        // asserted deliberately rather than depending on when the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());
        $user = $user->fresh();

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function leadFor(User $user, array $attributes = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'assigned_to' => $user->id,
            'timezone' => 'Asia/Kolkata',
        ], $attributes));
    }

    // -----------------------------------------------------------------------
    // FR-CALL-08 / BR-CALL-01 — the DNC gate (mandatory, TESTING §4.1)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_suppressed_lead_can_never_be_dialled(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        app(DncService::class)->suppress($lead, DncReason::DoNotContact);

        $this->postJson("/api/v1/leads/{$lead->id}/calls")
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'dnc.suppressed');

        // No dial intent, not even a cancelled one - the record must not exist.
        $this->assertSame(0, Call::count());
    }

    #[Test]
    public function a_suppressed_lead_cannot_be_dialled_by_logging_a_call_either(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        app(DncService::class)->suppress($lead, DncReason::OptedOut);

        // The one-step "log a call I already made" path runs the same gate.
        // A second entry point that skipped it would be the whole problem.
        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'connected'])
            ->assertStatus(403);

        $this->assertSame(0, Call::count());
    }

    #[Test]
    public function suppression_that_does_not_block_calls_does_not_block_calls(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // BR-DNC-02: a bounced email says nothing about the phone number.
        // Over-blocking is a real cost - it silently stops legitimate work.
        app(DncService::class)->suppress($lead, DncReason::BouncedEmail);

        $this->postJson("/api/v1/leads/{$lead->id}/calls")->assertStatus(202);
    }

    #[Test]
    public function the_gate_reads_the_dnc_table_not_the_cached_flag(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);

        // The denormalised flag says "suppressed" but there is no entry behind
        // it. The flag is a list-filter cache and is never the authority
        // (BR-DNC-01, ADR-E) - so the call is allowed.
        $lead = $this->leadFor($user, ['is_suppressed' => true]);

        $this->postJson("/api/v1/leads/{$lead->id}/calls")->assertStatus(202);
    }

    // -----------------------------------------------------------------------
    // BR-CALL-04 — calling hours
    // -----------------------------------------------------------------------

    #[Test]
    public function a_call_outside_the_window_is_refused_with_the_time_it_reopens(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        Carbon::setTestNow(Carbon::parse('2026-08-10 22:30:00', 'Asia/Kolkata'));

        $response = $this->postJson("/api/v1/leads/{$lead->id}/calls")
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'call.outside_calling_hours');

        // Deferred, not dropped: the refusal carries when the lead becomes
        // reachable, so the work can reschedule itself.
        $this->assertNotNull($response->json('data.next_opening'));
        $this->assertSame(0, Call::count());
    }

    #[Test]
    public function the_window_is_evaluated_in_the_leads_timezone_not_the_offices(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);

        // 11:00 in Kolkata is 05:30 UTC — the middle of the night in London.
        // A telecaller must not ring someone at 06:30 their time because it is
        // convenient here.
        $lead = $this->leadFor($user, ['timezone' => 'Europe/London']);

        $this->postJson("/api/v1/leads/{$lead->id}/calls")
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'call.outside_calling_hours')
            ->assertJsonPath('data.lead_timezone', 'Europe/London');
    }

    // -----------------------------------------------------------------------
    // FR-CALL-03 — dial intent
    // -----------------------------------------------------------------------

    #[Test]
    public function starting_a_call_returns_202_and_a_record_with_no_outcome_yet(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // 202, not 201: the device has yet to dial. A status here would be a
        // guess, and a guessed outcome is worse than an absent one.
        $this->postJson("/api/v1/leads/{$lead->id}/calls")
            ->assertStatus(202)
            ->assertJsonPath('data.is_pending', true)
            ->assertJsonPath('data.status', null);

        $this->assertDatabaseHas('calls', [
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'status' => null,
            'direction' => 'outbound',
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'activity_type' => 'call_started',
        ]);
    }

    #[Test]
    public function a_lead_with_no_number_cannot_be_called(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);
        $lead->forceFill(['phone_e164' => ''])->save();

        $this->postJson("/api/v1/leads/{$lead->id}/calls")->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // FR-CALL-02 / BR-CALL-05 — outcomes and their side effects
    // -----------------------------------------------------------------------

    #[Test]
    public function an_outcome_can_be_reported_against_a_dialled_call(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $callId = $this->postJson("/api/v1/leads/{$lead->id}/calls")->json('data.id');

        $this->patchJson("/api/v1/calls/{$callId}", [
            'status' => 'connected',
            'duration_seconds' => 214,
            'notes' => 'Wants a quote for the portal',
        ])->assertOk()
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonPath('data.duration_seconds', 214)
            ->assertJsonPath('data.is_pending', false);

        // Any attempt updates contact recency, which drives the leakage
        // metric (GLOSSARY §2.9).
        $this->assertNotNull($lead->fresh()->last_contacted_at);
    }

    #[Test]
    public function an_unconnected_call_records_no_talk_time(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $callId = $this->postJson("/api/v1/leads/{$lead->id}/calls")->json('data.id');

        $this->patchJson("/api/v1/calls/{$callId}", [
            'status' => 'no_answer',
            'duration_seconds' => 30,
        ])->assertOk()
            // A ringing phone is not a conversation. Counting it would inflate
            // Average Call Duration for whoever gets the worst list.
            ->assertJsonPath('data.duration_seconds', 0);
    }

    #[Test]
    public function an_outcome_is_write_once(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $callId = $this->postJson("/api/v1/leads/{$lead->id}/calls")->json('data.id');

        $this->patchJson("/api/v1/calls/{$callId}", ['status' => 'connected'])->assertOk();

        // Call records are evidence: they feed talk time, pay and disputed
        // conversions. An outcome that could be rewritten later is one nobody
        // can rely on.
        $this->patchJson("/api/v1/calls/{$callId}", ['status' => 'no_answer'])
            ->assertStatus(409);

        $this->assertSame(CallStatus::Connected, Call::find($callId)->status);
    }

    #[Test]
    public function a_wrong_number_suppresses_the_lead_automatically(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'wrong_number'])
            ->assertStatus(201);

        // BR-DNC-07. Not a prompt and not the telecaller's decision — a number
        // confirmed wrong must never be dialled again, and leaving that to
        // somebody ticking a box is how it gets dialled again.
        $this->assertDatabaseHas('dnc_entries', [
            'lead_id' => $lead->id,
            'reason' => DncReason::WrongNumber->value,
            'source' => 'call_outcome',
            'active' => true,
        ]);

        $this->assertTrue($lead->fresh()->is_suppressed);

        // ...and the lead cannot be dialled again.
        $this->postJson("/api/v1/leads/{$lead->id}/calls")->assertStatus(403);
    }

    #[Test]
    public function a_wrong_number_leaves_the_email_address_contactable(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'invalid_number'])
            ->assertStatus(201);

        // BR-DNC-02: the phone is bad, the email may be perfectly good.
        $this->assertFalse(app(DncService::class)->canContact($lead->fresh(), Channel::Call));
        $this->assertTrue(app(DncService::class)->canContact($lead->fresh(), Channel::Email));
    }

    #[Test]
    public function a_connected_call_does_not_suppress_anything(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'connected'])
            ->assertStatus(201);

        $this->assertSame(0, DncEntry::count());
        $this->assertFalse($lead->fresh()->is_suppressed);
    }

    #[Test]
    public function a_callback_request_creates_a_follow_up_for_the_same_telecaller(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/calls", [
            'status' => 'call_back_requested',
            'callback_at' => now()->addDay()->toIso8601String(),
        ])->assertStatus(201);

        // BR-CALL-05. The lead asked *this* telecaller to ring back, so the
        // follow-up stays with them.
        $this->assertDatabaseHas('follow_ups', [
            'lead_id' => $lead->id,
            'assigned_to' => $user->id,
            'channel' => 'call',
            'status' => 'open',
            'subject' => 'Callback requested',
        ]);

        // The call points at the follow-up it produced.
        $this->assertNotNull(Call::first()->follow_up_id);
    }

    #[Test]
    public function a_callback_without_a_time_is_rejected_rather_than_silently_dropped(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // A callback request with no diary entry is a promise nobody keeps.
        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'call_back_requested'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.field', 'callback_at');

        $this->assertSame(0, FollowUp::count());
    }

    #[Test]
    public function a_callback_cannot_be_scheduled_in_the_past(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/calls", [
            'status' => 'call_back_requested',
            'callback_at' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    #[Test]
    public function an_invalid_outcome_value_is_rejected(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        // FR-CALL-01: the eleven, and nothing else.
        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'they_hung_up'])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // FR-CALL-04 — history
    // -----------------------------------------------------------------------

    #[Test]
    public function call_history_per_lead_is_paginated_and_filterable(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'connected']);
        $this->postJson("/api/v1/leads/{$lead->id}/calls", ['status' => 'no_answer']);

        $this->getJson("/api/v1/leads/{$lead->id}/calls")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson("/api/v1/leads/{$lead->id}/calls?filter[status]=connected")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function a_telecaller_sees_only_their_own_calls_in_the_history(): void
    {
        $colleague = User::factory()->create();
        $colleague->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());
        $theirLead = Lead::factory()->create(['assigned_to' => $colleague->id, 'timezone' => 'Asia/Kolkata']);
        app(CallService::class)->log($theirLead, $colleague->fresh(), CallStatus::Connected);

        $user = $this->actingAsRole(RoleName::Telecaller);
        $this->postJson("/api/v1/leads/{$this->leadFor($user)->id}/calls", ['status' => 'connected']);

        // SEC-AUTHZ-03: two calls exist, one is visible.
        $this->assertSame(2, Call::count());

        $this->getJson('/api/v1/calls')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function an_admin_sees_every_call(): void
    {
        $telecaller = User::factory()->create();
        $telecaller->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());
        $lead = Lead::factory()->create(['assigned_to' => $telecaller->id, 'timezone' => 'Asia/Kolkata']);
        app(CallService::class)->log($lead, $telecaller->fresh(), CallStatus::Connected);

        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/v1/calls')->assertOk()->assertJsonPath('data.meta.total', 1);
    }

    // -----------------------------------------------------------------------
    // Callability probe and authorisation
    // -----------------------------------------------------------------------

    #[Test]
    public function the_callability_probe_explains_a_refusal_before_it_happens(): void
    {
        $user = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($user);

        $this->getJson("/api/v1/leads/{$lead->id}/callability")
            ->assertOk()
            ->assertJsonPath('data.callable', true);

        app(DncService::class)->suppress($lead, DncReason::NotInterested);

        $this->getJson("/api/v1/leads/{$lead->id}/callability")
            ->assertOk()
            ->assertJsonPath('data.callable', false)
            ->assertJsonPath('data.reason', 'dnc.suppressed');
    }

    #[Test]
    public function a_telecaller_cannot_call_a_colleagues_lead(): void
    {
        $colleague = User::factory()->create();
        $colleague->roles()->attach(Role::where('name', RoleName::Telecaller->value)->first());
        $lead = Lead::factory()->create(['assigned_to' => $colleague->id, 'timezone' => 'Asia/Kolkata']);

        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson("/api/v1/leads/{$lead->id}/calls")->assertStatus(403);
        $this->assertSame(0, Call::count());
    }

    #[Test]
    public function a_colleague_cannot_write_an_outcome_onto_someone_elses_call(): void
    {
        $owner = $this->actingAsRole(RoleName::Telecaller);
        $lead = $this->leadFor($owner);
        $callId = $this->postJson("/api/v1/leads/{$lead->id}/calls")->json('data.id');

        // Would corrupt the record that drives talk time and telecaller pay.
        $this->actingAsRole(RoleName::Telecaller);
        $this->patchJson("/api/v1/calls/{$callId}", ['status' => 'connected'])->assertStatus(403);
    }

    #[Test]
    public function a_read_only_role_cannot_place_calls(): void
    {
        $this->actingAsRole(RoleName::Viewer);
        $lead = Lead::factory()->create(['timezone' => 'Asia/Kolkata']);

        // Viewer holds calls.view but not calls.create.
        $this->postJson("/api/v1/leads/{$lead->id}/calls")->assertStatus(403);
    }
}
