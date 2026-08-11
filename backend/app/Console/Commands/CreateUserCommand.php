<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates a CRM user with a role.
 *
 * The first Super Admin is created this way rather than seeded, because a seeded
 * admin means a known default password sitting in version control on every
 * install - the single most common way small deployments get breached.
 *
 *   php artisan crm:create-user --role=super_admin
 */
class CreateUserCommand extends Command
{
    protected $signature = 'crm:create-user
                            {--name= : Full name}
                            {--email= : Email address}
                            {--password= : Password (prompted securely if omitted)}
                            {--role= : Role name, e.g. super_admin}';

    protected $description = 'Create a CRM user and assign a role';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email address');
        $password = $this->option('password') ?: $this->secret('Password');

        $roleName = $this->option('role') ?: $this->choice(
            'Role',
            RoleName::values(),
            RoleName::Telecaller->value,
        );

        $validator = Validator::make(
            compact('name', 'email', 'password', 'roleName'),
            [
                'name' => ['required', 'string', 'max:150'],
                'email' => ['required', 'email', 'max:190', 'unique:users,email'],
                'password' => ['required', Password::min(8)->letters()->numbers()],
                'roleName' => ['required', 'in:'.implode(',', RoleName::values())],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::where('name', $roleName)->first();

        if (! $role) {
            $this->error("Role '{$roleName}' not found. Run: php artisan db:seed --class=RolePermissionSeeder");

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $user->roles()->attach($role->id);

        $this->info("Created {$user->email} with role {$role->label}.");

        return self::SUCCESS;
    }
}
