<?php

namespace App\Services\Leads;

use App\Enums\ErrorCode;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Exceptions\ApiException;
use App\Models\Lead;
use App\Models\User;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lead assignment (FR-LEAD-08/10/11, BR-ASSIGN-01..05).
 *
 * Assignment strategy is configuration, not code (config/crm.php), because the
 * method and the open-lead cap are still proposals awaiting sign-off (T-13).
 */
class LeadAssignmentService
{
    /**
     * `FollowUpService` is resolved lazily, not constructor-injected.
     *
     * There is a genuine cycle: this service needs follow-ups (to transfer them
     * on reassignment), `FollowUpService` needs `LeadService` (to write the
     * lead timeline), and `LeadService` needs this one (to auto-assign on
     * create). Constructor-injecting all three exhausts memory in the container
     * before anything runs.
     *
     * Resolving at call time breaks the cycle without pretending one of those
     * three dependencies is not real. The honest alternative - extracting the
     * timeline writer out of `LeadService` so `FollowUpService` no longer needs
     * it - is a wider refactor than reassignment warrants.
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Assign to a specific user.
     *
     * @throws ApiException when the target cannot hold leads
     */
    public function assign(Lead $lead, User $assignee, ?int $actorId = null, string $method = 'manual', ?string $reason = null): Lead
    {
        $this->guardAssignable($assignee);

        return DB::transaction(function () use ($lead, $assignee, $actorId, $method, $reason) {
            // Close the previous assignment rather than overwriting it - the
            // history table is the source for conversion attribution
            // (GLOSSARY §2.6).
            $lead->assignments()->whereNull('unassigned_at')->update(['unassigned_at' => now()]);

            $lead->assignments()->create([
                'assigned_to' => $assignee->id,
                'assigned_by' => $actorId,
                'assignment_method' => $method,
                'assigned_at' => now(),
                'reason' => $reason,
            ]);

            $previousOwner = $lead->assigned_to;

            $lead->forceFill([
                'assigned_to' => $assignee->id,
                'assigned_at' => now(),
            ])->save();

            $lead->activities()->create([
                'user_id' => $actorId,
                'activity_type' => 'assignment',
                'title' => 'Lead assigned to '.$assignee->name,
                'meta' => [
                    'from_user_id' => $previousOwner,
                    'to_user_id' => $assignee->id,
                    'method' => $method,
                ],
                'occurred_at' => now(),
            ]);

            // BR-ASSIGN-04 / T-14: open follow-ups move with the lead. One left
            // pointing at the previous owner is invisible to the new owner and
            // meaningless to the old one - the commitment belongs to whoever
            // holds the relationship.
            $this->container->make(FollowUpService::class)->transferOpenFollowUps($lead, $assignee, $actorId);

            $this->notify($assignee, $lead);

            return $lead->fresh();
        });
    }

    /**
     * Automatic assignment for inbound/webhook leads (FR-LEAD-10).
     *
     * Returns the lead unchanged when nobody is eligible. The lead is left
     * unassigned in a queue rather than force-assigned to an overloaded or
     * off-shift agent (BR-ASSIGN-02) - a lead nobody calls is recoverable, a
     * lead buried in an overloaded queue is not.
     */
    public function autoAssign(Lead $lead, ?int $actorId = null): Lead
    {
        // BR-ASSIGN-05: a repeat enquiry goes back to whoever already owns the
        // relationship, so two agents never call the same person.
        if ($lead->assigned_to !== null) {
            return $lead;
        }

        $method = config('crm.assignment.method', 'load_balanced');

        $assignee = match ($method) {
            'round_robin' => $this->nextRoundRobin(),
            'load_balanced' => $this->leastLoaded(),
            default => null,   // 'manual' - deliberately assigns nobody
        };

        if (! $assignee) {
            $lead->activities()->create([
                'user_id' => $actorId,
                'activity_type' => 'assignment',
                'title' => 'Awaiting assignment - no eligible telecaller',
                'meta' => ['method' => $method],
                'occurred_at' => now(),
            ]);

            return $lead;
        }

        return $this->assign($lead, $assignee, $actorId, $method);
    }

    public function unassign(Lead $lead, ?int $actorId = null, ?string $reason = null): Lead
    {
        return DB::transaction(function () use ($lead, $actorId, $reason) {
            $lead->assignments()->whereNull('unassigned_at')->update([
                'unassigned_at' => now(),
                'reason' => $reason,
            ]);

            $lead->forceFill(['assigned_to' => null, 'assigned_at' => null])->save();

            $lead->activities()->create([
                'user_id' => $actorId,
                'activity_type' => 'assignment',
                'title' => 'Lead unassigned',
                'meta' => ['reason' => $reason],
                'occurred_at' => now(),
            ]);

            return $lead->fresh();
        });
    }

    /**
     * Telecallers eligible to receive work (BR-ASSIGN-02): active, able to work
     * leads, and below the open-lead cap.
     *
     * @return Collection<int, User>
     */
    public function eligibleAssignees()
    {
        $cap = (int) config('crm.assignment.open_lead_cap', 150);

        return User::query()
            ->active()
            ->whereHas('roles', fn ($q) => $q->where('name', RoleName::Telecaller->value))
            ->withCount(['assignedLeads as open_lead_count' => fn ($q) => $q->whereNotIn('status', [
                'converted', 'lost', 'not_interested',
            ])])
            ->get()
            ->filter(fn (User $u) => $u->open_lead_count < $cap)
            ->values();
    }

    /**
     * Fewest open leads wins - better than round-robin when agents work at
     * different speeds, because it responds to actual load rather than turn
     * order.
     */
    private function leastLoaded(): ?User
    {
        return $this->eligibleAssignees()->sortBy('open_lead_count')->first();
    }

    /**
     * Strict rotation: the eligible agent who has waited longest since their
     * last assignment.
     */
    private function nextRoundRobin(): ?User
    {
        $eligible = $this->eligibleAssignees();

        if ($eligible->isEmpty()) {
            return null;
        }

        return $eligible
            ->sortBy(fn (User $u) => $u->assignedLeads()->max('assigned_at') ?? '')
            ->first();
    }

    private function guardAssignable(User $user): void
    {
        if (! $user->is_active) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Leads cannot be assigned to a disabled account.',
            );
        }

        // "Can work leads" means can UPDATE them, not merely view them. Almost
        // every role can view - Accounts and Viewer included - so checking view
        // would happily park a lead with someone who is read-only and cannot
        // action it.
        if (! $user->hasPermission(Permission::LeadsUpdate)) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'That user does not have permission to work leads.',
            );
        }
    }

    private function notify(User $assignee, Lead $lead): void
    {
        $assignee->crmNotifications()->create([
            'type' => 'lead_assigned',
            'title' => 'New lead assigned: '.$lead->name,
            'body' => $lead->company ? 'Company: '.$lead->company : null,
            'channel' => 'in_app',
            'reference_type' => Lead::class,
            'reference_id' => $lead->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
