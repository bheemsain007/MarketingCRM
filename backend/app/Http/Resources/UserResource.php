<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone_e164,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'team' => $this->whenLoaded('team', fn () => [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'name' => $role->name,
                'label' => $role->label,
            ])),
            // The client uses these to hide controls it cannot use. That is a
            // usability aid only - the server enforces the same rules on every
            // request (SEC-AUTHZ-02).
            'permissions' => $this->permissionNames(),
            'data_scope' => $this->dataScope()->value,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
