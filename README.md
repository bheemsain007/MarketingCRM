# Marketing CRM

A lead-management and telecalling CRM: capture leads from ads and imports, work them
through a defined pipeline, call and message them without ever contacting somebody who
asked you not to, and report on what actually produced revenue.

Laravel 12 API + Blade web app, with a Flutter Android client planned. Built in phases
against a written specification — every business rule has an ID, and the code cites it.

---

## Current state

| | |
|---|---|
| **Suite** | 643 passing, 0 failing |
| **Phases** | 11 done, 8 partly done, of 36 |
| **Requirements** | 130 of 172 covered |
| **Quality gates** | Pint clean · PHPStan (Larastan L5) clean against baseline · `composer audit` clean |

Working end to end: leads, imports, assignment, status and product interest, calling and
the auto dialer (server side), suppression/DNC, email and SMS, Facebook/Instagram capture,
follow-ups and notifications, scoring and temperature, opportunities/quotations/sales,
payments, business reports, settings, and user administration — with a web UI for all of it.

Not built: WhatsApp, RCS, Voice, AI calling, campaigns, call recording, and the Android app.
Most are waiting on a vendor being named or a decision being made, not on engineering —
see [docs/TODO.md](docs/TODO.md).

---

## Running it locally

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Then create the databases and point `.env` at them:

```sql
CREATE DATABASE marketing_crm      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE marketing_crm_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed
php artisan crm:create-user --role=super_admin   # prompts for the rest
php artisan serve
```

The scheduler and a queue worker are **not optional** — reminders, overdue payments,
score decay and retention all run there, and nothing errors when they are missing:

```bash
php artisan schedule:work
php artisan queue:work --queue=messages,webhooks,imports,default
```

### `APP_DEBUG` defaults to false

Unlike Laravel's shipped example. The deployment path here is copying `.env.example` on
the server, and a debug page leaks credentials in a stack trace (SEC-CFG-03). Set
`APP_DEBUG=true` in your own `.env` for local work.

---

## Tests and quality gates

```bash
php artisan test                                  # 643 tests
./vendor/bin/pint --test                          # style
./vendor/bin/phpstan analyse --memory-limit=1G    # static analysis
composer audit                                    # dependency advisories
```

CI runs all four plus a migration up/down/up check — see
[`.github/workflows/ci.yml`](.github/workflows/ci.yml).

**Tests need MySQL, not SQLite.** The schema relies on FK `RESTRICT`, composite unique
semantics and strict mode, and testing on SQLite would let a constraint bug pass locally
and fail in production. `phpunit.xml` targets `marketing_crm_test` and the suite recreates
it each run.

### Known local-environment issue

The machine this was built on has a **corrupt `mysql.db` table** in its MariaDB install, so
database-level `GRANT`s cannot be persisted and the project's own DB user cannot be
authorised. The workaround is a gitignored `backend/.env.testing` pointing the suite at
`root`. Two consequences worth knowing:

- The suite currently runs on **MariaDB 10.4**, not the pinned MySQL 8.4.9. CI is what
  settles that — the first green build closes the caveat.
- If your environment is healthy, you do not need `.env.testing` at all.

Full detail in **T-48** in [docs/TODO.md](docs/TODO.md).

---

## Where the documentation is

Read in this order:

| Document | What it answers |
|---|---|
| [PROJECT_REQUIREMENTS.md](docs/PROJECT_REQUIREMENTS.md) | What the system must do — every `FR-*`/`NFR-*` ID |
| [BUSINESS_RULES.md](docs/BUSINESS_RULES.md) | The rules the code enforces — every `BR-*` ID |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | Layering, and the ADRs behind the shape of it |
| [DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) | Tables, relationships, and why they are shaped that way |
| [API_DOCUMENTATION.md](docs/API_DOCUMENTATION.md) | Every implemented endpoint and its contract |
| [SECURITY.md](docs/SECURITY.md) | `SEC-*` controls and the threats they answer |
| [GLOSSARY.md](docs/GLOSSARY.md) | Entity definitions, and **every metric formula** |
| [MODULE_STATUS.md](docs/MODULE_STATUS.md) | Phase-by-phase state and blockers |
| [TESTING.md](docs/TESTING.md) | Strategy, mandatory rule coverage, and results |
| [TODO.md](docs/TODO.md) | Open decisions — **the things blocking progress** |
| [CHANGELOG.md](docs/CHANGELOG.md) | What was built, and why it was built that way |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Hosting, the scheduler cron, and release steps |

### Two conventions that explain most of the codebase

**Business rules live in one place and are cited everywhere.** `BR-DNC-01` says one service
decides contactability; `DncService` is that service, every outbound path calls it, and the
tests name the rule. If you are about to reimplement a rule, you are about to create a
second one that will eventually disagree.

**Comments say *why*, not *what*.** A comment explaining that a `TIMESTAMP` column needs an
explicit default because MySQL otherwise adds `ON UPDATE CURRENT_TIMESTAMP` is worth
keeping; one saying "set the status" is not.

---

## Getting oriented in the code

- `app/Enums` — the business rules that are decision tables: status matrices, the DNC
  reason × channel grid, scoring signals. Start here.
- `app/Services` — everything that changes state. Controllers are thin by policy; if a rule
  is enforced anywhere, it is enforced here.
- `app/Policies` — record-level authorisation. The permission middleware asks "may they
  touch leads?"; the policy asks "may they touch *this* lead?"
- `routes/api_v1.php` — one commented block per phase, in build order.
- `tests/Feature` — organised by module. The DNC suite (`tests/Feature/Dnc`) is the one to
  read first: it is the rule the whole system is arranged around.
