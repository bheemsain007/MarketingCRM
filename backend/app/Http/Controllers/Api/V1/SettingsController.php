<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use App\Support\ApiResponse;
use App\Support\SettingDefinition;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Runtime settings and provider credentials (SEC-CFG-01/04, SEC-AUD-02).
 *
 * Two permission classes through one endpoint: `settings.manage` for
 * operational thresholds, `credentials.manage` for provider secrets - Super
 * Admin only, and not held by Admin (SEC-AUTHZ-06). The split is enforced per
 * KEY rather than per route, because a single save on the settings screen can
 * legitimately touch both.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Current values, grouped.
     *
     * Secret values are never returned - only whether one is set, a
     * non-reversible hint, and who changed it last (SEC-CFG-05).
     */
    public function index(Request $request): JsonResponse
    {
        $groups = [];

        foreach (SettingsRegistry::grouped() as $group => $definitions) {
            $visible = array_values(array_filter(
                $definitions,
                fn (SettingDefinition $d) => $request->user()->hasPermission($d->permission()),
            ));

            if ($visible === []) {
                continue;
            }

            $groups[] = [
                'group' => $group,
                'label' => SettingsRegistry::groupLabel($group),
                'settings' => array_map(fn (SettingDefinition $d) => $this->present($d), $visible),
            ];
        }

        return ApiResponse::success(['groups' => $groups], 'Settings retrieved.');
    }

    /**
     * Saves a batch.
     *
     * Body shape: `{"settings": {"crm.assignment.open_lead_cap": 200, ...}}`.
     * An explicit null clears the override so the config default applies again;
     * an omitted key is left alone - which is what makes it safe for the screen
     * to submit only the fields a user touched, and what lets a secret field
     * stay blank without wiping the stored value.
     */
    public function update(Request $request): JsonResponse
    {
        $incoming = $request->input('settings');

        if (! is_array($incoming) || $incoming === []) {
            throw new ApiException(
                ErrorCode::ValidationFailed,
                'Send a "settings" object of key/value pairs.',
            );
        }

        $rules = [];
        $definitions = [];

        foreach ($incoming as $key => $value) {
            $definition = SettingsRegistry::find((string) $key);

            if ($definition === null) {
                throw new ApiException(
                    ErrorCode::ValidationFailed,
                    sprintf('"%s" is not a settable key.', $key),
                    errors: [[
                        'field' => 'settings',
                        'code' => ErrorCode::ValidationFailed->value,
                        'message' => sprintf('Unknown setting "%s".', $key),
                    ]],
                );
            }

            // Checked per key: an Admin may save the operational half of the
            // screen, but a credential in the same payload is still refused.
            if (! $request->user()->hasPermission($definition->permission())) {
                throw new ApiException(
                    ErrorCode::Forbidden,
                    sprintf('You may not change "%s".', $definition->label),
                );
            }

            // Dots are Laravel's nesting separator, so a dotted key has to be
            // escaped or `settings.crm.timezone` is read as three levels.
            $rules['settings.'.str_replace('.', '\\.', (string) $key)] = $definition->rules();
            $definitions[(string) $key] = $definition;
        }

        Validator::make($request->all(), $rules)->validate();

        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '') {
                $this->settings->forget((string) $key, $request->user()->id, $request);

                continue;
            }

            $this->settings->set((string) $key, (string) $value, $request->user()->id, $request);
        }

        return ApiResponse::success(
            ['changed' => array_keys($definitions)],
            'Settings saved.',
        );
    }

    /** @return array<string, mixed> */
    private function present(SettingDefinition $definition): array
    {
        $configured = $this->settings->isConfigured($definition->key);

        return [
            'key' => $definition->key,
            'label' => $definition->label,
            'type' => $definition->type,
            'help' => $definition->help,
            'options' => $definition->options,
            'is_secret' => $definition->isSecret(),
            'is_configured' => $configured,

            // Secrets return a hint, never the value. Everything else returns
            // the resolved value, which may be the config default rather than
            // a stored override - the screen shows what is in effect, not only
            // what somebody typed.
            'value' => $definition->isSecret() ? null : $this->settings->get($definition->key),
            'hint' => $definition->isSecret() ? $this->settings->hint($definition->key) : null,
        ];
    }
}
