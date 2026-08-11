# Deployment & Environments

| | |
|---|---|
| **Version** | 1.1 |
| **Last updated** | 2026-08-10 (Phase 2) |
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

Grouped; `.env.example` carries every key with **empty** values (SEC-CFG-02).

```
# Core
APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL, APP_TIMEZONE

# Database
DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

# Redis / queue / cache / session
REDIS_HOST, REDIS_PORT, REDIS_PASSWORD
QUEUE_CONNECTION=redis, CACHE_STORE=redis, SESSION_DRIVER=redis

# Storage (recordings + lead import files — private disks, never `public`)
FILESYSTEM_DISK, RECORDINGS_DISK, RECORDINGS_RETENTION_DAYS
LEAD_IMPORT_DISK, LEAD_IMPORT_MAX_FILE_KB, LEAD_IMPORT_MAX_ROWS, LEAD_IMPORT_CHUNK_SIZE, LEAD_IMPORT_FILE_RETENTION_DAYS

# Auth
SANCTUM_STATEFUL_DOMAINS, SESSION_DOMAIN, TOKEN_EXPIRY_DAYS

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
* * * * * cd ~/domains/<site>/backend && php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```

The second line replaces the daemon worker: it starts each minute, drains what it can, and exits before the next tick. `--max-time=50` keeps it inside the minute so overlapping runs don't pile up.

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
| `critical` | `critical,webhooks` | 2 |
| `dialer` | `dialer` | 2 |
| `campaigns` | `campaigns` | 4–10, scale with volume |
| `background` | `media,imports,reports,default` | 2 |

**Never run one worker on all queues.** Latency-sensitive work (`dialer`, `webhooks`) must not queue behind bulk sends.

Worker settings: `--tries=3 --backoff=30,120,600 --max-time=3600 --timeout=<below job timeout>`. Workers are restarted on every deploy (§6) — long-running PHP processes hold old code in memory.

## 5. Scheduler

One cron entry drives everything ([ARCHITECTURE §8](ARCHITECTURE.md#8-scheduler)):

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Verify after every deploy that this cron exists — a missing entry silently stops follow-up reminders, overdue-payment marking, scheduled campaigns, and recording purges. Nothing errors; work simply never happens. Add an uptime/heartbeat check on the scheduler.

Registered so far (`routes/console.php`):

| Command | When | Consequence if the cron is missing |
|---------|------|------------------------------------|
| `leads:purge-import-files` | daily 03:30 | Uploaded lead files — bulk PII — are kept indefinitely. A retention failure that nothing surfaces (SEC-PII-05) |

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
- [ ] DB user least-privilege (SEC-OPS-04)
- [ ] Recordings on a **private** disk; signed URLs verified as expiring
- [ ] Supervisor workers running for all four groups; `queue:restart` in the deploy script
- [ ] Scheduler cron installed and heartbeat-monitored
- [ ] Backups running; **restore tested**
- [ ] Retention purge jobs verified against real dates
- [ ] Rate limits active
- [ ] Log redaction of PII/secrets confirmed (SEC-PII-03)
- [ ] DNC test matrix green against production config

## 11. Open Decisions

| # | Decision | Needed by |
|---|----------|-----------|
| 1 | Hosting target — VPS / managed (Forge, Vapor) / cloud (AWS, DO)? | Phase 3 (informs local parity) |
| 2 | PHP + Laravel version pin | Phase 3 |
| 3 | Recording storage — local disk vs. S3-compatible object storage | Phase 11 |
| 4 | CI/CD platform (GitHub Actions?) and whether deploys are automated | Phase 3 |
| 5 | Payment gateway | Phase 23 |
