<?php

namespace Tests\Feature\Interest;

use App\Enums\Channel;
use App\Enums\InterestSignalType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\InterestSignal;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Interest\InterestEngine;
use App\Services\Interest\LeadScorer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Interest engine (Phase 20, FR-INT-01..03, FR-STAT-04, BR-INT-01..04,
 * BR-SCORE-01, BR-TEMP-01/02).
 *
 * The rules worth proving are the ones that make the score trustworthy: it is
 * derived rather than accumulated, recency gates temperature rather than
 * bonusing it, low-confidence AI is recorded without acting, and all seven
 * effects land together or not at all.
 */
class InterestEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'crm.ai_interest_confidence_threshold' => 0.75,
            'crm.interest.auto_follow_up_hours' => 24,
        ]);
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = $this->user($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function signal(Lead $lead, InterestSignalType $type, array $options = []): InterestSignal
    {
        return app(InterestEngine::class)->record($lead, $type, null, $options);
    }

    // -----------------------------------------------------------------------
    // The atomic effect set (FR-INT-02, BR-INT-02)
    // -----------------------------------------------------------------------

    #[Test]
    public function an_interest_signal_applies_all_seven_effects(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);
        $product = Product::factory()->create();
        $lead = Lead::factory()->create([
            'status' => LeadStatus::Contacted->value,
            'assigned_to' => $actor->id,
        ]);

        $this->postJson("/api/v1/leads/{$lead->id}/interest", [
            'type' => InterestSignalType::InterestStated->value,
            'product_id' => $product->id,
        ])->assertCreated();

        $lead->refresh();

        // 1. Signal stored with its evidence.
        $this->assertDatabaseHas('interest_signals', [
            'lead_id' => $lead->id,
            'type' => InterestSignalType::InterestStated->value,
        ]);
        // 2. Status.
        $this->assertSame(LeadStatus::Interested, $lead->status);
        // 3. Product interest.
        $this->assertDatabaseHas('lead_products', [
            'lead_id' => $lead->id,
            'product_id' => $product->id,
            'interest_status' => 'interested',
        ]);
        // 4. Label.
        $this->assertTrue($lead->tags()->where('name', 'Interested')->exists());
        // 5. Score.
        $this->assertSame(25, $lead->score);
        // 6. Temperature (25 points, engaged now -> Cold band, but recalculated).
        $this->assertNotNull($lead->temperature);
        // 7. Follow-up.
        $this->assertDatabaseHas('follow_ups', ['lead_id' => $lead->id, 'status' => 'open']);
    }

    #[Test]
    public function a_second_interest_signal_reschedules_rather_than_stacking_follow_ups(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Contacted->value]);

        $this->signal($lead, InterestSignalType::InterestStated);
        $this->signal($lead->fresh(), InterestSignalType::InboundReply);

        // BR-FUP-01 makes the engine safe to call repeatedly: one open
        // follow-up per lead-product, so two signals do not produce two
        // reminders that then both look missed.
        $this->assertSame(1, $lead->followUps()->where('status', 'open')->count());
    }

    #[Test]
    public function a_signal_does_not_drag_a_lead_backwards_down_the_pipeline(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Negotiation->value]);

        $this->signal($lead, InterestSignalType::InboundReply);

        // Negotiation is further along than Interested. Somebody replying to an
        // email is not a reason to demote the deal.
        $this->assertSame(LeadStatus::Negotiation, $lead->fresh()->status);
    }

    #[Test]
    public function a_non_interest_signal_scores_without_touching_status_or_labels(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Contacted->value]);

        // Somebody answering the phone has not said yes.
        $this->signal($lead, InterestSignalType::CallConnected);

        $lead->refresh();
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertSame(10, $lead->score);
        $this->assertFalse($lead->tags()->where('name', 'Interested')->exists());
        $this->assertSame(0, $lead->followUps()->count());
    }

    // -----------------------------------------------------------------------
    // AI confidence (BR-INT-04)
    // -----------------------------------------------------------------------

    #[Test]
    public function low_confidence_ai_interest_is_recorded_but_not_acted_on(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Contacted->value]);

        $response = $this->postJson("/api/v1/leads/{$lead->id}/interest", [
            'type' => InterestSignalType::AiInterestDetected->value,
            'confidence' => 0.4,
        ])->assertCreated();

        // Discarding it would lose the evidence that the model saw something;
        // acting on it would let a 0.4 guess reclassify a lead.
        $this->assertFalse($response->json('data.signal.acted_on'));
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertDatabaseHas('interest_signals', [
            'lead_id' => $lead->id,
            'acted_on' => false,
        ]);
    }

    #[Test]
    public function high_confidence_ai_interest_acts_like_any_other_signal(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['status' => LeadStatus::Contacted->value]);

        $this->postJson("/api/v1/leads/{$lead->id}/interest", [
            'type' => InterestSignalType::AiInterestDetected->value,
            'confidence' => 0.9,
        ])->assertCreated();

        $this->assertSame(LeadStatus::Interested, $lead->fresh()->status);
    }

    #[Test]
    public function ai_points_are_weighted_by_confidence(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        // 15 base x 0.8 = 12.
        $this->signal($lead, InterestSignalType::AiInterestDetected, ['confidence' => 0.8]);

        $this->assertSame(12, $lead->fresh()->score);
    }

    // -----------------------------------------------------------------------
    // The score model (BR-SCORE-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function repeat_negatives_are_capped_so_they_cannot_bury_a_lead(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        // +25 interest, then ten unanswered calls at -2 each.
        $this->signal($lead, InterestSignalType::InterestStated);
        for ($i = 0; $i < 10; $i++) {
            $this->signal($lead->fresh(), InterestSignalType::CallNoAnswer);
        }

        // Capped at -10, not -20: ten unanswered calls to a genuinely busy
        // prospect should not bury an otherwise warm lead.
        $breakdown = app(LeadScorer::class)->explain($lead->fresh());
        $noAnswer = collect($breakdown['lines'])->firstWhere('type', 'call_no_answer');

        $this->assertEquals(-10, $noAnswer['points']);
        $this->assertTrue($noAnswer['capped']);
        $this->assertSame(15, $breakdown['score']);   // 25 - 10
    }

    #[Test]
    public function the_score_is_clamped_to_the_configured_range(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        // Well over 100 raw.
        for ($i = 0; $i < 8; $i++) {
            $this->signal($lead->fresh(), InterestSignalType::NegotiationEntered);
        }

        $fresh = $lead->fresh();
        $this->assertSame(100, $fresh->score);

        // The raw figure is still visible, so a lead far above the ceiling is
        // distinguishable from one exactly at it.
        $this->assertGreaterThan(100, app(LeadScorer::class)->explain($fresh)['raw']);
    }

    #[Test]
    public function a_negative_run_cannot_push_the_score_below_zero(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->signal($lead, InterestSignalType::NotInterested);
        $this->signal($lead->fresh(), InterestSignalType::FollowUpMissed);

        $this->assertSame(0, $lead->fresh()->score);
    }

    #[Test]
    public function the_score_is_derived_so_re_weighting_applies_to_history(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->signal($lead, InterestSignalType::CallConnected);
        $this->assertSame(10, $lead->fresh()->score);

        // The model is still a proposal (T-16). Re-weighting an accumulated
        // integer would mean guessing at history; a derived score just
        // recomputes.
        config(['crm.scoring.signals.call_connected' => 30]);

        $this->assertSame(30, app(LeadScorer::class)->score($lead->fresh()));
    }

    #[Test]
    public function the_breakdown_explains_why_a_lead_scores_what_it_does(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['assigned_to' => $actor->id]);

        $this->signal($lead, InterestSignalType::CallConnected);
        $this->signal($lead->fresh(), InterestSignalType::CallConnected);
        $this->signal($lead->fresh(), InterestSignalType::ProposalSent);

        $response = $this->getJson("/api/v1/leads/{$lead->id}/score")->assertOk();

        // BR-SCORE-01: transparent and explainable. A bare number cannot answer
        // "why is this Hot?", and a score nobody understands is one nobody
        // trusts.
        $this->assertSame(40, $response->json('data.score'));

        $lines = collect($response->json('data.signals'));
        $this->assertEquals(2, $lines->firstWhere('type', 'call_connected')['count']);
        $this->assertEquals(20, $lines->firstWhere('type', 'call_connected')['points']);
        $this->assertEquals(20, $lines->firstWhere('type', 'proposal_sent')['points']);
    }

    // -----------------------------------------------------------------------
    // Temperature (FR-STAT-04, BR-TEMP-01/02)
    // -----------------------------------------------------------------------

    #[Test]
    public function recency_gates_hot_rather_than_bonusing_it(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        // Enough points for Hot, engaged just now.
        $this->signal($lead, InterestSignalType::NegotiationEntered);
        $this->signal($lead->fresh(), InterestSignalType::ProposalSent);
        $this->signal($lead->fresh(), InterestSignalType::InterestStated);

        $this->assertSame(LeadTemperature::Hot, $lead->fresh()->temperature);

        // Same lead, sixty days of silence. A high-scoring lead nobody has
        // touched in two months is not Hot.
        $this->travel(60)->days();
        app(InterestEngine::class)->recalculate($lead->fresh());

        $this->assertNotSame(LeadTemperature::Hot, $lead->fresh()->temperature);
    }

    #[Test]
    public function a_suppressed_lead_is_dormant_however_warm_it_was(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->signal($lead, InterestSignalType::NegotiationEntered);
        $this->signal($lead->fresh(), InterestSignalType::ProposalSent);
        $this->signal($lead->fresh(), InterestSignalType::InterestStated);
        $this->assertSame(LeadTemperature::Hot, $lead->fresh()->temperature);

        $lead->forceFill(['is_suppressed' => true])->save();
        app(InterestEngine::class)->recalculate($lead->fresh());

        $this->assertSame(LeadTemperature::Dormant, $lead->fresh()->temperature);
    }

    #[Test]
    public function temperature_cannot_be_set_through_the_lead_endpoint(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['assigned_to' => $actor->id]);

        // BR-TEMP-01: derived, never freely typed. `temperature` and `score`
        // are outside mass assignment, so a PATCH body cannot reach them.
        $this->patchJson("/api/v1/leads/{$lead->id}", [
            'name' => 'Renamed',
            'temperature' => 'hot',
            'score' => 99,
        ])->assertOk();

        $lead->refresh();
        $this->assertNotSame(LeadTemperature::Hot, $lead->temperature);
        $this->assertSame(0, $lead->score);
    }

    // -----------------------------------------------------------------------
    // Decay (BR-SCORE-01)
    // -----------------------------------------------------------------------

    #[Test]
    public function silence_decays_the_score(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->signal($lead, InterestSignalType::ProposalSent);   // +20
        $this->assertSame(20, $lead->fresh()->score);

        // -5 per 7 days. 21 days of silence = -15.
        $this->travel(21)->days();
        app(InterestEngine::class)->recalculate($lead->fresh());

        $this->assertSame(5, $lead->fresh()->score);
    }

    #[Test]
    public function the_decay_sweep_only_touches_leads_with_signals(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $withSignals = Lead::factory()->create();
        $untouched = Lead::factory()->create();

        $this->signal($withSignals, InterestSignalType::ProposalSent);
        $this->travel(30)->days();

        $this->artisan('crm:decay-lead-scores')->assertSuccessful();

        // A lead nobody has ever interacted with scores zero; recomputing it
        // every night achieves nothing.
        $this->assertSame(0, $untouched->fresh()->score);
        $this->assertLessThan(20, $withSignals->fresh()->score);
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();
        $this->signal($lead, InterestSignalType::ProposalSent);

        $this->travel(30)->days();
        $this->artisan('crm:decay-lead-scores --dry-run')->assertSuccessful();

        $this->assertSame(20, $lead->fresh()->score);
    }

    // -----------------------------------------------------------------------
    // The maintained views (FR-INT-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function the_interested_view_lists_only_leads_that_showed_interest(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);

        $interested = Lead::factory()->create(['assigned_to' => $actor->id]);
        $justCalled = Lead::factory()->create(['assigned_to' => $actor->id]);
        Lead::factory()->create();   // never touched

        $this->signal($interested, InterestSignalType::InterestStated);
        $this->signal($justCalled, InterestSignalType::CallConnected);

        $items = $this->getJson('/api/v1/interested-leads')->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame($interested->id, $items[0]['id']);
    }

    #[Test]
    public function low_confidence_ai_alone_does_not_put_a_lead_in_the_interested_view(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create();

        $this->signal($lead, InterestSignalType::AiInterestDetected, ['confidence' => 0.3]);

        // The signal exists but was not acted on, so the view - which is what
        // a telecaller works from - must not include it (BR-INT-04).
        $this->getJson('/api/v1/interested-leads')->assertOk()->assertJsonCount(0, 'data.items');
    }

    #[Test]
    public function the_view_filters_by_temperature_and_by_product(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        $hot = Lead::factory()->create();
        $this->signal($hot, InterestSignalType::InterestStated, ['product_id' => $productA->id]);
        $this->signal($hot->fresh(), InterestSignalType::NegotiationEntered);
        $this->signal($hot->fresh(), InterestSignalType::ProposalSent);

        $cooler = Lead::factory()->create();
        $this->signal($cooler, InterestSignalType::InterestStated, ['product_id' => $productB->id]);

        $this->assertSame(LeadTemperature::Hot, $hot->fresh()->temperature);

        $this->getJson('/api/v1/interested-leads?temperature=hot')
            ->assertOk()->assertJsonCount(1, 'data.items');

        $this->getJson("/api/v1/interested-leads?product_id={$productB->id}")
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $cooler->id);
    }

    #[Test]
    public function the_view_is_sorted_hottest_first(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $low = Lead::factory()->create();
        $this->signal($low, InterestSignalType::InboundReply);   // +15

        $high = Lead::factory()->create();
        $this->signal($high, InterestSignalType::InterestStated);        // +25
        $this->signal($high->fresh(), InterestSignalType::ProposalSent); // +20

        $items = $this->getJson('/api/v1/interested-leads')->assertOk()->json('data.items');

        // The list exists to be worked from the top.
        $this->assertSame($high->id, $items[0]['id']);
    }

    // -----------------------------------------------------------------------
    // Scope and permissions
    // -----------------------------------------------------------------------

    #[Test]
    public function the_interested_view_is_scoped(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $this->actingAsRole(RoleName::Admin);
        $theirs = Lead::factory()->create(['assigned_to' => $colleague->id]);
        $this->signal($theirs, InterestSignalType::InterestStated);

        $this->actingAsRole(RoleName::Telecaller);

        $this->getJson('/api/v1/interested-leads')->assertOk()->assertJsonCount(0, 'data.items');
    }

    #[Test]
    public function a_telecaller_cannot_record_interest_on_someone_elses_lead(): void
    {
        $colleague = $this->user(RoleName::Telecaller);
        $lead = Lead::factory()->create(['assigned_to' => $colleague->id]);

        $this->actingAsRole(RoleName::Telecaller);

        $this->postJson("/api/v1/leads/{$lead->id}/interest", [
            'type' => InterestSignalType::InterestStated->value,
        ])->assertStatus(403);

        $this->getJson("/api/v1/leads/{$lead->id}/score")->assertStatus(403);
    }

    #[Test]
    public function a_read_only_role_cannot_record_interest(): void
    {
        $lead = Lead::factory()->create();

        // Accounts is read-only on leads - it holds leads.view but not
        // leads.update, and recording interest works the lead.
        $this->actingAsRole(RoleName::Accounts);

        $this->postJson("/api/v1/leads/{$lead->id}/interest", [
            'type' => InterestSignalType::InterestStated->value,
        ])->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Evidence (BR-INT-03)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_signal_retains_the_evidence_it_was_derived_from(): void
    {
        $lead = Lead::factory()->create();
        $call = Call::factory()->create(['lead_id' => $lead->id]);

        $signal = $this->signal($lead, InterestSignalType::InterestStated, [
            'evidence' => $call,
            'excerpt' => 'asked for pricing on the 40-seat plan',
            'channel' => Channel::Call,
        ]);

        /*
         * BR-INT-03: the signal points back at the call it came from. A score
         * that cannot be traced to what somebody actually said is a number
         * nobody will act on - and the first question asked of any hot lead is
         * "why is it hot?".
         */
        $this->assertTrue($signal->evidence->is($call));
        $this->assertSame('asked for pricing on the 40-seat plan', $signal->excerpt);
        $this->assertSame(Channel::Call, $signal->channel);
        $this->assertSame('system', $signal->source);
    }

    #[Test]
    public function evidence_survives_a_later_signal_on_the_same_lead(): void
    {
        $lead = Lead::factory()->create();
        $call = Call::factory()->create(['lead_id' => $lead->id]);

        $first = $this->signal($lead, InterestSignalType::InterestStated, ['evidence' => $call]);
        $this->signal($lead, InterestSignalType::ProposalSent);

        // Signals accumulate as history; the score is derived from them
        // (BR-SCORE-01). Recalculating must not rewrite what was already
        // recorded, or the audit trail changes every time somebody rings.
        $this->assertTrue($first->fresh()->evidence->is($call));
    }

    #[Test]
    public function an_unknown_signal_type_is_refused(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);
        $lead = Lead::factory()->create(['assigned_to' => $actor->id]);

        // The set is closed so the breakdown stays readable and the points
        // table stays in step.
        $this->postJson("/api/v1/leads/{$lead->id}/interest", ['type' => 'vibes'])
            ->assertStatus(422);
    }
}
