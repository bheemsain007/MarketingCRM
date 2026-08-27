# Architecture

| | |
|---|---|
| **Version** | 1.1 |
| **Last updated** | 2026-08-10 (Phase 1) |
| **Status** | Baseline agreed; ADR-A..D carry assumed defaults pending confirmation |
| **Related** | [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) · [BUSINESS_RULES.md](BUSINESS_RULES.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [SECURITY.md](SECURITY.md) |

---

## 1. System Context

```
┌─────────────────────────┐        ┌──────────────────────────┐
│  Web CRM (browser)      │        │  Flutter Android App     │
│  Blade shell + Tailwind │        │  (Phase 30+)             │
│  Bootstrap + jQuery     │        │  native dialer + recorder│
│  AJAX, session auth     │        │  token auth, offline q   │
└───────────┬─────────────┘        └────────────┬─────────────┘
            │                                   │
            └───────────────┬───────────────────┘
                            │  HTTPS  /api/v1/*
                            v
        ┌───────────────────────────────────────────┐
        │           Laravel Application             │
        │  Routes → Middleware → FormRequest →      │
        │  Controller → Service → Model → Resource  │
        └───────┬───────────────────────┬───────────┘
                │                       │
      ┌─────────v────────┐    ┌─────────v──────────┐
      │ MySQL            │    │ Redis              │
      │ system of record │    │ queue/cache/locks  │
      └──────────────────┘    └─────────┬──────────┘
                                        │
                              ┌─────────v──────────┐
                              │ Queue Workers      │
                              │ (campaigns, comms, │
                              │  imports, reports) │
                              └─────────┬──────────┘
                                        │ outbound
                    ┌───────────────────┴──────────────────┐
                    v                                      v
        ┌───────────────────────┐              ┌───────────────────────┐
        │ Provider APIs         │  webhooks    │ Meta Lead Ads         │
        │ Mailercloud, WhatsApp │─────────────>│ FB/IG webhook         │
        │ BhashSMS, RCS, Voice, │   inbound    └───────────────────────┘
        │ Vaaad (AI calling)    │
        └───────────────────────┘
```

Both clients are pure API consumers. Neither ever reaches MySQL directly (NFR-01). All business rules — DNC, status transitions, scoring, campaign eligibility — live in `app/Services/*`, so Web and Android behave identically by construction rather than by discipline (NFR-05).

---

## 2. Request Lifecycle & Layering

```
Route (routes/api_v1.php)
  → Middleware       auth:sanctum, role/permission, throttle, tenant (dormant)
  → FormRequest      validation + authorization
  → Controller       thin: translate HTTP ⇄ service call, no business logic
  → Service          business rules, transactions, event dispatch
  → Model/Eloquent   persistence only
  → API Resource     response shaping into {success, message, data, errors}
```

**Layer rules** (enforced in review):

| Layer | May do | Must not do |
|-------|--------|-------------|
| Controller | Validate via FormRequest, call one service, return a Resource | Contain conditionals expressing business rules, query builders, or provider calls |
| Service | Own transactions, enforce rules, dispatch jobs/events | Read `$request`, return HTTP responses, echo/redirect |
| Model | Relations, scopes, casts, accessors | Side effects on other aggregates, provider calls |
| Job | Orchestrate one unit of async work by calling services | Re-implement rules that belong in a service |
| Resource | Shape output | Trigger queries (N+1) — eager-load in the service |

**Response envelope** is produced by a single `ApiResponse` helper/trait, including the exception handler's error path, so no endpoint can drift from the contract (NFR-03).

---

## 3. Directory Structure

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/V1/       # thin controllers, one per resource
│   │   ├── Middleware/               # role, permission, tenant (dormant), throttle
│   │   ├── Requests/                 # FormRequest validation
│   │   └── Resources/                # API response shaping
│   ├── Models/
│   ├── Policies/                     # authorization rules per model
│   ├── Services/
│   │   ├── Leads/
│   │   ├── Calling/                  # call sessions, dial intents, auto dialer
│   │   ├── Communication/
│   │   │   ├── Contracts/            # channel interfaces
│   │   │   ├── Email/  WhatsApp/  Sms/  Rcs/  Voice/  AiCalling/
│   │   │   └── Support/              # retry, idempotency, payload logging
│   │   ├── Campaigns/
│   │   ├── Dnc/                      # THE suppression gate
│   │   ├── InterestEngine/
│   │   ├── Sales/  Payments/  Reports/
│   │   └── Shared/                   # phone normalisation, scoring primitives
│   ├── Jobs/                         # queued work (see §4)
│   ├── Events/  Listeners/
│   ├── Exceptions/                   # domain exceptions → HTTP mapping
│   └── Console/Commands/             # scheduler entry points
├── config/                           # incl. per-provider config files
├── database/{migrations,factories,seeders}/
├── routes/{api_v1.php, web.php, channels.php}
├── resources/{views,js,css}/         # Blade shells + page JS
└── tests/{Unit,Feature,Api}/
```

---

## 4. Queue & Worker Topology

Bulk and slow work never runs in an HTTP request (NFR-06). Redis is the queue driver.

> **Design target vs. what is built.** The table below is the intended topology. Today the code dispatches onto five of these only — `messages` (which carries the campaign fan-out this table assigns to `campaigns`), `webhooks`, `imports`, `reports` and `default`; the canonical list is `config('crm.queues')`. `critical`, `dialer`, `campaigns` and `media` have no dispatcher yet. Staff workers from [DEPLOYMENT §4](DEPLOYMENT.md#4-queue-workers), not from this table, or you will start workers that idle for ever while real work waits on a queue nobody drains.

| Queue | Purpose | Priority | Worker guidance |
|-------|---------|----------|-----------------|
| `critical` | Auth/security side-effects, DNC propagation, webhook ingestion follow-up | Highest | Always staffed, short jobs only |
| `dialer` | Auto-dialer queue building, dial-intent dispatch | High | Latency-sensitive; keep separate from bulk |
| `campaigns` | Per-recipient campaign send jobs (the bulk volume) | Normal | Scale horizontally; rate-limited per provider |
| `webhooks` | Async processing of verified inbound webhook payloads | High | Idempotent, fast |
| `media` | Recording upload processing, storage moves, transcoding if any | Low | I/O heavy, isolate |
| `imports` | CSV/Excel lead import row processing | Low | Long-running, batched |
| `reports` | Report aggregation/pre-computation | Low | Off-peak via scheduler |
| `default` | Everything else (mail notifications, cleanups) | Normal | — |

**Design rules**

- A campaign **fans out**: one `DispatchCampaignJob` builds the eligible audience and enqueues one job *per recipient* onto `campaigns`. Recipient jobs are independently retryable, so one bad number cannot fail a 50,000-lead campaign (FR-CAMP-05).
- Campaign send jobs use Laravel **job batching** so pause/resume/stop and progress counts map to batch operations rather than bespoke state tracking (FR-CAMP-02).
- **Per-provider rate limiting** via Redis-backed throttling so a burst never trips a vendor's limit.
- Every job is **idempotent by key** — re-running a recipient job must not send twice (see §6).
- **Failed jobs** land in `failed_jobs` with full context and are surfaced in campaign logs, never silently discarded (FR-COMM-06).
- Long queues are drained by dedicated worker pools; `dialer` and `critical` never share a worker pool with `campaigns`.

---

## 5. Provider Integration Pattern

Every external channel sits behind an interface so vendors are swappable without touching CRM logic (NFR-14):

```
app/Services/Communication/Contracts/
    ChannelProvider.php          # send(), status(), supports()
    SupportsTemplates.php
    SupportsWebhooks.php

app/Services/Communication/Email/MailercloudProvider.php
app/Services/Communication/Sms/BhashSmsProvider.php
app/Services/Communication/AiCalling/VaaadProvider.php
app/Services/Communication/WhatsApp/{TBD}Provider.php
```

Concrete providers are bound in a service provider and resolved by channel key from config — campaign and messaging code depends only on the interface.

**Resilience policy (applies to every outbound provider call):**

| Concern | Policy |
|---------|--------|
| Timeouts | Explicit connect + request timeouts on every HTTP call; no unbounded waits |
| Retries | Exponential backoff with jitter on transient failures (network, 429, 5xx) |
| Non-retryable | 4xx other than 429 (bad payload, invalid recipient) fail fast and are logged as permanent — no retry storm |
| Circuit breaking | Repeated provider failures trip a breaker; jobs are held rather than hammering a down vendor |
| Rate limits | Per-provider token bucket in Redis, respected before dispatch |
| Idempotency | Each send carries a stable idempotency key (see §6) |
| Observability | Request/response metadata logged with correlation ID; **credentials and message bodies are redacted** |
| Credentials | From `.env` → `config/*` only; never in code, migrations, seeders, or logs (NFR-11) |

---

## 6. Webhooks & Idempotency

Inbound webhooks (Meta Lead Ads, WhatsApp, email delivery/bounce, AI-calling status) follow one shared pipeline:

```
Provider → HTTPS endpoint
  → 1. Verify signature/token           (reject → 401, before any parsing)
  → 2. Persist raw payload              (provider_webhook_logs, always)
  → 3. Idempotency check                (dedupe on provider event ID)
  → 4. ACK 200 immediately
  → 5. Queue async processing           (queue: webhooks)
```

**Why ACK before processing:** providers retry on slow/failed responses. Acknowledging first and processing async prevents duplicate deliveries caused by our own latency.

**Idempotency rules:**

- Every inbound event is deduplicated on the provider's event/message ID, stored with a unique constraint. A replayed delivery is recorded and skipped, not reprocessed (FR-META-03).
- Every *outbound* send stores a deterministic idempotency key (`campaign_id` + `lead_id` + `channel` + attempt semantics) so a retried job cannot double-send.
- Raw payloads are retained for replay/debugging under the same retention policy discipline as other stored data.

---

## 7. Redis Usage

| Use | Notes |
|-----|-------|
| Queue backend | All queues in §4 |
| Cache | Reference data (products, templates, DNC policy config), report aggregates |
| Rate limiting | API throttling (NFR-07) and per-provider outbound limits |
| Locks | Mutexes for auto-dialer "next lead" claim and campaign state transitions — prevents two telecallers being handed the same lead |
| Session | Web CRM sessions |

MySQL remains the system of record; nothing business-critical exists only in Redis.

---

## 8. Scheduler

Laravel Scheduler (single cron entry) drives:

| Job | Cadence | Purpose |
|-----|---------|---------|
| Scheduled campaign dispatch | minute | Start campaigns whose time has arrived |
| Follow-up reminders | minute | Fire due reminders (FR-FUP-01) |
| Missed follow-up detection | frequent | Flag overdue follow-ups (FR-FUP-03) |
| Report pre-aggregation | hourly/nightly | Keep dashboards fast (FR-RPT-05) |
| Recording retention purge | nightly | Delete expired recordings, audited (FR-REC-05) |
| Provider status reconciliation | periodic | Poll for statuses missed by webhooks |
| Queue/failed-job health check | periodic | Surface stuck or failing work |

Scheduled commands are thin — they dispatch jobs; they do not contain business logic.

---

## 9. Key Flows

**Outbound contact (any channel, any trigger):**
```
Trigger (manual send / campaign / auto dialer)
  → DncService::canContact(lead, channel)   ← single gate, no bypass
  → eligible?  no → log skipped + reason → stop
               yes ↓
  → resolve template + recipient detail
  → enqueue job (idempotency key)
  → provider call (retry/backoff/breaker)
  → persist message + status
  → webhook updates status later
```

**Auto dialer next-lead:**
```
Telecaller requests next
  → acquire Redis lock (prevent double-assignment)
  → pop from prioritised queue (priority, follow-up due, recency)
  → apply skip rules (DNC, invalid phone, recently contacted) → skip+log if failed
  → create call record + dial intent
  → release lock
```

**Interest detected (any of 8 sources):**
```
Signal → InterestEngine::record(lead, product, source, evidence)
  → single DB transaction:
     status → product interest → label → score
     → interested-leads entry → temperature → optional follow-up
  → events emitted for reporting/audit
```
All seven effects commit together or not at all (FR-INT-02).

---

## 10. Architecture Decision Records

### ADR-A — Web CRM delivery model
**Status:** ✅ **Confirmed and implemented at Phase 8** (2026-08-10)
**Decision:** One Laravel application. Blade renders page shells/layouts; page JS (jQuery/AJAX) calls the same app's `/api/v1/*` endpoints, same-origin, session-authenticated via Sanctum.
**Rationale:** The specified stack has no SPA framework. Single deployable, no CORS, one auth story; the same API surface is reused unchanged by Flutter (token auth) later.
**Alternative rejected:** Fully decoupled static frontend — adds a second deployable and CORS/auth complexity for no stated benefit.
**Revisit if:** a decoupled frontend or SPA framework was actually intended.

**As built (Phase 8):**
- Browser requests authenticate by **session cookie**, via Sanctum's stateful middleware; the Flutter app will keep using bearer tokens against the same endpoints. One API surface, two credential styles, decided per request by the Origin/Referer.
- A session cookie beats a token in browser storage here: same-origin means there is nothing to gain from a token, and a token any XSS could read is strictly worse.
- Blade renders **page shells and reference data only**. Every row and every write goes through `/api/v1/*`. A Blade page that queried leads directly would be a second implementation of scoping and filtering — and the second path is always the one that gets a rule wrong.
- Bootstrap 5 and jQuery are loaded from a **CDN**, not built with Vite: the shared-hosting target has no Node ([DEPLOYMENT §3A](DEPLOYMENT.md#3a-shared-hosting-mode-hostinger--active-target-)), so a deploy stays "upload plus composer". The cost is the CSP looseness already tracked as **T-39**; Vite remains wired up if the assets ever need self-hosting.

### ADR-B — Human calling mechanism
**Status:** Proposed default · **confirm before Phase 9** (highest-impact open decision)
**Decision:** The Android app places calls natively and records locally where the OS/device and applicable rules permit. The Web CRM is the system of record and orchestrator: it creates the call record and dial intent, drives auto-dialer queue/skip rules, and ingests the uploaded recording afterward.
**Rationale:** Section 9 (web one-click calling + auto dialer) and Section 10 (Android local recording) only reconcile if the device dials and the server orchestrates.
**Alternative rejected:** Browser-based CPaaS/WebRTC softphone (Twilio/Exotel-style) — not indicated by the brief, and incompatible with the stated device-side recording flow.
**Impact if wrong:** Phases 9, 10, 11, 31, 32 change materially.

### ADR-C — Multi-tenancy timing
**Status:** Accepted · implemented Phase 2 (amended during implementation)
**Decision:** Every primary table gets a `tenant_id` column, **NOT NULL DEFAULT 0**, from Phase 2 onward. `0` denotes the default/only tenant; real tenant IDs begin at 1 in Phase 36. No global scopes, no tenant middleware, no tenant-aware logic until then — schema reservation only.
**Rationale:** Retrofitting tenancy onto a populated schema is high-risk and touches every table; the column now costs almost nothing.
**Amendment (Phase 2):** originally specified as *nullable*. Implementation testing proved that unsafe: in SQL `NULL != NULL`, so every composite unique index containing a nullable `tenant_id` — `leads(tenant_id, phone_e164)`, `products(tenant_id, code)`, `tags`, `templates`, `lead_sources` — was **silently inert**. Duplicate leads would have been accepted in production while the schema appeared to forbid them. `DEFAULT 0` preserves the tenancy reservation and makes the constraints actually enforce. Regression test: `SchemaIntegrityTest::tenant_id_defaults_to_zero_and_is_never_null`.
**Alternative rejected:** Add tenancy entirely in Phase 36 — expensive migration across all data.

### ADR-D — Communication provider abstraction
**Status:** Accepted
**Decision:** Every channel sits behind a `ChannelProvider` interface; concrete vendors are config-bound and swappable. Named today: Mailercloud (Email), Official WhatsApp Business API — BSP TBD, BhashSMS (SMS), Vaaad (AI Calling). RCS and Voice vendors TBD.
**Rationale:** Three vendors are unnamed and any of the named ones may change; CRM/campaign logic must not depend on vendor specifics.
**Consequence:** Vendor-specific payload quirks live only in the provider class and its tests.

### ADR-E — Centralized DNC gate
**Status:** Accepted (critical)
**Decision:** `App\Services\Dnc\DncService` is the sole authority on "may we contact this lead on this channel now." Every outbound path calls it. No channel, campaign, or dialer module re-implements suppression.
**Rationale:** Section 13 is a critical business rule spanning 9 outbound paths; duplicated logic guarantees eventual divergence.
**Consequence:** Adding a channel means calling the gate, not writing new suppression logic. Enforced by tests per channel (FR-DNC-01/02).

---

## 11. Observability

- **Correlation ID** per request, propagated into queued jobs and provider calls, so a campaign send can be traced end to end.
- **Audit vs. activity logs**: audit = who changed what (compliance-grade, immutable); activity = user-visible lead timeline. Separate concerns, separate tables.
- **Structured logs** with context; no stack traces in API responses (NFR-08).
- **Redaction**: credentials, tokens, and message bodies are never logged in full.
- **Health signals**: queue depth, failed-job count, provider breaker state, webhook lag.

---

## 12. Scalability Notes

- Stateless app servers (session/cache in Redis) → horizontal scaling.
- Queue workers scale independently of web tier; `campaigns` is the elastic pool.
- Reporting reads from pre-aggregated tables rather than scanning transactional tables (FR-RPT-05).
- `tenant_id` present from day one keeps the multi-tenant path open (ADR-C).
- Provider abstraction keeps vendor swaps/additions cheap (ADR-D).

---

## 13. Non-Goals

Browser-based softphone/WebRTC (ADR-B) · microservices — this is a modular monolith by intent · separate Android business logic (NFR-05) · direct DB access from any client (NFR-01) · GraphQL — REST only for v1.

---

## 14. Open Decisions Requiring Confirmation

| ADR | Decision | Default assumed | Confirm by |
|-----|----------|------------------|------------|
| A | Web CRM delivery model | Blade + AJAX in the same app as the API | Phase 8 |
| B | Human calling mechanism | Android dials/records; Web CRM orchestrates | **Phase 9** |
| C | Multi-tenancy timing | Schema-only `tenant_id` now | Phase 2 |
| D | WhatsApp BSP / RCS / Voice vendors | Interface-based, vendors TBD | Phases 14/16/17 |

Also pending: the role model in [PROJECT_REQUIREMENTS.md §2](PROJECT_REQUIREMENTS.md).
