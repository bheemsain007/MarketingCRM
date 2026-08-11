<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Authentication flows (SEC-AUTH-01..06, SEC-AUD-02, FR-ATT-01).
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role = RoleName::Telecaller, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => Hash::make('secret-password-1'),
        ], $attributes));

        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user;
    }

    #[Test]
    public function a_user_can_sign_in_and_receive_a_token(): void
    {
        $user = $this->user();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'token_type', 'work_session_id', 'user']])
            ->assertJsonPath('data.user.email', $user->email);
    }

    #[Test]
    public function signing_in_opens_a_work_session(): void
    {
        // FR-ATT-01: attendance must start automatically. If login could happen
        // without it, active/idle reporting would silently under-count.
        $user = $this->user();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ])->assertOk();

        $this->assertDatabaseHas('user_work_sessions', [
            'user_id' => $user->id,
            'ended_at' => null,
            'source' => 'web',
        ]);
    }

    #[Test]
    public function signing_in_twice_does_not_stack_work_sessions(): void
    {
        // Otherwise a user with web and mobile open double-counts logged-in time.
        $user = $this->user();

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'secret-password-1',
            ])->assertOk();
        }

        $this->assertSame(1, $user->workSessions()->whereNull('ended_at')->count());
    }

    #[Test]
    public function invalid_credentials_are_rejected_without_revealing_whether_the_account_exists(): void
    {
        // A different message for "no such user" is a user-enumeration oracle.
        $user = $this->user();

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(401);

        $noSuchUser = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(401);

        $this->assertSame($wrongPassword->json('message'), $noSuchUser->json('message'));
    }

    #[Test]
    public function a_failed_login_is_audited(): void
    {
        // SEC-AUD-02: needed to detect credential spraying.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'attacker@example.com',
            'password' => 'guess',
        ])->assertStatus(401);

        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed']);
    }

    #[Test]
    public function a_password_is_never_written_to_the_audit_log(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'attacker@example.com',
            'password' => 'sup3r-s3cret-value',
        ]);

        $logged = AuditLog::where('action', 'login_failed')->first();

        $this->assertStringNotContainsString('sup3r-s3cret-value', json_encode($logged->new_values));
    }

    #[Test]
    public function a_disabled_account_cannot_sign_in(): void
    {
        $user = $this->user(attributes: ['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ])->assertStatus(403);
    }

    #[Test]
    public function login_is_rate_limited(): void
    {
        // SEC-AUTH-03: brute-force protection.
        $limit = (int) config('crm.api.rate_limits.auth');

        for ($i = 0; $i <= $limit; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'target@example.com',
                'password' => 'guess-'.$i,
            ]);
        }

        $response->assertStatus(429);
    }

    #[Test]
    public function me_returns_the_authenticated_user_with_permissions(): void
    {
        $user = $this->user(RoleName::Telecaller);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.data_scope', 'own')
            ->assertJsonStructure(['data' => ['permissions', 'roles']]);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected_with_the_envelope(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonStructure(['success', 'message', 'data', 'errors'])
            ->assertJsonPath('errors.0.code', 'auth.unauthenticated');
    }

    #[Test]
    public function logging_out_revokes_only_the_presenting_token(): void
    {
        // SEC-AUTH-04: signing out on a phone must not sign the user out at
        // their desk.
        $user = $this->user();

        $deskToken = $user->createToken('desk')->plainTextToken;
        $phoneToken = $user->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$phoneToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$deskToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    #[Test]
    public function logout_all_revokes_every_token(): void
    {
        $user = $this->user();
        $user->createToken('desk');
        $token = $user->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    #[Test]
    public function changing_a_password_signs_out_other_devices(): void
    {
        // A password change usually means the old one may be compromised.
        $user = $this->user();
        $user->createToken('other-device');
        $token = $user->createToken('current')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'secret-password-1',
                'password' => 'new-password-99',
                'password_confirmation' => 'new-password-99',
            ])->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('new-password-99', $user->fresh()->password));
    }

    #[Test]
    public function changing_a_password_requires_the_current_one(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'wrong',
                'password' => 'new-password-99',
                'password_confirmation' => 'new-password-99',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('secret-password-1', $user->fresh()->password));
    }

    #[Test]
    public function a_successful_login_is_audited_and_stamps_last_login(): void
    {
        $user = $this->user();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password-1',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'login']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
