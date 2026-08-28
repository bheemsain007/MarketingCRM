<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * User administration (T-51, ROLE-01..07, SEC-AUTHZ-05).
 *
 * Note which permission guards what: `users.manage` creates and edits people,
 * `roles.manage` decides what they may do. Those are different powers and are
 * held by different roles - Admin has the first and only Super Admin has the
 * second - so an Admin can onboard a telecaller but cannot promote one.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['is_active', 'team_id'],
            allowedSorts: ['name', 'created_at', 'last_login_at'],
            allowedIncludes: ['roles', 'team'],
        );

        /*
         * The role select must cover everything UserResource reads, not just
         * what the list "looks like" it needs. `label` and `data_scope` are
         * both read there, and a column that was never selected comes back
         * null rather than failing - so narrowing this to (id, name) made
         * every colleague render with a blank role label and, because
         * `dataScope()` falls back to Own when no scope is present, as scoped
         * to their own records. The list then disagreed with the same user
         * fetched by id, which on an authorization display is a lie, not a
         * cosmetic gap (SEC-AUTHZ-01).
         *
         * `roles.permissions` is loaded here too because the resource asks for
         * `permissionNames()` on every row; without it each row resolves its
         * own permissions one query at a time (ARCHITECTURE §2).
         */
        $query = User::query()->with([
            'roles:id,name,label,data_scope',
            'roles.permissions',
            'team:id,name',
        ]);

        if ($search = $request->query('q')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if (! $request->filled('sort')) {
            $query->orderBy('name');
        }

        return ApiResponse::paginated(
            UserResource::collection($options->applyTo($query)->paginate($options->perPage())),
            'Users retrieved.',
        );
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success(new UserResource($user->load(['roles.permissions', 'team'])));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            // Same strength rule as a self-service change - an account created
            // by an admin is not a lesser account.
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ]);

        $user = $this->users->create($validated, $request->user());

        return ApiResponse::created(
            new UserResource($user->load('roles')),
            'User created. A Super Admin must assign a role before they can do anything.',
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'filled', 'string', 'max:150'],
            'email' => ['sometimes', 'filled', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_e164' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ]);

        return ApiResponse::success(
            new UserResource($this->users->update($user, $validated, $request->user())->load('roles')),
            'User updated.',
        );
    }

    /**
     * Replaces a user's roles. Super Admin only, and never your own
     * (SEC-AUTHZ-05).
     */
    public function setRoles(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', Rule::in(RoleName::values())],
        ]);

        return ApiResponse::success(
            new UserResource($this->users->setRoles($user, $validated['roles'], $request->user())),
            'Roles updated.',
        );
    }

    /** Disabling revokes tokens and closes the open work session. */
    public function disable(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->users->disable($user, $request->user())->load('roles')),
            'User disabled.',
        );
    }

    public function enable(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->users->enable($user, $request->user())->load('roles')),
            'User enabled.',
        );
    }
}
