<?php

namespace App\Services\Interest;

use App\Enums\InterestSignalType;
use App\Enums\LeadTemperature;
use App\Models\InterestSignal;
use App\Models\Lead;
use Illuminate\Support\Carbon;

/**
 * Computes a lead's score and temperature (BR-SCORE-01, BR-TEMP-01/02).
 *
 * Derived from `interest_signals` every time rather than accumulated on the
 * lead. That is what makes the score **explainable** - `explain()` returns the
 * same arithmetic the number came from - and what makes re-weighting the model
 * a recompute rather than a guess at history (T-16).
 */
class LeadScorer
{
    /**
     * The score as it stands, 0-100.
     *
     * Clamped at both ends. An unclamped score is not comparable between leads,
     * and "score 143" tells a telecaller nothing that "100" does not.
     */
    public function score(Lead $lead): int
    {
        return $this->explain($lead)['score'];
    }

    /**
     * The score with its full working (BR-SCORE-01: transparent and explainable).
     *
     * A telecaller must be able to see why a lead is Hot. This is that answer:
     * every contributing signal type, how many of them, what they were worth,
     * and the decay applied for silence.
     *
     * @return array{score: int, raw: float, lines: array<int, array<string, mixed>>, decay: float}
     */
    public function explain(Lead $lead): array
    {
        $signals = InterestSignal::query()
            ->where('lead_id', $lead->id)
            ->get()
            ->groupBy(fn (InterestSignal $s) => $s->type->value);

        $lines = [];
        $raw = 0.0;

        foreach ($signals as $typeValue => $group) {
            $type = InterestSignalType::from((string) $typeValue);

            // Recomputed at CURRENT weights rather than summing the stored
            // `points_awarded`, so a re-weighted model applies to history
            // instead of leaving old leads scored under old rules.
            $subtotal = $group->sum(fn (InterestSignal $s) => $this->pointsFor($type, $s));

            // BR-SCORE-01 caps repeat negatives - ten unanswered calls should
            // not bury an otherwise warm lead.
            $cap = $type->cumulativeCap();
            if ($cap !== null && $subtotal < $cap) {
                $subtotal = $cap;
            }

            $lines[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'count' => $group->count(),
                'points' => round($subtotal, 2),
                'capped' => $cap !== null && $group->sum(fn (InterestSignal $s) => $this->pointsFor($type, $s)) < $cap,
            ];

            $raw += $subtotal;
        }

        $decay = $this->decayFor($lead);
        $raw += $decay;

        $min = (float) config('crm.scoring.min', 0);
        $max = (float) config('crm.scoring.max', 100);

        return [
            'score' => (int) round(max($min, min($max, $raw))),
            'raw' => round($raw, 2),
            'lines' => $lines,
            'decay' => round($decay, 2),
        ];
    }

    /**
     * Temperature from score and recency (BR-TEMP-02).
     *
     * Recency is a GATE, not a bonus: a high-scoring lead nobody has touched in
     * 60 days is not Hot. The banding itself lives in the enum so there is one
     * definition of it.
     */
    public function temperature(Lead $lead, ?int $score = null): LeadTemperature
    {
        $days = $lead->last_engagement_at?->diffInDays(now());

        return LeadTemperature::derive(
            $score ?? $this->score($lead),
            $days === null ? null : (int) $days,
            (bool) $lead->is_suppressed,
        );
    }

    /**
     * -5 per 7 days of silence (BR-SCORE-01).
     *
     * Computed, never stored as signals: decay is the ABSENCE of events, and
     * inventing rows for it would corrupt the very explanation the score is
     * supposed to provide.
     *
     * Measured from `last_engagement_at` where there is one, and otherwise from
     * the most recent signal of any kind. That fallback matters: a lead we sent
     * a proposal to six months ago who never replied has no *engagement* at all
     * - `last_engagement_at` tracks inbound signals only - and without it their
     * score would sit at 20 for ever, which is precisely the stale-pipeline
     * problem decay exists to solve.
     */
    private function decayFor(Lead $lead): float
    {
        $since = $lead->last_engagement_at
            ?? InterestSignal::where('lead_id', $lead->id)->max('occurred_at');

        if ($since === null) {
            return 0.0;
        }

        $since = $since instanceof \DateTimeInterface ? Carbon::instance($since) : Carbon::parse($since);

        $every = max(1, (int) config('crm.scoring.decay_every_days', 7));
        $points = (float) config('crm.scoring.decay_points', -5);

        $periods = intdiv((int) $since->diffInDays(now()), $every);

        return $periods * $points;
    }

    /** AI signals are weighted by the confidence the model reported (BR-INT-04). */
    private function pointsFor(InterestSignalType $type, InterestSignal $signal): float
    {
        $base = $type->points();

        if ($type->isAiDetected() && $signal->confidence !== null) {
            return $base * (float) $signal->confidence;
        }

        return $base;
    }
}
