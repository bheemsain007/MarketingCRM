<?php

namespace App\Services\Interest;

use App\Enums\Channel;
use App\Enums\InterestSignalType;
use App\Enums\LeadStatus;
use App\Enums\StatusSource;
use App\Models\InterestSignal;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\User;
use App\Services\FollowUps\FollowUpService;
use App\Services\Leads\LeadStatusService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single interest engine (FR-INT-01/02, BR-INT-01..04).
 *
 * BR-INT-01: human call, AI call, WhatsApp, email, SMS, RCS, voice and manual
 * CRM action all route here. **No channel handler implements its own interest
 * logic** - eight copies of "what counts as interest" would disagree, and the
 * disagreement would show up as a lead that is Hot on one screen and Cold on
 * another.
 *
 * BR-INT-02: all seven effects commit in ONE transaction. Partial application
 * is the failure mode this rule exists to prevent - a lead whose score moved
 * but whose status did not is a lead nobody calls back.
 */
class InterestEngine
{
    public function __construct(
        private readonly LeadScorer $scorer,
        private readonly LeadStatusService $status,
        private readonly FollowUpService $followUps,
    ) {}

    /**
     * Records a signal and applies everything that follows from it.
     *
     * @param  array<string, mixed>  $options  product_id, channel, source,
     *                                         evidence, excerpt, confidence,
     *                                         occurred_at
     */
    public function record(
        Lead $lead,
        InterestSignalType $type,
        ?User $actor = null,
        array $options = [],
    ): InterestSignal {
        $confidence = isset($options['confidence']) ? (float) $options['confidence'] : null;

        /*
         * BR-INT-04: AI-detected interest below the configured threshold is
         * RECORDED as a signal but does not by itself move status. Discarding
         * it would lose the evidence that the model saw something; acting on it
         * would let a 0.3-confidence guess reclassify a lead.
         */
        $actOn = ! ($type->isAiDetected()
            && $confidence !== null
            && $confidence < (float) config('crm.ai_interest_confidence_threshold', 0.75));

        return DB::transaction(function () use ($lead, $type, $actor, $options, $confidence, $actOn) {
            // 1. The signal itself, with its evidence (BR-INT-03).
            $signal = $this->store($lead, $type, $actor, $options, $confidence, $actOn);

            if ($actOn && $type->indicatesInterest()) {
                // 2. Lead status.
                $this->promoteStatus($lead, $actor);

                // 3. Product interest.
                $this->recordProductInterest($lead, $options);

                // 4. Label.
                $this->applyLabel($lead, $actor);
            }

            // 5 & 6. Score and temperature - recalculated for EVERY signal,
            // including ones that do not indicate interest and AI signals below
            // the threshold. A no-answer still moves the number.
            $this->recalculate($lead, touchEngagement: $actOn && $type->indicatesInterest());

            // 7. Follow-up, if configured.
            if ($actOn && $type->indicatesInterest()) {
                $this->scheduleFollowUp($lead, $actor, $options);
            }

            return $signal->fresh();
        });
    }

