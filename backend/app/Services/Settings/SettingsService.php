<?php

namespace App\Services\Settings;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\SettingDefinition;
use App\Support\SettingsRegistry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves settings as an override layer over config (SEC-CFG-01/04).
 *
 * Read order is: stored row, then `config()`. That ordering is the whole
 * design - `.env` and `config/*` stay the source of defaults, so an empty
 * `settings` table behaves exactly as the application did before this existed,
 * and a bad stored value can be cleared to fall back rather than needing a
 * deploy to fix.
 *
 * Nothing outside this service reads the `settings` table. A caller using the
 * model directly would read "no row" as "no value" instead of "use the
 * default", which is the one mistake this design has to prevent.
 */
class SettingsService
{
    private const CACHE_KEY = 'crm.settings.overrides';

    /**
     * Decrypted secrets, for this request only (SEC-CFG-07).
     *
     * @var array<string, string|null>|null
     */
    private ?array $secrets = null;

    /** Resolved value for a key, or the config default when unset. */
    public function get(string $key, mixed $default = null): mixed
    {
        $definition = SettingsRegistry::find($key);
        $overrides = $this->overrides() + $this->secretOverrides();

        if (array_key_exists($key, $overrides)) {
            $value = $overrides[$key];

            // A stored null means "explicitly cleared" - fall through to the
            // config default rather than returning null, so clearing a field in
            // the UI restores the shipped behaviour instead of breaking it.
            if ($value !== null) {
                return $definition ? $definition->cast($value) : $value;
            }
        }

        return config($key, $default);
    }

    /**
     * Writes one setting.
     *
     * @throws ApiException when the key is not in the registry
     */
    public function set(string $key, ?string $value, ?int $actorId = null, ?Request $request = null): Setting
    {
        $definition = SettingsRegistry::find($key);

        if ($definition === null) {
            // The registry is an allowlist. Without this the endpoint is an
            // arbitrary-config-write primitive - `app.debug`, database
            // credentials, anything (SEC-IN-06).
            throw new ApiException(
                ErrorCode::ValidationFailed,
                sprintf('"%s" is not a settable key.', $key),
            );
        }

        return DB::transaction(function () use ($definition, $key, $value, $actorId, $request) {
            /*
             * An unreadable row has to be REPLACED, not updated.
             *
             * Eloquent works out what changed by comparing the new value with
             * the stored one, and that comparison decrypts it - so writing over
             * a row this APP_KEY cannot read throws, and overwriting the bad
             * credential is exactly the recovery an operator reaches for.
             * A recovery path must never depend on the thing that is broken.
             */
            $existing = Setting::query()
                ->where('tenant_id', config('crm.default_tenant_id'))
                ->where('key', $key)
                ->first();

            if ($existing !== null && ! $this->isReadable($existing)) {
                $existing->delete();
            }

            $setting = Setting::updateOrCreate(
                ['tenant_id' => config('crm.default_tenant_id'), 'key' => $key],
                [
                    'value' => $value,
                    'is_secret' => $definition->isSecret(),
                    'updated_by' => $actorId,
                ],
            );

            $this->audit($definition, $actorId, $value, $request);
            $this->flush();

            return $setting;
        });
    }

    /**
     * The row's plaintext value, or null when it stores nothing.
     *
     * Decrypts explicitly instead of reading `$setting->value`, so the failure
     * happens somewhere a reader can see it rather than inside an attribute
     * cast - and so the `catch` around it is visibly reachable.
     *
     * @throws DecryptException when APP_KEY cannot read what the row stores
     */
    private function decrypt(Setting $setting): ?string
    {
        $raw = $setting->getRawOriginal('value');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return Crypt::decryptString($raw);
    }

