<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\Permission as PermissionEnum;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level permission gate (SEC-AUTHZ-02).
 *
 *     Route::get(...)->middleware('permission:leads.view');
 *     Route::post(...)->middleware('permission:leads.export,leads.import');  // any-of
 *
 * This is a coarse first gate. Record-level checks (does this telecaller own
 * THIS lead?) stay in policies and query scopes - a route-level check alone
 * would leave IDOR open (SEC-AUTHZ-04).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }

        if (! $user->hasAnyPermission($permissions)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'You do not have permission to perform this action.',
                context: ['required' => array_values($permissions)],
            );
        }

        $this->auditIfSensitive($request, $permissions);

        return $next($request);
    }

    /**
     * Sensitive permissions are logged whenever used, not only when refused
     * (SEC-AUD-02). Bulk export and recording access are the highest-value
     * insider-threat actions in a CRM, so legitimate use is recorded too.
     *
     * @param  array<int, string>  $permissions
     */
    private function auditIfSensitive(Request $request, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $enum = PermissionEnum::tryFrom($permission);

            if ($enum?->isAudited()) {
                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'permission_used',
                    'description' => $permission,
                    'new_values' => ['route' => $request->path(), 'method' => $request->method()],
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                ]);
            }
        }
    }
}