    /**
     * Recomputes score and temperature from the signal history.
     *
     * Public because the decay sweep and any re-weighting of the model need it
     * without inventing a signal to trigger it.
     */
    public function recalculate(Lead $lead, bool $touchEngagement = false): Lead
    {
        if ($touchEngagement) {
            $lead->forceFill(['last_engagement_at' => now()])->save();
            $lead->refresh();
        }

        $score = $this->scorer->score($lead);

        // forceFill: `score` and `temperature` are derived (BR-TEMP-01) and
        // deliberately outside mass assignment, so nothing but this can set
        // them.
        $lead->forceFill([
            'score' => $score,
            'temperature' => $this->scorer->temperature($lead, $score),
        ])->save();

        return $lead->fresh();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function store(
        Lead $lead,
        InterestSignalType $type,
        ?User $actor,
        array $options,
        ?float $confidence,
        bool $actOn,
    ): InterestSignal {
        /** @var Model|null $evidence */
        $evidence = $options['evidence'] ?? null;

        $points = $type->points();
        if ($type->isAiDetected() && $confidence !== null) {
            $points *= $confidence;
        }

        return InterestSignal::create([
            'tenant_id' => config('crm.default_tenant_id'),
            'lead_id' => $lead->id,
            'product_id' => $options['product_id'] ?? null,
            'type' => $type->value,
            'channel' => isset($options['channel'])
                ? ($options['channel'] instanceof Channel ? $options['channel']->value : $options['channel'])
                : null,
            'source' => $options['source'] ?? ($actor !== null ? 'manual' : 'system'),
            'evidence_type' => $evidence !== null ? $evidence::class : null,
            'evidence_id' => $evidence?->getKey(),
            'excerpt' => $options['excerpt'] ?? null,
            'confidence' => $confidence,
            'acted_on' => $actOn,
            'points_awarded' => round($points, 2),
            'user_id' => $actor?->id,
            'occurred_at' => $options['occurred_at'] ?? now(),
        ]);
    }

    /**
     * Statuses the engine may automatically promote FROM.
     *
     * Deliberately not "whatever the matrix allows". The matrix permits
     * Negotiation -> Interested because a HUMAN may legitimately step a deal
     * back when it stalls - but an automated signal must never do that. A
     * prospect replying to an email while a proposal is on the table is not a
     * reason to demote the deal, and a score engine that quietly walked
     * pipeline stages backwards would make the funnel report meaningless.
     *
     * @var array<int, LeadStatus>
     */
    private const PROMOTABLE_FROM = [
        LeadStatus::New,
        LeadStatus::Contacted,
        LeadStatus::FollowUp,
        LeadStatus::Callback,
    ];

    /**
     * Moves the lead to `Interested` when it is genuinely a step forward.
     */
    private function promoteStatus(Lead $lead, ?User $actor): void
    {
        if (! in_array($lead->status, self::PROMOTABLE_FROM, true)) {
            return;
        }

        if (! $this->status->canChange($lead, LeadStatus::Interested, $actor)) {
            return;
        }

        $this->status->change(
            $lead,
            LeadStatus::Interested,
            $actor,
            StatusSource::System,
            'Interest signal received',
        );

        $lead->refresh();
    }

    /** @param array<string, mixed> $options */
    private function recordProductInterest(Lead $lead, array $options): void
    {
        if (empty($options['product_id'])) {
            return;
        }

        // Upsert rather than insert: interest in the same product twice is one
        // interest, not two.
        $lead->leadProducts()->updateOrCreate(
            ['product_id' => (int) $options['product_id']],
            ['interest_status' => 'interested'],
        );
    }

    private function applyLabel(Lead $lead, ?User $actor): void
    {
        $name = (string) config('crm.interest.label', 'Interested');

        $tag = Tag::firstOrCreate(
            ['tenant_id' => config('crm.default_tenant_id'), 'name' => $name],
            ['slug' => str($name)->slug()->toString(), 'is_system' => true],
        );

        // syncWithoutDetaching: applying the label must not remove tags a
        // telecaller added by hand.
        $lead->tags()->syncWithoutDetaching([
            $tag->id => ['tagged_by' => $actor?->id],
        ]);
    }

    /** @param array<string, mixed> $options */
    private function scheduleFollowUp(Lead $lead, ?User $actor, array $options): void
    {
        $hours = config('crm.interest.auto_follow_up_hours');

        if ($hours === null) {
            return;
        }

        // BR-FUP-01 makes this safe to call repeatedly: a lead-product pair has
        // at most one open follow-up, so a second interest signal reschedules
        // rather than stacking a duplicate.
        $this->followUps->schedule($lead, [
            'scheduled_at' => now()->addHours((int) $hours),
            'product_id' => $options['product_id'] ?? null,
            'subject' => 'Follow up on interest',
            'channel' => Channel::Call->value,
        ], $actor?->id);
    }
}
