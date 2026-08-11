<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\StoreLeadRequest;
use App\Http\Requests\Leads\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Services\Leads\LeadService;
use App\Support\ApiResponse;
use App\Support\PhoneNumber;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Leads (Phase 6) - the core of the CRM.
 *
 * Every read path applies the caller's data scope; every single-record path
 * additionally runs LeadPolicy. Both are required: the scope hides records from
 * lists, the policy stops them being fetched by id (SEC-AUTHZ-03/04).
 */
class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Lead::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: [
                'status', 'temperature', 'priority', 'assigned_to',
                'lead_source_id', 'campaign_id', 'city', 'state',
                'is_suppressed', 'created_at', 'last_contacted_at',
            ],
            allowedSorts: [
                'created_at', 'updated_at', 'name', 'priority', 'score',
                'last_contacted_at', 'last_engagement_at',
            ],
            allowedIncludes: ['source', 'assignedUser', 'tags', 'leadProducts.product'],
        );

        $query = Lead::query()
            ->with(['source', 'assignedUser'])
            ->withCount(['notes', 'calls']);

        // Data scope applied BEFORE any client filter, so a caller can narrow
        // their view but never widen it (SEC-AUTHZ-03).
        $request->user()->applyDataScope($query);

        if ($request->boolean('with_archived')) {
            $query->withTrashed();
        }

        if ($search = $request->query('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('phone_e164', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (! $request->filled('sort')) {
            $query->latest('created_at');
        }

        $leads = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(LeadResource::collection($leads), 'Leads retrieved.');
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $lead->load(['source', 'assignedUser', 'tags', 'leadProducts.product'])
            ->loadCount(['notes', 'calls']);

        return ApiResponse::success(new LeadResource($lead));
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['product_ids', 'tag_ids', 'note', 'alt_phone']);

        if ($request->filled('alt_phone')) {
            $data['alt_phone_e164'] = PhoneNumber::normalise($request->input('alt_phone'));
        }

        $lead = $this->leads->create($data, $request->user()->id);

        if ($ids = $request->input('product_ids')) {
            foreach ($ids as $productId) {
                $lead->leadProducts()->create(['product_id' => $productId]);
            }
        }

        if ($ids = $request->input('tag_ids')) {
            $lead->tags()->syncWithPivotValues($ids, ['tagged_by' => $request->user()->id]);
        }

        if ($note = $request->input('note')) {
            $lead->notes()->create(['user_id' => $request->user()->id, 'body' => $note]);
        }

        $lead->load(['source', 'assignedUser', 'tags', 'leadProducts.product']);

        return ApiResponse::created(new LeadResource($lead), 'Lead created.');
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        $data = $request->safe()->except('alt_phone');

        /*
         * `alt_phone` is a request field, not a column - the column is
         * `alt_phone_e164`. Passing it straight through reached
         * preventSilentlyDiscardingAttributes as a 500 in dev, and would have
         * dropped the value silently in production. store() already did this
         * translation; update() did not.
         *
         * A supplied-but-empty value clears the number, which is the only way
         * to remove an alternate contact once one has been recorded.
         */
        if ($request->has('alt_phone')) {
            $data['alt_phone_e164'] = $request->filled('alt_phone')
                ? PhoneNumber::normalise($request->input('alt_phone'))
                : null;
        }

        $lead = $this->leads->update($lead, $data, $request->user()->id);

        $lead->load(['source', 'assignedUser', 'tags', 'leadProducts.product']);

        return ApiResponse::success(new LeadResource($lead), 'Lead updated.');
    }

    /** Archives (soft delete) - the lead and its history remain restorable. */
    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('archive', $lead);

        $this->leads->archive($lead, $request->user()->id, $request->input('reason'));

        return ApiResponse::success(message: 'Lead archived.');
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $lead = Lead::withTrashed()->findOrFail($id);

        $this->authorize('restore', $lead);

        return ApiResponse::success(
            new LeadResource($this->leads->restore($lead, $request->user()->id)),
            'Lead restored.',
        );
    }
}
