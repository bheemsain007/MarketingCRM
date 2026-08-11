<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authentication endpoints (Phase 4).
 *
 * Thin by design: no business logic here, only HTTP translation
 * (ARCHITECTURE §2).
 */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request,
            $request->string('source', 'web')->toString(),
        );

        $user = $result['user']->load(['roles.permissions', 'team']);

        return ApiResponse::success([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'work_session_id' => $result['session']->id,
            'user' => new UserResource($user),
        ], 'Signed in successfully.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user(), $request);

        return ApiResponse::success(message: 'Signed out.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->auth->logoutEverywhere($request->user(), $request);

        return ApiResponse::success(message: 'Signed out of all devices.');
    }

    /** The client calls this on boot to learn who it is and what it may do. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles.permissions', 'team']);

        return ApiResponse::success(new UserResource($user));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->auth->changePassword(
            $request->user(),
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
            $request,
        );

        return ApiResponse::success(
            message: 'Password changed. Other devices have been signed out.',
        );
    }
}
