<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadDuplicateCandidate;
use Illuminate\Support\Facades\DB;

/**
 * Secondary duplicate matching (BR-DUP-03, T-64).
 *
 * Phone is identity (BR-DUP-01) and is enforced by a unique index, so a second
 * lead with the same number cannot exist. Email is weaker evidence, and the
 * rule is explicit about what that means: **flagged for review, never
 * auto-merged.**
 *
 * The reason is not caution for its own sake. Two people at one company
 * legitimately share an address - `info@`, `accounts@`, a receptionist's inbox
 * - and colleagues routinely give the same one. Auto-merging on that evidence
 * fuses distinct humans, and a merge has no undo.
 */
class DuplicateDetector
{
    /**
     * Records a review candidate if this lead shares an email with another.
     *
     * Returns the candidate, or null when there is nothing to review. Called
     * after creation rather than during it: a possible duplicate must not block
     * a lead from being captured, because the cost of losing a real enquiry is
     * higher than the cost of reviewing two records later.
     */
    public function check(Lead $lead): ?LeadDuplicateCandidate
    {
        $email = trim((string) $lead->email);

        if ($email === '') {
            return null;
        }

        /*
         * Resolved rather than read straight off the model. `tenant_id` is a
         * database default, so a lead built without it explicitly has null in
         * memory - and `where('tenant_id', null)` becomes `IS NULL`, which
         * matches nothing and would silently find no duplicates at all.
         */
        $tenantId = $lead->tenant_id ?? config('crm.default_tenant_id');

        $other = Lead::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $lead->id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            // Different phone. The same phone is BR-DUP-02's job and cannot
            // reach here anyway - the unique index refuses it.
            ->where(fn ($q) => $q->whereNull('phone_e164')->orWhere('phone_e164', '!=', $lead->phone_e164))
            ->whereNull('merged_into_id')
            ->orderBy('id')
            ->first();

        if ($other === null) {
            return null;
        }

        /*
         * Ordered by id so (a, b) and (b, a) cannot both be recorded. The same
         * pair surfacing twice is two decisions to make about one question, and
         * a reviewer answering one of them would leave the other waiting.
         */
        [$first, $second] = $lead->id < $other->id ? [$lead, $other] : [$other, $lead];

        $existing = LeadDuplicateCandidate::query()
            ->where('lead_id', $first->id)
            ->where('duplicate_lead_id', $second->id)
            ->first();

        // Includes dismissed pairs: "these are different people" is an answer,
        // and re-asking it on every import is how a review queue becomes noise
        // nobody reads.
        if ($existing !== null) {
            return $existing;
        }

        return LeadDuplicateCandidate::create([
            'tenant_id' => $tenantId,
            'lead_id' => $first->id,
            'duplicate_lead_id' => $second->id,
            'match_type' => 'email',
            'match_value' => $email,
            'status' => 'pending',
        ]);
    }

    /**
     * Sweeps existing leads for shared emails.
     *
     * The detector only runs on creation, so leads captured before this existed
     * were never checked. A one-off backfill is the honest way to close that,
     * rather than pretending the queue is complete.
     *
     * @return int candidates created
     */
    public function backfill(): int
    {
        $created = 0;

        $shared = DB::table('leads')
            ->selectRaw('LOWER(email) as normalised')
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->groupBy('normalised')
            ->havingRaw('COUNT(DISTINCT phone_e164) > 1')
            ->pluck('normalised');

        foreach ($shared as $email) {
            $leads = Lead::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->whereNull('merged_into_id')
                ->orderBy('id')
                ->get();

            // Every pair, not just consecutive ones: three leads sharing an
            // address is three decisions, and only surfacing two of them would
            // leave a pair silently unreviewed.
            foreach ($leads as $i => $lead) {
                foreach ($leads->slice($i + 1) as $other) {
                    if ($lead->phone_e164 === $other->phone_e164) {
                        continue;
                    }

                    $candidate = LeadDuplicateCandidate::firstOrCreate([
                        'lead_id' => $lead->id,
                        'duplicate_lead_id' => $other->id,
                    ], [
                        'tenant_id' => $lead->tenant_id,
                        'match_type' => 'email',
                        'match_value' => $lead->email,
                        'status' => 'pending',
                    ]);

                    if ($candidate->wasRecentlyCreated) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }
}