    /** Whether this APP_KEY can still read what the row stores. */
    private function isReadable(Setting $setting): bool
    {
        try {
            $this->decrypt($setting);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * Removes the override so the config default applies again.
     */
    public function forget(string $key, ?int $actorId = null, ?Request $request = null): void
    {
        $definition = SettingsRegistry::find($key);

        if ($definition === null) {
            return;
        }

        DB::transaction(function () use ($definition, $key, $actorId, $request) {
            Setting::query()
                ->where('tenant_id', config('crm.default_tenant_id'))
                ->where('key', $key)
                ->delete();

            $this->audit($definition, $actorId, null, $request, cleared: true);
            $this->flush();
        });
    }

    /** Whether a key currently has a usable value from any source. */
    public function isConfigured(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null && $value !== '';
    }

    /**
     * A non-reversible hint that a secret is present, for the UI.
     *
     * Last four characters only, and only when the value is long enough that
     * four characters are not most of it - an eight-character token would
     * otherwise be half-disclosed by its own "masked" display.
     */
    public function hint(string $key): ?string
    {
        $value = (string) $this->get($key);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) >= 12
            ? '••••'.mb_substr($value, -4)
            : '••••';
    }

    /**
     * Non-secret overrides, cached across requests.
     *
     * Cached because `get()` is called on hot paths - the dialer asks for the
     * cooldown on every skip check. Flushed on every write.
     *
     * @return array<string, string|null>
     */
    private function overrides(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => $this->load(secrets: false));
    }

    /**
     * Secret overrides - resolved per request, never cached (SEC-CFG-07).
     *
     * The cache store is not a safe home for these. The deployment target has
     * no Redis, so `CACHE_STORE=database` (DEPLOYMENT §3A) puts the cache in
     * the same database whose backups SECURITY §7A treats as the risk surface
     * - and a plaintext copy there would undo the encryption on
     * `settings.value` completely.
     *
     * So credentials are read from their (encrypted) rows and decrypted into
     * memory for the life of one request only. That costs one query per
     * request, on paths that already make an HTTP call to a provider.
     *
     * @return array<string, string|null>
     */
    private function secretOverrides(): array
    {
        return $this->secrets ??= $this->load(secrets: true);
    }

    /**
     * @return array<string, string|null>
     */
    private function load(bool $secrets): array
    {
        $resolved = [];

        $rows = Setting::query()
            ->where('tenant_id', config('crm.default_tenant_id'))
            ->where('is_secret', $secrets)
            ->get();

        foreach ($rows as $setting) {
            /*
             * Decrypted per row, and guarded, because the values are encrypted
             * with APP_KEY and the key does not always match the data.
             * Restoring a production database into staging is the ordinary way
             * this happens, and rotating APP_KEY is the deliberate one.
             *
             * Unguarded, ONE unreadable row would throw out of this bulk load
             * and take every other key with it - both webhooks, both message
             * drivers, and the settings screen an operator would use to fix it.
             * Skipping the row instead falls through to the config default,
             * which is the behaviour before this table existed, and every
             * credential path already handles "not configured" by failing
             * closed rather than open.
             */
            try {
                // A null stays in the array on purpose: the key being present
                // but null means "explicitly cleared", which get() resolves to
                // the config default.
                $resolved[$setting->key] = $this->decrypt($setting);
            } catch (DecryptException) {
                // The key, never the value - and at warning level, because
                // silently running on defaults is how a rotated APP_KEY
                // becomes "the integrations mysteriously stopped working".
                Log::warning('Stored setting could not be decrypted; falling back to the config default.', [
                    'key' => $setting->key,
                    'hint' => 'APP_KEY does not match the value this row was encrypted with.',
                ]);
            }
        }

        return $resolved;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->secrets = null;
    }

    /**
     * SEC-AUD-02 requires provider credential changes to be audited.
     *
     * The VALUE is never recorded - not even for non-secrets, because the
     * audit log is read by more people than the settings screen is, and a
     * redaction rule with an exception is a redaction rule that leaks
     * (SEC-CFG-05).
     */
    private function audit(
        SettingDefinition $definition,
        ?int $actorId,
        ?string $value,
        ?Request $request,
        bool $cleared = false,
    ): void {
        AuditLog::create([
            'user_id' => $actorId,
            'action' => $definition->isSecret() ? 'credential_changed' : 'setting_changed',
            'description' => $definition->key,
            'new_values' => [
                'group' => $definition->group,
                'secret' => $definition->isSecret(),
                'action' => $cleared ? 'cleared' : ($value === null || $value === '' ? 'cleared' : 'set'),
            ],
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
        ]);
    }
}
