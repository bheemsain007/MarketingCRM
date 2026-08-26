<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DataScope;
use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leads\StoreLeadExportRequest;
use App\Http\Resources\LeadExportResource;
use App\Models\LeadExport;
use App\Services\Leads\LeadExportService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk lead CSV export (FR-LEAD-12, SEC-PII-04).
 *
 * Every route additionally carries `permission:leads.export`, which is in
 * `Permission::isAudited()` - `EnsurePermission` writes the audit entry for
 * every request, list read and download BEFORE this controller runs
 * (SEC-AUD-02). Nothing here logs a second time.
 */
class LeadExportController extends Controller
{
    public function __construct(private readonly LeadExportService $exports) {}

    /**
     * Accepts a filter set and queues the run.
     *
     * 202, never 200 - like lead import, the request has accepted the job,
     * not finished it (API_DOCUMENTATION §5, NFR-06). The client polls
     * `index`/the returned id for progress.
     */
    public function store(StoreLeadExportRequest $request): JsonResponse
    {
        $export = $this->exports->request($request->user(), $request->exportFilters());

        $export->load('requester');

        return ApiResponse::accepted(
            new LeadExportResource($export),
            'Export accepted. Poll this record for progress.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeadExport::class);

        $options = new QueryOptions(
            $request,
            allowedFilters: ['status', 'created_at'],
            allowedSorts: ['created_at', 'completed_at'],
        );

        $query = LeadExport::query()
            ->with('requester')
            ->where('tenant_id', config('crm.default_tenant_id'));

        // Mirrors LeadExportPolicy::view() - the list must not show what the
        // record endpoint would refuse (SEC-AUTHZ-03).
        if ($request->user()->dataScope() !== DataScope::All) {
            $query->where('user_id', $request->user()->id);
        }

        if (! $request->filled('sort')) {
            $query->latest('created_at')->orderByDesc('id');
        }

        $exports = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(LeadExportResource::collection($exports), 'Exports retrieved.');
    }

    /**
     * Streams the generated file (SEC-FILE-02/03).
     *
     * Refused with a 404 - not the file's absence dressed up as a 403 - for
     * every reason the bytes are not servable right now: not yet finished,
     * failed, or past its retention window. Only the ownership check (via the
     * policy) is a 403, because that is the one case where the export
     * genuinely exists and is simply not this caller's to read.
     */
    public function download(Request $request, LeadExport $export): StreamedResponse
    {
        $this->authorize('view', $export);

        if ($export->status !== ExportStatus::Completed || $export->file_path === null) {
            abort(404, 'This export has no completed file.');
        }

        if ($export->expires_at !== null && $export->expires_at->isPast()) {
            abort(404, 'This export has expired.');
        }

        $disk = Storage::disk((string) $export->disk);

        if (! $disk->exists($export->file_path)) {
            abort(404, 'This export file is no longer available.');
        }

        // Content-Type set explicitly rather than left to extension/content
        // sniffing, which for a plain-text CSV is unreliable across OSes and
        // commonly resolves to `text/plain` instead.
        return $disk->download(
            $export->file_path,
            sprintf('leads-export-%d.csv', $export->id),
            ['Content-Type' => 'text/csv'],
        );
    }
}
