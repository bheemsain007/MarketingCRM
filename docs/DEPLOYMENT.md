# Deployment & Environments

| | |
|---|---|
| **Version** | 1.3 |
| **Last updated** | 2026-08-28 — §5 scheduler table completed (was 1 command, actually 10) and pointed at the real health-check route; §3 environment list completed 2026-08-27 (mail, trusted proxies, security headers, session cookie, lead exports) |
| **Status** | Local stack installed and verified. **Production target: Hostinger shared hosting** — see §3A for what that constrains |
| **Related** | [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [TESTING.md](TESTING.md) |

Why this exists: a Laravel app with queue workers, a scheduler, and six webhook-receiving providers cannot be deployed by copying files. Queue workers must be restarted on deploy or they run stale code — a class of bug that is invisible in testing and corrupts live campaigns.

---

## 1. Stack Versions *(proposed — confirm before Phase 3)*

| Component | Installed (dev) | Notes |
|-----------|-----------------|-------|
| PHP | **8.2.30** ✓ | Laravel 12 requires 8.2+. Match this on Hostinger |
| Laravel | **12.65.0** ✓ | |
| MySQL | **8.4.9** ✓ | Dev instance runs as a user process (no admin rights on this machine); data dir `C:\Users\BHEEM\.mysql-dev` |
| Composer | **2.10.2** ✓ | Installed locally at `tools/composer.phar`, SHA-256 verified |
| Node | **25.8.0** ✓ | Build-time only (Vite/Tailwind); not needed at runtime |
| Redis | not installed | Not available on the shared-hosting target — see §3A |
| Sanctum | **4.3.3** ✓ | |

**Local MySQL is not a Windows service** (installation ran without administrator rights). Start it with:

```
Start-Process 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\Users\BHEEM\.mysql-dev\my.ini' -WindowStyle Hidden
```

To register it as an auto-starting service instead, run once **as Administrator**:

```
& 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe' --install MySQL84 --defaults-file='C:\Users\BHEEM\.mysql-dev\my.ini'
Start-Service MySQL84
```

**Pin these in `composer.json` / `package.json` and in CI** so local, CI, and production match. Version drift between environments is the most common source of "works on my machine".

## 2. Environments

| Environment | Purpose | Data | Providers |
|-------------|---------|------|-----------|
| **Local** | Development | Seeded fake data | **All faked** — no real sends |
| **CI** | Automated tests | Ephemeral, per-run | All faked (TESTING §5) |
| **Staging** | Pre-release verification | Anonymised copy or seeded | Provider **sandbox** credentials, or faked |
| **Production** | Live | Real | Real credentials |

**Rule: staging must never hold real lead phone numbers with live provider credentials.** That combination will eventually send a real message to a real person from a test run. Either anonymise the data or fake the providers — preferably both.

## 3. Required Environment Variables

Grouped. `.env.example` carries every key below. **Credentials are always empty** (SEC-CFG-02); everything else carries a working value on purpose, because a blank line does *not* fall through to the config default — it overrides it with `""`. That is how `MAIL_FROM_ADDRESS=` silently disabled every mail this application sends, and it is why `SECURITY_FRAME_OPTIONS=` would send an empty header rather than `DENY`.

```
# Core
APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL, APP_TIMEZONE

# Database
DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

# Redis / queue / cache / session
REDIS_HOST, REDIS_PORT, REDIS_PASSWORD
QUEUE_CONNECTION=redis, CACHE_STORE=redis, SESSION_DRIVER=redis

# Storage (recordings, lead imports AND lead exports — private disks, never `public`)
FILESYSTEM_DISK, RECORDINGS_DISK, RECORDINGS_RETENTION_DAYS, RECORDINGS_SIGNED_URL_MINUTES
LEAD_IMPORT_DISK, LEAD_IMPORT_MAX_FILE_KB, LEAD_IMPORT_MAX_ROWS, LEAD_IMPORT_CHUNK_SIZE, LEAD_IMPORT_FILE_RETENTION_DAYS
LEAD_EXPORT_DISK, LEAD_EXPORT_RETENTION_DAYS, LEAD_EXPORT_CHUNK_SIZE

# Auth
SANCTUM_STATEFUL_DOMAINS, SANCTUM_TOKEN_PREFIX, SESSION_DOMAIN, TOKEN_EXPIRY_DAYS
AUTH_RESET_TOKEN_EXPIRE_MINUTES, AUTH_TIMEBOX_MICROSECONDS

# Session cookie — `crm:production-check` FAILS on any other value
SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_SAME_SITE=lax

# Mail — password reset is the ONLY account-recovery path (SEC-AUTH-06)
MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
MAIL_FROM_ADDRESS, MAIL_FROM_NAME

# Transport & browser security (SEC-OPS-02, T-30, T-39)
TRUSTED_PROXIES
SECURITY_HEADERS_ENABLED, SECURITY_FRAME_OPTIONS, SECURITY_REFERRER_POLICY
SECURITY_CROSS_DOMAIN_POLICIES, SECURITY_PERMISSIONS_POLICY
SECURITY_HSTS_ENABLED, SECURITY_HSTS_MAX_AGE, SECURITY_HSTS_INCLUDE_SUBDOMAINS, SECURITY_HSTS_PRELOAD
SECURITY_CSP_ENABLED, SECURITY_CSP_REPORT_ONLY, SECURITY_CSP_REPORT_URI, SECURITY_CSP_CDN_HOSTS, SECURITY_CSP_ALLOW_INLINE

# Operational thresholds
QUEUE_BACKLOG_ALERT_MINUTES, ATTENDANCE_STALE_AFTER_MINUTES
RATE_LIMIT_AUTH, RATE_LIMIT_STANDARD, RATE_LIMIT_BULK, RATE_LIMIT_WEBHOOK

# Providers  (never committed — SEC-CFG-01)
MAILERCLOUD_API_KEY, MAILERCLOUD_FROM_EMAIL, MAILERCLOUD_WEBHOOK_SECRET
WHATSAPP_PROVIDER, WHATSAPP_API_KEY, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_WEBHOOK_VERIFY_TOKEN, WHATSAPP_APP_SECRET
BHASHSMS_USER, BHASHSMS_PASSWORD, BHASHSMS_SENDER_ID
RCS_PROVIDER, RCS_API_KEY                       # vendor TBD
VOICE_PROVIDER, VOICE_API_KEY                   # vendor TBD
VAAAD_API_KEY, VAAAD_WEBHOOK_SECRET
META_APP_ID, META_APP_SECRET, META_VERIFY_TOKEN, META_PAGE_ACCESS_TOKEN
PAYMENT_GATEWAY, PAYMENT_KEY_ID, PAYMENT_KEY_SECRET, PAYMENT_WEBHOOK_SECRET   # gateway TBD

# Business rule config (BUSINESS_RULES.md)
CALLING_HOURS_START, CALLING_HOURS_END
CAMPAIGN_CAP_PER_DAY, CAMPAIGN_CAP_PER_WEEK
AI_INTEREST_CONFIDENCE_THRESHOLD
DISCOUNT_APPROVAL_THRESHOLD
IDLE_THRESHOLD_MINUTES
```

Business-rule thresholds live in config, not code, so tuning them is a config change and not a deployment of new logic (BR-DNC-04).

## 3A. Shared Hosting Mode (Hostinger) — ACTIVE TARGET ⚠️

The chosen production target is **Hostinger shared hosting**. That platform cannot run the topology in ARCHITECTURE §4 as written, so the application is built driver-agnostic and runs in "shared hosting mode" by default.

### What changes

| Concern | VPS design | Shared hosting reality |
|---------|-----------|------------------------|
| Queue driver | Redis | **`database`** (jobs table) |
| Cache / session | Redis | **`database`** |
| Locks | Redis atomic locks | Database atomic locks (Laravel supports both) |
| Workers | Supervisor daemons, 4–10 processes | **No Supervisor.** Cron-triggered `queue:work --stop-when-empty --max-time=50` |
| Rate limiting | Redis | Database cache store — slower but correct |

Application code never references a driver directly, so moving to a VPS is a `.env` change plus a Supervisor config — not a rewrite.

### Cron entries required

```
* * * * * cd ~/domains/<site>/backend && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/domains/<site>/backend && php artisan queue:work --queue=messages,webhooks,imports,reports,default --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```

The second line replaces the daemon worker: it starts each minute, drains what it can, and exits before the next tick. `--max-time=50` keeps it inside the minute so overlapping runs don't pile up.

**`--queue` is not optional.** Without it a worker drains only `default`, and every job in this application names a queue instead (`config('crm.queues')`). Omitting the flag sends no error anywhere: the API keeps answering `202 queued`, `failed_jobs` stays empty, and the rows just accumulate — while password-reset mail, the one thing that *does* use `default`, keeps working, so a smoke test passes. `php artisan crm:production-check` reports the backlog per queue and fails once work sits unclaimed.

### Honest limitations of this target

1. **Campaign throughput is the real constraint.** One cron-driven worker processes messages serially. A 50,000-recipient campaign that a VPS clears in minutes will take **hours** here. The engine is correct either way — it is *slow*, not broken — but bulk campaigns are the feature most affected.
2. **Resource limits.** Shared plans cap CPU seconds and concurrent processes. Sustained queue processing can trip those limits, and hosts may throttle or suspend accounts for it. Watch this during Phase 18.
3. **No Redis** on standard shared plans, so no Redis-backed locking or high-speed rate limiting.
4. **Auto-dialer latency** (Phase 10) depends on a minute-granularity cron rather than an always-on worker — acceptable for a small team, poor for many concurrent telecallers.
5. **SSH + Composer** require a Hostinger plan tier that provides them (Business or above). Verify before Phase 29.

### Recommendation

Shared hosting is workable for launch with a small team and modest campaign volume. **Budget for a move to a VPS before scaling bulk campaigns or the auto dialer** — Hostinger sells VPS plans, so it is a migration within the same vendor, not a re-platform. Revisit at Phase 18 (Campaign Engine) with real volume numbers.

This is a deployment-topology constraint only. **No business logic, API, or schema decision changes because of it.**

---

## 4. Queue Workers

Per [ARCHITECTURE §4](ARCHITECTURE.md#4-queue--worker-topology). Supervisor runs separate worker groups so a 50,000-message campaign cannot starve the dialer:

| Worker group | Queues | Processes *(starting point)* |
|--------------|--------|------------------------------|
| `realtime` | `webhooks` | 2 |
| `messages` | `messages` | 4–10, scale with volume |
| `background` | `imports,reports,default` | 2 |

These are the queues the code actually dispatches onto — the canonical list is `config('crm.queues')`, and anything added there needs a worker here. The wider topology in [ARCHITECTURE §4](ARCHITECTURE.md#4-queue--worker-topology) (`critical`, `dialer`, `campaigns`, `media`) is the design target; those queues have no dispatcher yet, so staffing them today would start workers that idle for ever.

**Never run one worker on all queues.** Latency-sensitive work (`webhooks`) must not queue behind bulk sends — a campaign fan-out puts one job per recipient on `messages`.

Worker settings: `--tries=3 --backoff=30,120,600 --max-time=3600 --timeout=<below job timeout>`. Workers are restarted on every deploy (§6) — long-running PHP processes hold old code in memory.

## 5. Scheduler

One cron entry drives everything ([ARCHITECTURE §8](ARCHITECTURE.md#8-scheduler)):

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Verify after every deploy that this cron exists — a missing entry silently stops follow-up reminders, overdue-payment marking, scheduled campaigns, and recording purges. Nothing errors; work simply never happens. Poll `GET /api/v1/health/scheduler` from your uptime monitor (**not** `/up/scheduler`, which does not exist — see API_DOCUMENTATION §10A).

**Registered** (`routes/console.php`) — **corrected 2026-08-28**: this table named one command; ten are
actually registered, several pay- or compliance-affecting:

| Command | When | Consequence if the cron is missing |
|---------|------|------------------------------------|
| `crm:scheduler-heartbeat` | every minute | Nothing else in this table has a way to detect its own absence without this one — it is the signal every other row's "missing cron" consequence depends on being noticed at all |
| `crm:process-follow-ups` | every minute | Follow-up reminders never fire and overdue follow-ups are never flagged `Missed` (FR-FUP-01/03) |
| `crm:dispatch-scheduled-campaigns` | every minute | A campaign scheduled for a future time stays `scheduled` forever while every screen claims it is going out — indistinguishable from success until someone checks (FR-CAMP-02) |
| `leads:purge-import-files` | daily 03:30 | Uploaded lead files — bulk PII — are kept indefinitely. A retention failure that nothing surfaces (SEC-PII-05) |
| `leads:purge-export-files` | daily 03:30 | Generated export CSVs — the whole lead database per file — are kept indefinitely. `expires_at` stops the download but deletes nothing (SEC-PII-04/05). `--dry-run` reports without deleting |
| `crm:purge-recordings` | daily 03:30 | Call recordings are kept past their retention period (BR-REC-02, SEC-PII-05) |
| `crm:decay-lead-scores` | daily 04:15 | Lead scores never decay; a lead that went cold months ago still reads Hot (BR-SCORE-01, BR-TEMP-02) |
| `crm:aggregate-daily-reports` | daily 04:45 | `report_daily_aggregates` is never written; every report request for a past period falls back to a live scan forever instead of just until the next run (FR-RPT-05) |
| `crm:mark-overdue-payments` | daily 06:00 | Payments due in the past never flip to `overdue`; collections reporting silently under-counts (BR-PAY-06) |
| `crm:close-stale-work-sessions` | every 15 min | A session nobody logged out of stays open indefinitely, and open time counts up to `now()` — the number that feeds telecaller reports and therefore pay grows on its own with nobody at the desk |

⚠️ **`crm:production-check` is deliberately not on this list.** It is built (Phase 29) and checks queue
backlog and `failed_jobs`, but it is a **manual/deploy-script command**, not scheduled — run it as the
last step of a deploy (§6) or point a separate monitoring job at it yourself; `schedule:run` never
calls it on its own. See ARCHITECTURE §8.

## 6. Deploy Sequence

```
1. Put app in maintenance mode (php artisan down --render=...)   # only if migrations are breaking
2. Pull code
3. composer install --no-dev --optimize-autoloader
4. npm ci && npm run build
5. php artisan migrate --force
6. php artisan config:cache route:cache view:cache event:cache
7. php artisan queue:restart          ← MANDATORY: workers hold old code otherwise
8. php artisan up
9. Smoke check: health endpoint, queue depth, scheduler heartbeat
```

**Step 7 is the one most often forgotten.** Skipping it means new code serves web requests while queued jobs still run the previous version — campaigns and DNC checks would execute stale logic.

**Migration safety:** additive first (add column → backfill → switch reads → drop old) for zero-downtime deploys. Never drop or rename a column in the same deploy that stops using it.

## 7. Rollback

- Code: redeploy previous tag, then `queue:restart` again.
- Database: forward-fix preferred over `migrate:rollback` on production data. Every migration must be reviewed for whether rollback is safe — destructive `down()` methods on live tables are prohibited.
- Take a DB snapshot before any deploy containing migrations.

## 8. Backups

| Item | Policy |
|------|--------|
| MySQL | Automated daily full + binlog/point-in-time recovery; encrypted at rest |
| Recordings | Backed up per retention policy (BR-REC-02); never backed up beyond retention, or the purge is meaningless |
| Restore drill | Tested before production go-live (Phase 29) — an untested backup is not a backup |

## 9. Monitoring & Alerts

| Signal | Alert when |
|--------|-----------|
| Queue depth per queue | Sustained growth — workers dead or under-provisioned |
| Failed jobs | Any spike |
| Scheduler heartbeat | No run in > 5 minutes |
| Provider circuit breakers | Open |
| Webhook processing lag | Backlog growing |
| API 5xx rate / p95 latency | Threshold breach |
| Disk usage | Recordings fill disks quickly |
| Failed logins / lockouts | Spike (SEC-AUD-02) |

## 10. Pre-Production Checklist (Phase 29)

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] All provider credentials set as production (not sandbox) and verified
- [ ] Every webhook URL registered with each provider and signature verification confirmed
- [ ] TLS + HSTS + security headers (SEC-OPS-02)
- [ ] `TRUSTED_PROXIES` pinned to the load balancer's address on any host that is **not** the shared-hosting target — `*` there lets a client set its own `X-Forwarded-For`, and that is the IP the rate limiter and every audit row are keyed on (T-30)
- [ ] `MAIL_MAILER` is a real transport and `MAIL_FROM_ADDRESS` is set — password reset is the only account-recovery path, and an empty from-address stops every message before it is sent (SEC-AUTH-06)
- [ ] DB user least-privilege (SEC-OPS-04)
- [ ] Recordings, lead **imports** and lead **exports** on **private** disks; signed URLs verified as expiring (an export is the whole lead database in one file — SEC-PII-04)
- [ ] Supervisor workers running for all four groups; `queue:restart` in the deploy script
- [ ] Scheduler cron installed and heartbeat-monitored
- [ ] Backups running; **restore tested**
- [ ] Retention purge jobs verified against real dates
- [ ] Rate limits active
- [ ] Log redaction of PII/secrets confirmed (SEC-PII-03)
- [ ] DNC test matrix green against production config

`php artisan crm:production-check` automates most of the list above and exits non-zero on any failure, so a deploy script can end with `php artisan crm:production-check || exit 1`. It is not a substitute for the items it cannot see from inside the application — provider credentials, webhook registration, backups.

## 11. Open Decisions

| # | Decision | Needed by |
|---|----------|-----------|
| 1 | Hosting target — VPS / managed (Forge, Vapor) / cloud (AWS, DO)? | Phase 3 (informs local parity) |
| 2 | PHP + Laravel version pin | Phase 3 |
| 3 | Recording storage — local disk vs. S3-compatible object storage | Phase 11 |
| 4 | CI/CD platform (GitHub Actions?) and whether deploys are automated | Phase 3 |
| 5 | Payment gateway | Phase 23 |
