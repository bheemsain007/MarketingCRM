<?php

namespace Tests\Feature\Settings;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Settings and provider credentials (SEC-CFG-01/04/05, SEC-AUD-02).
 *
 * The valuable assertions here are the negative ones: that a secret never
 * leaves the server, that an Admin cannot set one, that an unknown key cannot
 * be written, and that clearing an override restores the shipped default rather
 * than nulling the setting.
 */
class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role->value)->first());

        return $user->fresh();
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = $this->user($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_sees_operational_settings_but_no_credential_group(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $groups = collect($this->getJson('/api/v1/settings')->assertOk()->json('data.groups'));

        // Admin deliberately lacks credentials.manage (SEC-AUTHZ-06).
        $this->assertTrue($groups->contains(fn ($g) => $g['group'] === 'assignment'));
        $this->assertFalse($groups->contains(fn ($g) => $g['group'] === 'payment'));
        $this->assertFalse($groups->contains(fn ($g) => $g['group'] === 'meta'));
    }

    #[Test]
    public function non_secret_provider_fields_are_still_credential_configuration(): void
    {
        $this->actingAsRole(RoleName::Admin);

        // An endpoint is not a secret, and repointing one sends every future
        // request somewhere of the caller's choosing. Gating only the fields
        // that happen to be secret would leave that open to an Admin.
        foreach ([
            // Naming follows .env.example, which is the deployment contract -
            // see EnvExampleTest.
            'providers.rcs.provider' => 'attacker-inc',
            'providers.payment.gateway' => 'stripe',
            'providers.meta.app_id' => '1234567890',
        ] as $key => $value) {
            $this->patchJson('/api/v1/settings', ['settings' => [$key => $value]])
                ->assertStatus(403);
        }

        $this->assertDatabaseCount('settings', 0);
    }

    #[Test]
    public function a_super_admin_sees_the_credential_groups(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);

        $groups = collect($this->getJson('/api/v1/settings')->assertOk()->json('data.groups'));

        $this->assertTrue($groups->contains(fn ($g) => $g['group'] === 'payment'));
    }

    #[Test]
    public function a_manager_cannot_open_settings_at_all(): void
    {
        $this->actingAsRole(RoleName::Manager);

        $this->getJson('/api/v1/settings')->assertStatus(403);
    }

    #[Test]
    public function values_shown_are_the_config_defaults_until_overridden(): void
    {
        $this->actingAsRole(RoleName::Admin);

        // The screen shows what is IN EFFECT, not only what somebody typed -
        // an empty settings table must not render as a page of blanks.
        $this->assertSame(150, config('crm.assignment.open_lead_cap'));

        $groups = collect($this->getJson('/api/v1/settings')->assertOk()->json('data.groups'));
        $setting = $groups->firstWhere('group', 'assignment')['settings'];
        $cap = collect($setting)->firstWhere('key', 'crm.assignment.open_lead_cap');

        $this->assertSame(150, $cap['value']);
    }

    // -----------------------------------------------------------------------
    // Secrets never leave the server (SEC-CFG-05)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_stored_secret_is_never_returned_by_the_api(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['providers.payment.key_secret' => 'sk_live_abcdefgh1234'],
        ])->assertOk();

        $response = $this->getJson('/api/v1/settings')->assertOk();

        // Not in the payload anywhere, under any key.
        $response->assertDontSee('sk_live_abcdefgh1234');

        $payment = collect($response->json('data.groups'))->firstWhere('group', 'payment')['settings'];
        $secret = collect($payment)->firstWhere('key', 'providers.payment.key_secret');

        $this->assertNull($secret['value']);
        $this->assertTrue($secret['is_configured']);
        $this->assertSame('••••1234', $secret['hint']);
    }

    #[Test]
    public function a_short_secret_is_not_half_disclosed_by_its_own_hint(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['providers.rcs.api_key' => 'short123'],
        ])->assertOk();

        // Four visible characters out of eight is not a mask.
        $this->assertSame('••••', app(SettingsService::class)->hint('providers.rcs.api_key'));
    }

    #[Test]
    public function a_secret_is_encrypted_at_rest(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['providers.meta.app_secret' => 'meta_secret_value_9999'],
        ])->assertOk();

        // Read the raw column, bypassing the model cast.
        $raw = \DB::table('settings')->where('key', 'providers.meta.app_secret')->value('value');

        $this->assertNotSame('meta_secret_value_9999', $raw);
        $this->assertStringNotContainsString('meta_secret_value_9999', (string) $raw);
    }

    #[Test]
    public function even_non_secret_settings_are_encrypted_at_rest(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.assignment.open_lead_cap' => 200],
        ])->assertOk();

        // Encryption is unconditional so that one forgotten `is_secret` flag
        // cannot write a key in clear text.
        $raw = \DB::table('settings')->where('key', 'crm.assignment.open_lead_cap')->value('value');

        $this->assertNotSame('200', $raw);
    }

    // -----------------------------------------------------------------------
    // Writing and authority
    // -----------------------------------------------------------------------

    #[Test]
    public function an_admin_cannot_set_a_provider_credential(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['providers.payment.key_secret' => 'nope'],
        ])->assertStatus(403);

        $this->assertDatabaseCount('settings', 0);
    }

    #[Test]
    public function a_mixed_payload_is_refused_whole_rather_than_partly_applied(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->patchJson('/api/v1/settings', [
            'settings' => [
                'crm.assignment.open_lead_cap' => 200,
                'providers.payment.key_secret' => 'nope',
            ],
        ])->assertStatus(403);

        // The permitted half must not land either - a partial save would leave
        // the operator unsure which changes took.
        $this->assertDatabaseCount('settings', 0);
    }

    #[Test]
    public function an_unknown_key_is_refused(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);

        // Without the registry allowlist this endpoint is an arbitrary
        // config-write primitive (SEC-IN-06).
        $this->patchJson('/api/v1/settings', [
            'settings' => ['app.debug' => true],
        ])->assertStatus(422);

        $this->assertDatabaseCount('settings', 0);
    }

    #[Test]
    public function a_value_of_the_wrong_type_is_refused(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.assignment.open_lead_cap' => 'plenty'],
        ])->assertStatus(422);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.calling_hours.start' => '9am'],
        ])->assertStatus(422);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.assignment.method' => 'telepathy'],
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Override semantics
    // -----------------------------------------------------------------------

    #[Test]
    public function a_stored_override_wins_over_config_and_is_correctly_typed(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.assignment.open_lead_cap' => 200],
        ])->assertOk();

        $value = app(SettingsService::class)->get('crm.assignment.open_lead_cap');

        // Stored as an encrypted string; must come back as the int the config
        // key promises, or every arithmetic caller silently changes behaviour.
        $this->assertSame(200, $value);
    }

    #[Test]
    public function clearing_an_override_restores_the_config_default(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $settings = app(SettingsService::class);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.assignment.open_lead_cap' => 200],
        ])->assertOk();
        $this->assertSame(200, $settings->get('crm.assignment.open_lead_cap'));

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.assignment.open_lead_cap' => null],
        ])->assertOk();

        // Back to the shipped default, not null - clearing a field must not be
        // a way to break the application.
        $this->assertSame(150, $settings->get('crm.assignment.open_lead_cap'));
        $this->assertDatabaseCount('settings', 0);
    }

    #[Test]
    public function saving_the_same_key_twice_updates_rather_than_duplicating(): void
    {
        $this->actingAsRole(RoleName::Admin);

        foreach ([200, 250] as $value) {
            $this->patchJson('/api/v1/settings', [
                'settings' => ['crm.assignment.open_lead_cap' => $value],
            ])->assertOk();
        }

        $this->assertSame(1, Setting::where('key', 'crm.assignment.open_lead_cap')->count());
        $this->assertSame(250, app(SettingsService::class)->get('crm.assignment.open_lead_cap'));
    }

    // -----------------------------------------------------------------------
    // Audit (SEC-AUD-02)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_credential_change_is_audited_without_recording_the_value(): void
    {
        $actor = $this->actingAsRole(RoleName::SuperAdmin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['providers.vaaad.api_key' => 'vaaad_live_key_7777'],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'credential_changed',
            'description' => 'providers.vaaad.api_key',
        ]);

        // The audit log is read by more people than the settings screen is. A
        // redaction rule with an exception is a redaction rule that leaks.
        $rows = \DB::table('audit_logs')->pluck('new_values')->implode(' ');
        $this->assertStringNotContainsString('vaaad_live_key_7777', $rows);
    }

    // -----------------------------------------------------------------------
    // Secrets must not leak into derived storage (SEC-CFG-01/05)
    // -----------------------------------------------------------------------

    #[Test]
    public function a_credential_is_never_written_to_the_cache_store_in_plaintext(): void
    {
        /*
         * The suite runs on the array driver, but production does not: the
         * shared-hosting target has no Redis, so CACHE_STORE=database
         * (DEPLOYMENT §3A). That puts the cache in the SAME database whose
         * backups SECURITY §7A treats as the risk surface - so a plaintext
         * copy there would undo the encryption on `settings.value` entirely.
         */
        config(['cache.default' => 'database']);

        $service = app(SettingsService::class);
        $service->set('providers.mailercloud.api_key', 'SUPER-SECRET-KEY-12345');
        $service->flush();

        // Force the overrides to be resolved and cached.
        $this->assertSame('SUPER-SECRET-KEY-12345', $service->get('providers.mailercloud.api_key'));

        foreach (\DB::table('cache')->get() as $row) {
            $this->assertStringNotContainsString(
                'SUPER-SECRET-KEY-12345',
                (string) $row->value,
                "Cache row \"{$row->key}\" holds a provider credential in plaintext.",
            );
        }
    }

    #[Test]
    public function a_non_secret_setting_is_still_cached(): void
    {
        config(['cache.default' => 'database']);

        $service = app(SettingsService::class);
        $service->set('crm.dialer.max_queue_size', '120');
        $service->flush();
        $service->get('crm.dialer.max_queue_size');

        // The cache exists to save a query per read; excluding secrets from it
        // must not quietly disable it for everything else.
        $this->assertGreaterThan(0, \DB::table('cache')->count());
    }

    // -----------------------------------------------------------------------
    // Undecryptable rows (APP_KEY rotation, restored backups)
    // -----------------------------------------------------------------------

    /**
     * Writes a row whose ciphertext this APP_KEY cannot read.
     *
     * The same state a restored production backup or a rotated APP_KEY
     * produces, without needing to rebuild the encrypter mid-test.
     */
    private function unreadableRow(string $key): void
    {
        \DB::table('settings')->insert([
            'tenant_id' => config('crm.default_tenant_id'),
            'key' => $key,
            'value' => 'not-a-valid-ciphertext',
            'is_secret' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(SettingsService::class)->flush();
    }

    #[Test]
    public function a_setting_that_cannot_be_decrypted_falls_back_to_the_config_default(): void
    {
        config(['providers.mailercloud.api_key' => 'key-from-config']);
        $this->unreadableRow('providers.mailercloud.api_key');

        // Falling back is the behaviour from before the settings table existed,
        // and every credential path treats "not configured" as fail-closed.
        $this->assertSame('key-from-config', app(SettingsService::class)->get('providers.mailercloud.api_key'));
    }

    #[Test]
    public function one_unreadable_row_does_not_take_every_other_setting_with_it(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.dialer.max_queue_size' => 120],
        ])->assertOk();

        $this->unreadableRow('providers.mailercloud.api_key');

        /*
         * The overrides are loaded in one pass, so an unguarded failure here
         * would throw out of the bulk load and take unrelated keys down with
         * it - including the settings screen an operator would use to fix the
         * problem.
         */
        $this->assertSame(120, app(SettingsService::class)->get('crm.dialer.max_queue_size'));
    }

    #[Test]
    public function the_settings_screen_still_loads_when_a_row_is_unreadable(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $this->unreadableRow('providers.mailercloud.api_key');

        // The recovery path must not depend on the thing that is broken.
        $this->getJson('/api/v1/settings')->assertOk();
    }

    #[Test]
    public function an_unreadable_row_can_be_overwritten_to_recover(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $this->unreadableRow('providers.mailercloud.api_key');

        $this->patchJson('/api/v1/settings', [
            'settings' => ['providers.mailercloud.api_key' => 'fresh-key'],
        ])->assertOk();

        $this->assertSame('fresh-key', app(SettingsService::class)->get('providers.mailercloud.api_key'));
    }

    #[Test]
    public function an_operational_change_is_audited_under_its_own_action(): void
    {
        $actor = $this->actingAsRole(RoleName::Admin);

        $this->patchJson('/api/v1/settings', [
            'settings' => ['crm.dialer.max_queue_size' => 120],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'action' => 'setting_changed',
            'description' => 'crm.dialer.max_queue_size',
        ]);
    }
}
