<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\DataScope;
use App\Enums\DncReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dnc\RemoveDncEntryRequest;
use App\Http\Requests\Dnc\SkipLogRequest;
use App\Http\Requests\Dnc\StoreDncEntryRequest;
use App\Http\Resources\DncEntryResource;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Services\Dnc\DncService;
use App\Services\Reports\SuppressionReportService;
use App\Services\Reports\SuppressionSkipLog;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suppression administration (FR-DNC-01/04, BR-DNC-06).
 *
 * Phase 19 owns the DNC Engine, but its admin surface is brought forward for
 * the same reason `DncService` itself was (T-29, MODULE_STATUS sequencing
 * note): suppression is already being WRITTEN - by `Not Interested`, by call
 * outcomes - and until now nothing could read it back or lift it. A suppression
 * list that can only be added to is a compliance problem, not a feature gap.
 *
 * What Phase 19 still owns: policy configuration (BR-DNC-04), reporting, the
 * inbound-keyword source, and the full per-channel test matrix.
 */
class DncController extends Controller
{
    public function __construct(private readonly DncService $dnc) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DncEntry::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['reason', 'channel', 'source', 'active', 'lead_id'],
            allowedSorts: ['created_at', 'removed_at', 'reason'],
            allowedIncludes: ['lead', 'createdBy', 'removedBy'],
        );

        $query = DncEntry::query()->with(['lead:id,name', 'createdBy:id,name', 'removedBy:id,name']);

        /*
         * Scoped through the lead, so a telecaller sees suppression for their
         * own book and not the organisation's whole do-not-contact list -
         * which is a list of everyone who has ever refused us (SEC-AUTHZ-03).
         *
         * Entries with no lead - an inbound STOP from a number nobody has
         * imported - have no owner to scope by, so they are visible only at
         * All scope rather than being silently shown to everyone.
         */
        if ($request->user()->dataScope() !== DataScope::All) {
            $query->whereHas('lead', fn ($q) => $request->user()->applyDataScope($q));
        }

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('phone_e164', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('lead', fn ($lead) => $lead->where('name', 'like', "%{$search}%"));
            });
        }

        if (! $request->filled('sort')) {
            $query->latest('created_at');
        }

        $entries = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(DncEntryResource::collection($entries), 'Suppression entries retrieved.');
    }

    /**
     * Skip / suppression reporting (BR-DNC-05, FR-DNC-03).
     *
     * A send refused because a lead is suppressed must be visible, not a silent
     * nothing - this aggregates every such skip in the period by channel and
     * reason, plus a snapshot of who is on the list now.
     *
     * Unlike `index`, this is NOT data-scoped: these are non-identifying
     * aggregate counts, not the per-lead do-not-contact list, so the
     * SEC-AUTHZ-03 concern that scopes the list does not apply. It stays on
     * `dnc.view` because it is DNC-domain data; tighten to a Manager-only gate
     * later if the org-wide totals are judged sensitive.
     */
    public function skips(Request $request, SuppressionReportService $reports): JsonResponse
    {
        $this->authorize('viewAny', DncEntry::class);

        $period = ReportPeriod::fromRequest($request);

        return ApiResponse::success([
            'period' => $period->toArray(),
            'report' => $reports->report($period),
        ], 'Suppression report generated.');
    }

    /**
     * The row-level skip log behind that report (BR-DNC-05, FR-DNC-03).
     *
     * `skips` gives the totals; this names the people. A compliance question is
     * never "how many" - it is "did you contact this person after they asked
     * you not to", and only the log can answer it.
     *
     * Scoped through the lead, unlike the aggregate: these rows carry names and
     * phone numbers (SEC-AUTHZ-03).
     */
    public function skipLog(SkipLogRequest $request, SuppressionSkipLog $log): JsonResponse
    {
        $this->authorize('viewAny', DncEntry::class);

        $period = ReportPeriod::fromRequest($request);

        $attempts = $log->paginate(
            $request->user(),
            $period,
            $request->filters(),
            $request->filled('q') ? $request->string('q')->toString() : null,
            (new QueryOptions($request))->perPage(),
        );

        // The window travels with the rows so a screen can state which days it
        // is showing rather than implying "everything".
        return ApiResponse::paginated($attempts, 'Suppressed attempts retrieved.', ['period' => $period->toArray()]);
    }

    /**
     * Manual suppression - somebody phoned in and asked to be taken off the
     * list. The automatic triggers (BR-DNC-07) do not come through here.
     */
    public function store(StoreDncEntryRequest $request): JsonResponse
    {
        $lead = Lead::findOrFail($request->integer('lead_id'));

        // The lead-level policy, not a DNC-level one: suppressing a lead you
        // are not allowed to see would be a way to probe which ids exist.
        $this->authorize('view', $lead);
        $this->authorize('create', DncEntry::class);

        $entry = $this->dnc->suppress(
            $lead,
            DncReason::from($request->string('reason')->toString()),
            $request->filled('channel') ? Channel::from($request->string('channel')->toString()) : null,
            'manual',
            $request->user()->id,
            $request->input('note'),
        );

        return ApiResponse::created(
            new DncEntryResource($entry->load(['lead:id,name', 'createdBy:id,name'])),
            'Lead suppressed.',
        );
    }

    /**
     * Lifts a suppression (BR-DNC-06).
     *
     * DELETE in name only - the row is deactivated, never destroyed, so who
     * lifted it and why survives the action.
     */
    public function destroy(RemoveDncEntryRequest $request, DncEntry $dncEntry): JsonResponse
    {
        $this->authorize('remove', $dncEntry);

        $entry = $this->dnc->remove(
            $dncEntry,
            $request->string('reason')->toString(),
            $request->user()->id,
        );

        return ApiResponse::success(
            new DncEntryResource($entry->load(['lead:id,name', 'createdBy:id,name', 'removedBy:id,name'])),
            'Suppression removed.',
        );
    }
}
