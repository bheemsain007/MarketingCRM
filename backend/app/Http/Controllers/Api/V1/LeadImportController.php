<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\StoreLeadImportRequest;
use App\Http\Resources\LeadImportResource;
use App\Http\Resources\LeadImportRowResource;
use App\Models\LeadImport;
use App\Services\Leads\LeadImportService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk lead import (FR-LEAD-07).
 *
 * The upload returns 202, never 200: the request has accepted the file, not
 * finished the work (API_DOCUMENTATION §5). The client polls `show` for
 * progress and reads `rows` for the per-row outcome.
 */
class LeadImportController extends Controller
{
    public function __construct(private readonly LeadImportService $imports) {}

    /**
     * Accepts a file and queues it.
     *
     * 202 + a resource the client can poll. Nothing about the rows is known
     * yet, and pretending otherwise by blocking the request is exactly the
     * failure mode NFR-06 exists to prevent.
     */
    public function store(StoreLeadImportRequest $request): JsonResponse
    {
        $import = $this->imports->createFromUpload(
            $request->file('file'),
            $request->user(),
            $request->importOptions(),
        );

        $import->load('uploader');

        return ApiResponse::accepted(
            new LeadImportResource($import),
            'Import accepted. Poll this record for progress.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeadImport::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'uploaded_by', 'created_at'],
            allowedSorts: ['created_at', 'finished_at', 'total_rows'],
        );

        $query = LeadImport::query()
            ->with('uploader')
            ->where('tenant_id', config('crm.default_tenant_id'));

        // Mirrors LeadImportPolicy::view() - the list must not show what the
        // record endpoint would refuse (SEC-AUTHZ-03).
        if ($request->user()->dataScope() !== DataScope::All) {
            $query->where('uploaded_by', $request->user()->id);
        }

        if (! $request->filled('sort')) {
            // id breaks the tie: two files uploaded in the same second must
            // still list in a stable, genuinely newest-first order.
            $query->latest('created_at')->orderByDesc('id');
        }

        $imports = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(LeadImportResource::collection($imports), 'Imports retrieved.');
    }

    public function show(Request $request, LeadImport $leadImport): JsonResponse
    {
        $this->authorize('view', $leadImport);

        $leadImport->load('uploader');

        return ApiResponse::success(new LeadImportResource($leadImport));
    }

    /**
     * The per-row report.
     *
     * Paginated and filterable, because the only question worth asking of a
     * 50,000-row import is "show me the ones that did not work":
     * `?filter[status]=invalid`.
     */
    public function rows(Request $request, LeadImport $leadImport): JsonResponse
    {
        $this->authorize('view', $leadImport);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'row_number'],
            allowedSorts: ['row_number', 'status'],
        );

        $query = $leadImport->rows()->getQuery()->orderBy('row_number');

        $rows = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(
            LeadImportRowResource::collection($rows),
            'Import rows retrieved.',
            extraMeta: [
                'import_id' => $leadImport->id,
                'import_status' => $leadImport->status->value,
            ],
        );
    }
}
