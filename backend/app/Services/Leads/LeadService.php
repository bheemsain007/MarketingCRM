<?php

namespace App\Services\Leads;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\Attendance\AttendancePingService;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Lead lifecycle (FR-LEAD-01..11, BR-DUP-01/02).
 *
 * Everything that creates a lead - manual entry, CSV import, a Meta webhook -
 * comes through here, so phone normalisation, duplicate detection and timeline
 * writing cannot be skipped by one entry path and not another.
 *
 * Duplicate handling is phone-first: the unique index makes a second lead for
 * the same number impossible, so `guardDuplicate` exists only to turn that into
 * an actionable 409 rather than a constraint violation.
 *
 * Email is a SECONDARY signal and is treated as one (BR-DUP-03). A shared
 * address flags a review candidate and nothing more - two people at one company
 * legitimately share `info@`, and merging on that evidence fuses distinct
 * humans. Merging itself lives in LeadMergeService (BR-DUP-04).
 */
class LeadService
{
    public function __construct(
        private readonly LeadAssignmentService $assignment,
        private readonly DuplicateDetector $duplicates,
        private readonly AttendancePingService $attendancePings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ApiException when the phone is unusable or already belongs to a lead
     */
    public function create(array $data, ?int $actorId = null, bool $autoAssign = false): Lead
    {
        $raw = (string) ($data['phone_e164'] ?? $data['phone'] ?? '');
        $e164 = PhoneNumber::normalise($raw);

        if ($e164 === null) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'The phone number is not valid.',
                errors: [[
                    'field' => 'phone',
                    'code' => ErrorCode::ValidationFailed->value,
                    'message' => 'Enter a valid 10-digit mobile number.',
                ]],
            );
        }

        $this->guardDuplicate($e164);

        // The inbound key may be `phone` (API) or `phone_e164` (import/webhook).
        // Both are replaced by the normalised value; leaving `phone` in place
        // would trip mass-assignment protection, since it is not a real column.
        unset($data['phone']);

        return DB::transaction(function () use ($data, $e164, $raw, $actorId, $autoAssign) {
            $lead = Lead::create(array_merge($data, [
                'tenant_id' => config('crm.default_tenant_id'),
                'phone_e164' => $e164,
                'phone_raw' => $raw,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]));

            $this->recordActivity($lead, $actorId, 'lead_created', 'Lead created');

            /*
             * BR-DUP-03: a shared email with a different phone is flagged for
             * review, never auto-merged. Deliberately after creation and never
             * blocking it - the cost of losing a real enquiry is higher than
             * the cost of reviewing two records later.
             */
            $this->duplicates->check($lead);

            if ($autoAssign) {
                $this->assignment->autoAssign($lead, $actorId);
            }

            return $lead->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Lead $lead, array $data, ?int $actorId = null): Lead
    {
        // A phone change re-runs normalisation and duplicate detection: the new
        // number may already belong to somebody else.
        if (array_key_exists('phone_e164', $data) || array_key_exists('phone', $data)) {
            $raw = (string) ($data['phone_e164'] ?? $data['phone']);
            $e164 = PhoneNumber::normalise($raw);

            if ($e164 === null) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    'The phone number is not valid.',
                );
            }

            if ($e164 !== $lead->phone_e164) {
                $this->guardDuplicate($e164, $lead->id);
                $data['phone_raw'] = $raw;
            }

            $data['phone_e164'] = $e164;
            unset($data['phone']);
        }

        $data['updated_by'] = $actorId;

        return DB::transaction(function () use ($lead, $data, $actorId) {
            $lead->update($data);

            $this->recordActivity($lead, $actorId, 'lead_updated', 'Lead details updated');

            return $lead->fresh();
        });
    }

    /**
     * Archive = soft delete (FR-LEAD-01).
     *
     * Archived leads leave every list AND all outbound targeting, but remain
     * restorable with their full history.
     */
    public function archive(Lead $lead, ?int $actorId = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($lead, $actorId, $reason) {
            $this->recordActivity($lead, $actorId, 'lead_archived', 'Lead archived', [
                'reason' => $reason,
            ]);

            $lead->delete();
        });
    }

    public function restore(Lead $lead, ?int $actorId = null): Lead
    {
        $lead->restore();

        $this->recordActivity($lead, $actorId, 'lead_restored', 'Lead restored');

        return $lead->fresh();
    }

    /**
     * Finds an existing lead for a phone number, including archived ones.
     *
     * Import and webhook paths use this to decide "merge into existing" rather
     * than triggering the create-path exception.
     */
    public function findByPhone(string $phone): ?Lead
    {
        $e164 = PhoneNumber::normalise($phone);

        if ($e164 === null) {
            return null;
        }

        return Lead::withTrashed()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('phone_e164', $e164)
            ->first();
    }

    /**
     * BR-DUP-02: never silently insert a second lead for the same person.
     *
     * The database enforces this too via a unique index; this check exists to
     * return an actionable 409 - including the owning telecaller, so the caller
     * knows who to speak to rather than just being refused.
     */
    private function guardDuplicate(string $e164, ?int $ignoreLeadId = null): void
    {
        $existing = Lead::withTrashed()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('phone_e164', $e164)
            ->when($ignoreLeadId, fn ($q) => $q->where('id', '!=', $ignoreLeadId))
            ->first();

        if (! $existing) {
            return;
        }

        throw new ApiException(
            ErrorCode::LeadDuplicate,
            $existing->trashed()
                ? 'An archived lead already exists with this phone number.'
                : 'A lead with this phone number already exists.',
            context: [
                'existing_lead_id' => $existing->id,
                'existing_lead_name' => $existing->name,
                'archived' => $existing->trashed(),
                // Tells the caller who already owns the relationship
                // (BR-ASSIGN-05).
                'assigned_to' => $existing->assigned_to,
            ],
        );
    }

    /**
     * Writes the user-facing timeline (FR-LEAD-09), and - for the activity
     * types FR-ATT-02 tracks (calls, notes, status changes, sends,
     * follow-ups) - an attendance signal alongside it. One call site for both
     * means every caller of recordActivity() feeds attendance automatically,
     * the same reasoning that keeps lead creation itself to one path.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordActivity(
        Lead $lead,
        ?int $actorId,
        string $type,
        string $title,
        array $meta = [],
        ?string $description = null,
    ): LeadActivity {
        $activity = $lead->activities()->create([
            'user_id' => $actorId,
            'activity_type' => $type,
            'title' => $title,
            'description' => $description,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);

        $this->attendancePings->recordForLeadActivity($actorId, $type, $lead);

        return $activity;
    }
}
