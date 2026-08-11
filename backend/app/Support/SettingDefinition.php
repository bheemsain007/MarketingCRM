<?php

namespace App\Support;

use App\Enums\Permission;

/**
 * One settable key.
 *
 * The registry is an allowlist: `SettingsController` refuses any key not
 * defined here. Without that, the settings endpoint is an arbitrary-config-write
 * primitive - somebody could set `app.debug` or `database.connections.*` through
 * an HTTP call (SEC-IN-06).
 */
class SettingDefinition
{
    /**
     * @param  string  $key  identical to the config key it overrides
     * @param  string  $type  string|int|float|bool|time|select|secret
     * @param  array<int, string>  $options  for type=select
     * @param  bool  $credential  part of a provider's configuration, secret or not
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $label,
        public readonly string $type = 'string',
        public readonly ?string $help = null,
        public readonly array $options = [],
        public readonly bool $credential = false,
    ) {}

    /** Secrets are additionally never returned by the API (SEC-CFG-05). */
    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /**
     * Credential config is Super Admin only (SEC-AUTHZ-06); ordinary
     * operational settings are Admin's business.
     *
     * Keyed off the whole provider group rather than off `type === 'secret'`.
     * A provider's non-secret fields are still credential configuration: an
     * endpoint points traffic somewhere, a `key_id` names the account being
     * billed, and a gateway selector decides which integration runs at all.
     * Letting those sit under `settings.manage` because they are not
     * themselves secret would let an Admin repoint an integration at a host
     * of their choosing without ever needing to read a key.
     */
    public function permission(): Permission
    {
        return $this->credential || $this->isSecret()
            ? Permission::CredentialsManage
            : Permission::SettingsManage;
    }

    /** Casts a stored string back to the type the config key expects. */
    public function cast(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /** @return array<int, string> Laravel validation rules for an incoming value. */
    public function rules(): array
    {
        return match ($this->type) {
            'int' => ['nullable', 'integer', 'min:0'],
            'float' => ['nullable', 'numeric', 'min:0'],
            'bool' => ['nullable', 'boolean'],
            'time' => ['nullable', 'date_format:H:i'],
            'select' => ['nullable', 'string', 'in:'.implode(',', $this->options)],
            'secret' => ['nullable', 'string', 'max:2000'],
            default => ['nullable', 'string', 'max:500'],
        };
    }
}
