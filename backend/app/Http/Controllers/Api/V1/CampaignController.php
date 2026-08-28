<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Campaigns\UpdateCampaignRequest;
use App\Http\Resources\CampaignRecipientResource;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campaigns (Phase 18, FR-CAMP-01..05).
 *
 * Thin: HTTP translation only, rules live in CampaignService (ARCHITECTURE §2).
 *
 * Starting a campaign returns `202`, never `200`. The request queues the work
 * and returns - a campaign of any size must complete without an HTTP timeout
 * (FR-CAMP-05), so nothing here waits for a send.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'channel', 'product_id'],
            allowedSorts: ['created_at', 'scheduled_at', 'started_at', 'name'],
            allowedIncludes: ['template'],
        );

        $query = Campaign::query()->withCount('recipients');

        if (! $request->filled('sort')) {
            $query->latest('created_at');
        }

        return ApiResponse::paginated(
            CampaignResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Campaigns retrieved.',
        );
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(
            new CampaignResource($campaign->loadCount('recipients')),
            'Campaign retrieved.',
        );
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaigns->create($request->validated(), $request->user());

        return ApiResponse::created(new CampaignResource($campaign), 'Campaign created.');
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $campaign = $this->campaigns->update($campaign, $request->validated(), $request->user());

        return ApiResponse::success(new CampaignResource($campaign), 'Campaign updated.');
    }

    /**
     * Audience size and eligibility, without sending anything.
     *
     * "12,000 leads" and "12,000 leads of whom 4,000 are suppressed" are
     * different decisions, and finding that out after pressing send is too late.
     */
    public function preview(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success($this->campaigns->preview($campaign), 'Audience previewed.');
    }

    /**
     * Carries the unkeyed-channel caveat, exactly as `MessageController::store()`
     * does for a single send: an unconfigured channel falls back to the log
     * driver and records every message as sent without delivering any of it.
     * The moment to learn that is before a 50,000-lead audience goes out, not
     * from a report of sends nobody received (FR-COMM-05).
     */
    public function start(Request $request, Campaign $campaign): JsonResponse
    {
        $campaign = $this->campaigns->start($campaign, $request->user());
        $caveat = $this->campaigns->deliveryCaveat($campaign);

        return ApiResponse::accepted(
            new CampaignResource($campaign),
            'Campaign started. Recipients are being queued.'.($caveat !== null ? ' '.$caveat : ''),
        );
    }

    public function pause(Request $request, Campaign $campaign): JsonResponse
    {
        $campaign = $this->campaigns->pause($campaign, $request->user());

        return ApiResponse::success(
            new CampaignResource($campaign),
            'Campaign paused. Messages already handed to the provider will still complete.',
        );
    }

    public function stop(Request $request, Campaign $campaign): JsonResponse
    {
        $campaign = $this->campaigns->stop($campaign, $request->user());

        return ApiResponse::success(
            new CampaignResource($campaign),
            'Campaign stopped. This is final - clone it to run it again.',
        );
    }

    public function clone(Request $request, Campaign $campaign): JsonResponse
    {
        return ApiResponse::created(
            new CampaignResource($this->campaigns->clone($campaign, $request->user())),
            'Campaign cloned as a draft.',
        );
    }

    /**
     * Per-recipient outcomes (FR-CAMP-04).
     *
     * Filterable by skip reason, because "why did 3,800 leads not get this?" is
     * the question a campaign report exists to answer.
     */
    public function recipients(Request $request, Campaign $campaign): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'skip_reason'],
            allowedSorts: ['processed_at', 'created_at'],
            allowedIncludes: ['lead', 'message'],
        );

        $query = CampaignRecipient::query()->where('campaign_id', $campaign->id);

        return ApiResponse::paginated(
            CampaignRecipientResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Campaign recipients retrieved.',
        );
    }
}
