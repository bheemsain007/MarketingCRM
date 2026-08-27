# Marketing CRM — backend

Laravel 12 API and Blade web app. **Start with the [project README](../README.md)** — setup,
the documentation index, and the local-environment caveats live there.

## Quick reference

```bash
php artisan test                                  # 643 tests
./vendor/bin/pint                                 # style (--test to check only)
./vendor/bin/phpstan analyse --memory-limit=1G    # static analysis

php artisan schedule:work                         # reminders, overdue payments, decay, retention
php artisan queue:work --queue=messages,webhooks,imports,reports,default
```

## Layout

| Path | Contents |
|---|---|
| `app/Enums` | Business rules that are decision tables — status matrices, the DNC grid, scoring signals |
| `app/Services` | Everything that changes state; controllers are thin by policy |
| `app/Policies` | Record-level authorisation, one layer below the permission middleware |
| `app/Support` | Cross-cutting value objects — `PhoneNumber`, `CallingHours`, `SettingsRegistry`, `Reporting\Rate` |
| `routes/api_v1.php` | The API, one commented block per phase, in build order |
| `tests/Feature` | By module — `tests/Feature/Dnc` is the one to read first |

## Reporting a security issue

Contact the project owner directly. Do **not** use Laravel's disclosure address — this is an
application built on Laravel, not part of the framework.
