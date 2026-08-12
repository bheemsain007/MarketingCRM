<?php

namespace App\Services\FollowUps;

use App\Enums\ErrorCode;
use App\Enums\FollowUpStatus;
use App\Exceptions\ApiException;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up lifecycle (FR-FUP-01..05, BR-FUP-01..03).
 *
 * Every state change comes through here so the lead timeline, the notification
 * and the append-only reschedule chain cannot be skipped by one caller and not
 * another - the same reason lead creation goes through `LeadService`.
 */
class FollowUpService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LeadService $leads,
    ) {}

    /**
     * Schedules a follow-up, or reschedules the existing open one.
     *
     * BR-FUP-01: a lead-product pair has at most one OPEN follow-up. Two open
     * reminders for the same thing means one of them is wrong, and whichever
     * the telecaller acts on, the other becomes a false "missed" in the report.
     *
     * @param  array<string, mixed>  $data
     */
    public function schedule(Lead $lead, array $data, ?int $actorId = null): FollowUp
    {
        $scheduledAt = Carbon::parse($data['scheduled_at']);

        $existing = FollowUp::query()
            ->where('lead_id', $lead->id)
            ->where('product_id', $data['product_id'] ?? null)
            ->where('status', FollowUpStatus::Open->value)
            ->first();

        if ($existing !== null) {
            return $this->reschedule($existing, $scheduledAt, $actorId, $data['notes'] ?? null);
        }

        return DB::transaction(function () use ($lead, $data, $scheduledAt, $actorId) {
            $followUp = FollowUp::create([
                'tenant_id' => config('crm.default_tenant_id'),
                'lead_id' => $lead->id,
                'product_id' => $data['product_id'] ?? null,
                // Defaults to the lead's owner: a follow-up nobody is named on
                // is one nobody does.
                'assigned_to' => $data['assigned_to'] ?? $lead->assigned_to ?? $actorId,
                'channel' => $data['channel'] ?? 'call',
                'scheduled_at' => $scheduledAt,
                'status' => FollowUpStatus::Open->value,
                'subject' => $data['subject'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $this->leads->recordActivity(
                $lead,
                $actorId,
                'follow_up_scheduled',
                'Follow-up scheduled for '.$scheduledAt->format('d M Y H:i'),
                ['follow_up_id' => $followUp->id],
            );

            $this->notifyOwner($followUp, 'follow_up_assigned', 'New follow-up: '.$lead->name, $actorId);

            return $followUp->fresh();
        });
    }

    /**
     * Moves an open or missed follow-up to a new time.
     *
     * BR-FUP-03: the prior schedule is retained. A NEW row is created pointing
     * back at the old one through `rescheduled_from_id`, and the old row is
     * closed rather than edited - overwriting `scheduled_at` in place would
     * erase the evidence that a commitment was moved, which is precisely what
     * FR-FUP-04 asks the history to show.
     */
    public function reschedule(FollowUp $followUp, Carbon|string $to, ?int $actorId = null, ?string $notes = null): FollowUp
    {
        $this->guardActionable($followUp);

        $scheduledAt = $to instanceof Carbon ? $to : Carbon::parse($to);

        return DB::transaction(function () use ($followUp, $scheduledAt, $actorId, $notes) {
            $followUp->update([
                'status' => FollowUpStatus::Cancelled->value,
                'outcome' => 'Rescheduled to '.$scheduledAt->format('d M Y H:i'),
                'completed_by' => $actorId,
                'completed_at' => now(),
            ]);

            $replacement = FollowUp::create([
                'tenant_id' => $followUp->tenant_id,
                'lead_id' => $followUp->lead_id,
                'product_id' => $followUp->product_id,
                'assigned_to' => $followUp->assigned_to,
                'channel' => $followUp->channel?->value ?? 'call',
                'scheduled_at' => $scheduledAt,
                'status' => FollowUpStatus::Open->value,
                'subject' => $followUp->subject,
                'notes' => $notes ?? $followUp->notes,
                'rescheduled_from_id' => $followUp->id,
                'created_by' => $actorId,
            ]);

            if ($followUp->lead) {
                $this->leads->recordActivity(
                    $followUp->lead,
                    $actorId,
                    'follow_up_rescheduled',
                    'Follow-up moved to '.$scheduledAt->format('d M Y H:i'),
                    ['from_follow_up_id' => $followUp->id, 'follow_up_id' => $replacement->id],
                );
            }

            return $replacement->fresh();
        });
    }

    public function complete(FollowUp $followUp, ?string $outcome = null, ?int $actorId = null): FollowUp
    {
        // Deliberately allows completing a MISSED follow-up. It is late, not
        // void, and refusing would push people to create a fresh one - losing
        // the miss from the record (BR-FUP-02).
        $this->guardActionable($followUp);

        return DB::transaction(function () use ($followUp, $outcome, $actorId) {
            $followUp->update([
                'status' => FollowUpStatus::Completed->value,
                'completed_at' => now(),
                'completed_by' => $actorId,
                'outcome' => $outcome,
            ]);

            if ($followUp->lead) {
                $this->leads->recordActivity(
                    $followUp->lead,
                    $actorId,
                    'follow_up_completed',
                    'Follow-up completed',
                    ['follow_up_id' => $followUp->id, 'outcome' => $outcome],
                );
            }

            return $followUp->fresh();
        });
    }

    public function cancel(FollowUp $followUp, ?string $reason = null, ?int $actorId = null): FollowUp
    {
        $this->guardActionable($followUp);

        return DB::transaction(function () use ($followUp, $reason, $actorId) {
            $followUp->update([
                'status' => FollowUpStatus::Cancelled->value,
                'completed_at' => now(),
                'completed_by' => $actorId,
                'outcome' => $reason,
            ]);

            if ($followUp->lead) {
                $this->leads->recordActivity(
                    $followUp->lead,
                    $actorId,
                    'follow_up_cancelled',
                    'Follow-up cancelled',
                    ['follow_up_id' => $followUp->id, 'reason' => $reason],
                );
            }

            return $followUp->fresh();
        });
    }

    /**
     * Moves open follow-ups to a lead's new owner (BR-ASSIGN-04, T-14).
     *
     * Answering T-14's question as "yes, transfer": a follow-up left pointing at
     * the previous owner is one the new owner cannot see and the old owner has
     * no reason to do. The commitment belongs to whoever holds the lead.
     *
     * @return int how many moved
     */
    public function transferOpenFollowUps(Lead $lead, ?User $newOwner, ?int $actorId = null): int
    {
        $query = FollowUp::query()
            ->where('lead_id', $lead->id)
            ->whereIn('status', [FollowUpStatus::Open->value, FollowUpStatus::Missed->value]);

        $followUps = $query->get();

        foreach ($followUps as $followUp) {
            $followUp->update(['assigned_to' => $newOwner?->id]);

            if ($newOwner !== null) {
                $this->notifyOwner(
                    $followUp,
                    'follow_up_transferred',
                    'Follow-up transferred to you: '.($lead->name ?? 'lead'),
                    $actorId,
                );
            }
        }

        return $followUps->count();
    }

    /**
     * @throws ApiException when the follow-up is already closed
     */
    private function guardActionable(FollowUp $followUp): void
    {
        // Cast by the model, so always the enum - see FollowUpResource.
        $status = $followUp->status;

        if (! $status->isActionable()) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('This follow-up is already %s.', $status->label()),
            );
        }
    }

    private function notifyOwner(FollowUp $followUp, string $type, string $title, ?int $actorId): void
    {
        $owner = $followUp->assigned_to !== null ? User::find($followUp->assigned_to) : null;

        // Not notifying somebody about their own action - a telecaller who just
        // booked their own follow-up does not need telling.
        if ($owner === null || $owner->id === $actorId) {
            return;
        }

        $this->notifications->notify($owner, $type, $title, [
            'body' => 'Due '.$followUp->scheduled_at->format('d M Y H:i'),
            'reference' => $followUp,
            'action_url' => '/leads/'.$followUp->lead_id,
        ]);
    }
}
