<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Break-glass reset of another administrator's 2FA (SEC-AUTH-07, T-09).
 *
 * Somebody has to hold this. An admin who loses their phone and their printed
 * recovery codes is otherwise locked out for good: the password reset flow
 * (SEC-AUTH-06) sets a password and the challenge still stands behind it, and
 * there is deliberately no screen that sets another person's password.
 *
 * Two gates, not one. `roles.manage` on the route is the coarse filter, and
 * TwoFactorService::resetFor() re-checks Super Admin on the model itself -
 * because a route middleware protects a URL while the service protects the
 * action, and this action is the most abusable one in the module: whoever
 * performs it can then take the account over with a password reset. Both users
 * are audited (SEC-AUD-02).
 */
class TwoFactorAdminController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->twoFactor->resetFor($request->user(), $user, $request);

        return ApiResponse::success(
            ['user_id' => $user->id, 'two_factor_enabled' => false],
            'Two-factor authentication has been reset. The user should enrol a new device.',
        );
    }
}
