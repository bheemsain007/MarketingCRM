<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lead assignment (FR-LEAD-08/10, BR-ASSIGN-01..05).
 *
 * Separate from LeadController because assignment is a supervisory act with its
 * own permission - a telecaller must not be able to push a difficult lead onto
 * a colleague, or claim someone else's.
 */
class LeadAssignmentController extends Controller
{
    public function __construct(private readonly LeadAssignmentService $assignment) {}

    public function assign(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('assign', $lead);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $lead = $this->assignment->assign(
            $lead,
            User::findOrFail($validated['user_id']),
            $request->user()->id,
            'manual',
            $validated['reason'] ?? null,
        );

        return ApiResponse::success(
            new LeadResource($lead->load('assignedUser')),
            'Lead assigned.',
        );
    }

    public function unassign(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('assign', $lead);

        $lead = $this->assignment->unassign(
            $lead,
            $request->user()->id,
            $request->input('reason'),
        );

        return ApiResponse::success(new LeadResource($lead), 'Lead unassigned.');
    }

    /** Runs the configured strategy (load-balanced by default). */
    public function autoAssign(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('assign', $lead);

        $lead = $this->assignment->autoAssign($lead, $request->user()->id);

        return ApiResponse::success(
            new LeadResource($lead->load('assignedUser')),
            $lead->assigned_to
                ? 'Lead assigned.'
                : 'No eligible telecaller available; lead left unassigned.',
        );
    }

    /** Who can currently take work, with their open-lead counts. */
    public function eligibleAssignees(Request $request): JsonResponse
    {
        $this->authorize('assignAny', Lead::class);

        return ApiResponse::success(
            $this->assignment->eligibleAssignees()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'open_lead_count' => $u->open_lead_count,
            ])->values(),
        );
    }

    /** Assignment history - the source for conversion attribution. */
    public function history(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $history = $lead->assignments()->with(['assignee:id,name', 'assigner:id,name'])->get();

        return ApiResponse::success($history->map(fn ($a) => [
            'id' => $a->id,
            'assigned_to' => $a->assignee?->only(['id', 'name']),
            'assigned_by' => $a->assigner?->only(['id', 'name']),
            'method' => $a->assignment_method,
            'assigned_at' => $a->assigned_at?->toIso8601String(),
            'unassigned_at' => $a->unassigned_at?->toIso8601String(),
            'reason' => $a->reason,
        ]));
    }
}
