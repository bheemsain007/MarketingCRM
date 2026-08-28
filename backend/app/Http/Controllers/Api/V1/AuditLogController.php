<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the compliance trail (SEC-AUD-01..04).
 *
 * `audit.view` has existed since the permission model was written and was
 * granted to Admin and Super Admin, and nothing in the application ever read
 * `audit_logs` - so the trail was a table rather than a control. An audit log
 * nobody can read answers no question, and the people who would ask are exactly
 * the ones who cannot open a database console.
 *
 * READ ONLY, and structurally so: this controller has one method, the route
 * group carries one verb, and `AuditLog` refuses writes through both the model
 * and its builder (SEC-AUD-01). There is no update or delete here to be gated
 * later - a mistaken entry is corrected by writing another one.
 *
 * No data scoping. Unlike the lead endpoints this is not a per-owner view:
 * `audit.view` is Admin and Super Admin only, and an audit trail filtered by
 * what the reader owns would hide precisely the entries an investigation is
 * looking for (SEC-AUD-04).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            // Column names, so the documented filter syntax covers the whole
            // requirement without a second filtering mechanism: actor is
            // `filter[user_id]`, the subject is `filter[auditable_type]` plus
            // `filter[auditable_id]`, and a date range is
            // `filter[created_at][between]=<from>,<to>`.
            allowedFilters: ['user_id', 'action', 'auditable_type', 'auditable_id', 'created_at'],
            allowedSorts: ['created_at', 'action'],
            allowedIncludes: [],
        );

        $query = AuditLog::query()->with('user:id,name');

        /*
         * Free-text over the action and the description, because a reader
         * looking for "that refund" knows neither the exact action key nor the
         * row id. Exact `filter[action]` stays available for machine callers.
         */
        if ($search = $request->query('q')) {
            $query->where(function ($builder) use ($search) {
                $builder->where('action', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Newest first: an audit trail is read backwards from the incident.
        if (! $request->filled('sort')) {
            $query->latest('created_at')->latest('id');
        }

        return ApiResponse::paginated(
            AuditLogResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Audit entries retrieved.',
        );
    }
}
