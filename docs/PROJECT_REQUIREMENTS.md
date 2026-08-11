# Project Requirements

| | |
|---|---|
| **Version** | 1.1 |
| **Last updated** | 2026-08-10 (Phase 1) |
| **Status** | Baseline agreed; items marked *(proposed)* await stakeholder confirmation |
| **Related** | [ARCHITECTURE.md](ARCHITECTURE.md) · [BUSINESS_RULES.md](BUSINESS_RULES.md) · [GLOSSARY.md](GLOSSARY.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [MODULE_STATUS.md](MODULE_STATUS.md) |

## How to read this document

Every requirement has a stable ID (e.g. `FR-LEAD-03`). Tests cite these IDs so coverage is traceable back to a requirement, and MODULE_STATUS.md marks a phase complete only when its requirements' acceptance criteria pass. IDs are never reused or renumbered — a dropped requirement is struck through, not deleted.

Prefixes: `FR-` functional · `NFR-` non-functional · `ROLE-` role/permission · `OUT-` out of scope.

---

## 1. Overview

Telecalling CRM + Lead Management + Multi-Channel Communication + AI Calling Platform, built as an API-first Laravel backend consumed by a Web CRM (Blade + Tailwind/Bootstrap + jQuery/AJAX) and, later, a Flutter Android app. Both clients consume the same `/api/v1/` REST API and share all business logic — no logic is duplicated per client.

### 1.1 Core products sold/managed

| # | Product | Delivery |
|---|---------|----------|
| P1 | News Portal Development | SaaS |
| P2 | NGO Portal Development | SaaS |
| P3 | Epaper Development | SaaS |
| P4 | Business Website Development | Project |
| P5 | Shopping Portal Development | Project |
| P6 | Matrimonial Portal Development | SaaS |
| P7 | News Posting in Your News Portal | Service |

A single lead carries independent interest/status per product — e.g. News Portal = Interested, Epaper = Warm, News Posting = Hot at the same time.

---

## 2. Roles & Permissions *(implemented Phase 4 — still open to change, T-08)*

The brief requires role-based access but does not name the roles. The baseline below is **implemented and seeded**; roles and permissions are data, so changing the model is a seeder change, not a migration.

| ID | Role | Scope of data | Key capabilities |
|----|------|---------------|------------------|
| ROLE-01 | **Super Admin** | All | Everything, including user/role management, provider credentials, DNC policy config, retention settings |
| ROLE-02 | **Admin** | All | All CRM operations and reports; cannot change provider credentials or system config |
| ROLE-03 | **Manager** | Own team | Assign/reassign leads within team, view team reports, approve discounts, run campaigns |
| ROLE-04 | **Telecaller** | Own assigned leads only | Call, log call outcome, add notes, set follow-ups, mark interest; cannot reassign, bulk-export, or view other telecallers' data |
| ROLE-05 | **Accounts** | Payments/sales | Record payments, issue payment links, refunds, revenue reports; read-only on leads |
| ROLE-06 | **Viewer** | Read-only | Dashboards and reports only; no mutations |

**ROLE-07** — Permission checks are enforced server-side at the API/service boundary. Hiding a UI control is never the sole access control.

**Acceptance**: for each role, an authenticated request to an endpoint outside its scope returns `403` with the standard error envelope, proven by permission tests.

---

## 3. Functional Requirements

### 3.1 Lead Management

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-LEAD-01 | Create, view, edit, archive (soft delete) leads | Archived leads are excluded from default lists and all outbound targeting, and are restorable |
| FR-LEAD-02 | Search, filter, sort, paginate lead lists | List endpoint returns paginated results; filtering by status, temperature, product, source, owner, date range, and tag works in combination |
| FR-LEAD-03 | Tags and free-text notes on a lead | Notes are append-only with author + timestamp; tags are many-to-many and filterable |
| FR-LEAD-04 | Record lead source and campaign source | Every lead stores its origin; leads created from Meta webhooks retain the originating form/campaign |
| FR-LEAD-05 | Lead priority | Priority is settable and sortable; auto dialer honours it in queue ordering |
| FR-LEAD-06 | Duplicate detection on create and import | A lead whose normalised phone (E.164) matches an existing lead is flagged as duplicate and not silently inserted twice |
| FR-LEAD-07 | CSV/Excel import | Import runs as a queued job; produces a per-row result report (imported / duplicate / invalid) and never partially corrupts on failure |
| FR-LEAD-08 | Lead assignment to a telecaller | Assignment is auditable (who assigned whom, when); reassignment preserves history |
| FR-LEAD-09 | Activity timeline per lead | Timeline shows calls, messages across all channels, status changes, notes, follow-ups, and payments in one chronological view |
| FR-LEAD-10 | Automatic assignment for inbound/webhook leads | Method per BR-ASSIGN-01; an unassignable lead is queued and raises a notification, never dropped or force-assigned |
| FR-LEAD-11 | Repeat enquiries route to the existing owner | A duplicate enquiry does not create a second owner for the same person (BR-ASSIGN-05) |

### 3.2 Lead Status & Temperature

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-STAT-01 | 11 lead statuses: New, Contacted, Interested, Follow-up, Callback, Proposal, Negotiation, Decision Pending, Converted, Lost, Not Interested | Only these values are accepted; invalid values rejected with `422` |
| FR-STAT-02 | Status transitions follow a defined matrix | Transition matrix defined in BUSINESS_RULES.md (Phase 7); disallowed transitions rejected with `422` |
| FR-STAT-03 | Every status change is logged | `lead_status_history` records from-status, to-status, actor, timestamp, source channel; history is never overwritten |
| FR-STAT-04 | Lead temperature: Hot, Warm, Cold, Dormant — derived, not freely set | Temperature is recalculated by the Interest Engine on interest signals and recency changes; manual override (if allowed) is logged |
| FR-STAT-05 | Per-product interest status per lead | Changing product A's status leaves product B's untouched |

### 3.3 Calling

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-CALL-01 | 11 call statuses: Connected, Not Connected, Busy, No Answer, Call Rejected, Switched Off, Not Reachable, Invalid Number, Call Back Requested, No Response, Wrong Number | Only these values accepted |
| FR-CALL-02 | Each call stores start time, end time, duration, status, notes, telecaller, lead, recording ref, follow-up ref | All fields persisted and retrievable via the call history endpoint |
| FR-CALL-03 | One-click calling | Initiating a call from the CRM creates a call record and dial intent (mechanism per ARCHITECTURE.md ADR-B) |
| FR-CALL-04 | Call history per lead and per telecaller | Both views paginate and filter by date range and status |
| FR-CALL-05 | Call scheduling and callback | A "Call Back Requested" outcome can create a scheduled callback with reminder |
| FR-CALL-06 | Auto dialer: start, pause, resume, stop, next lead | Session state survives page reload; pause halts dialing without losing queue position |
| FR-CALL-07 | Auto dialer skip rules | Leads failing DNC, missing valid phone, or already contacted within the configured window are skipped and logged as skipped |
| FR-CALL-08 | **DNC check before every dial** | No dial intent is ever produced for a suppressed lead — enforced in the service layer, covered by tests (see FR-DNC-01) |

### 3.4 Call Recording

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-REC-01 | Recording captured on the Android device where OS/device and applicable rules permit | Unsupported devices degrade gracefully — the call still logs, without a recording |
| FR-REC-02 | Local upload queue, uploaded when connectivity returns | Recordings survive app restart and offline periods; no duplicate uploads |
| FR-REC-03 | Recording linked to call ID, lead ID, user ID, duration, upload status, created time | All fields persisted server-side |
| FR-REC-04 | Secure access | Recordings are not publicly addressable; served via signed/expiring URLs to authorised roles only |
| FR-REC-05 | Retention and deletion controls | Retention period is configurable; expired recordings are purged by a scheduled job, and the purge is audited |

### 3.5 Communication Channels

Channel-agnostic requirements (apply to Email, WhatsApp, SMS, RCS, Voice, AI Calling):

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-COMM-01 | Individual, bulk, and scheduled sends per channel | Bulk and scheduled sends always execute via queued jobs, never inline in an HTTP request |
| FR-COMM-02 | Template management per channel | Templates are reusable, versioned in the sense that a sent message records the template used |
| FR-COMM-03 | Delivery status tracking | Provider webhooks/status polls update message state; unknown/failed states are visible, not swallowed |
| FR-COMM-04 | Every send is DNC-checked | Suppressed leads are excluded before dispatch and logged as skipped |
| FR-COMM-05 | Provider credentials from environment only | No key appears in source, migrations, seeders, or logs |
| FR-COMM-06 | Provider failures are retried with backoff and surfaced | A provider outage does not lose messages silently; failures are visible in campaign logs |

Channel-specific:

| ID | Channel | Provider | Specific requirements |
|----|---------|----------|----------------------|
| FR-EMAIL-01 | Email | Mailercloud | Open tracking, click tracking, bounce handling where supported |
| FR-WA-01 | WhatsApp | Official WhatsApp Business API *(BSP TBD)* | Template messages, delivery + read receipts, inbound replies, webhooks |
| FR-SMS-01 | SMS | BhashSMS | Delivery reports |
| FR-RCS-01 | RCS | *(TBD)* | Rich media, buttons, tracking |
| FR-VOICE-01 | Voice SMS / Voice | *(TBD)* | Audio payload, delivery status |
| FR-AI-01 | AI Calling | Vaaad | Call status, duration, recording, transcript, summary, interest detection, AI score |

### 3.6 Campaign Engine

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-CAMP-01 | Campaign flow: audience → filters → DNC check → channel → template → queue → provider → webhook → CRM | Each stage is observable in campaign logs |
| FR-CAMP-02 | Lifecycle: create, edit, start, pause, resume, stop, schedule | Pause stops new dispatch without cancelling already-queued-in-flight sends ambiguously — in-flight behaviour is defined and logged |
| FR-CAMP-03 | Campaign eligibility check per lead | A lead enters the send queue only if DNC passes and it has a valid contact detail for that channel; ineligible leads are logged as skipped with a reason, never silently dropped |
| FR-CAMP-04 | Campaign logs and reports | Per-campaign counts of queued/sent/delivered/failed/skipped, with drill-down |
| FR-CAMP-05 | Bulk campaigns must use queues | A campaign of any size completes without HTTP timeout; verified by test with a large audience |

### 3.7 DNC / Suppression *(critical)*

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-DNC-01 | Leads marked Not Interested, Do Not Contact, Opted Out, Wrong Number, or Invalid Number are excluded from future outbound contact per the configured policy | Verified by test for **every** channel: Email, WhatsApp, SMS, RCS, Voice, AI Calling, human calling, auto dialer, scheduled campaigns |
| FR-DNC-02 | Single centralized DNC service | Exactly one code path decides contactability; no channel module reimplements it — enforced by code review and by tests that assert the service is the gate |
| FR-DNC-03 | Suppression policy is configurable | Which reasons suppress which channels is data/config-driven, not hardcoded per channel |
| FR-DNC-04 | DNC changes are audited | Adding/removing a lead from suppression records actor, reason, timestamp |

### 3.8 Interested Lead Engine

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-INT-01 | Interest signals accepted from human call, AI call, WhatsApp, Email, SMS, RCS, Voice, and manual CRM action | All eight sources route into the same engine |
| FR-INT-02 | On interest: update lead status, update product interest, apply label, recalculate score, add to Interested Leads, recalculate Hot/Warm/Cold, create follow-up if configured | All seven effects occur atomically; partial application is not possible |
| FR-INT-03 | Maintained views: All Interested, Hot, Warm, and product-wise interested leads | Each view is filterable and paginated |

### 3.9 Follow-ups

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-FUP-01 | Follow-up carries date, time, channel, reminder | Reminders fire via the scheduler |
| FR-FUP-02 | Actions: complete, reschedule, cancel | Each action is logged with actor and timestamp |
| FR-FUP-03 | Missed follow-ups are detected | A follow-up past its due time without completion is flagged as missed and reportable |
| FR-FUP-04 | Full follow-up history per lead | History survives reschedules — prior schedule is retained, not overwritten |
| FR-FUP-05 | Reminders are delivered to the owning user | In-app always; push/email per preference. A failed push never loses the in-app notification (BR-NOTIF-03) |

### 3.9a Internal Notifications

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-NOTIF-01 | CRM users receive notifications for follow-up due, lead assigned, campaign completed, payment overdue, upload failure, and approval requests | Each trigger produces exactly one in-app notification |
| FR-NOTIF-02 | Notifications are internal and never DNC-filtered | Suppression applies to leads, not staff (BR-NOTIF-01) |
| FR-NOTIF-03 | Read/unread state per user | Unread count is accurate and per-user |

### 3.9b Attendance & Productivity Tracking

Required to make FR-RPT-01's active/idle/office-hours metrics computable.

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-ATT-01 | A work session opens at login and closes at logout/timeout, on both web and Android | Sessions are never left open indefinitely — a stale session is auto-closed by the scheduler |
| FR-ATT-02 | User actions are recorded as activity signals | Calls, notes, status changes, sends, and follow-up actions all count as activity |
| FR-ATT-03 | Active, idle, and break time are derived per GLOSSARY §2.3 | A telecaller writing notes counts as active, not idle; marked breaks are excluded from idle |
| FR-ATT-04 | Explicit break start/stop | Break time is separated from idle time in all reports |

### 3.10 Sales

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-SALE-01 | Pipeline: Lead → Interested → Opportunity → Proposal → Negotiation → Sale → Payment → Converted | Stage changes are logged |
| FR-SALE-02 | Opportunity carries product(s) | An opportunity references one or more products with per-product value |
| FR-SALE-03 | Quotation and proposal generation | Quotation records line items, discount, and final value |
| FR-SALE-04 | Discount handling | Discounts beyond a threshold require Manager+ approval *(threshold configurable)* |
| FR-SALE-05 | Lost sale tracking with reason | Lost sales are reportable by reason |

### 3.11 Payments

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-PAY-01 | Payment states: Pending, Partial, Paid, Failed, Overdue, Refund | Only these values accepted; transitions covered by tests |
| FR-PAY-02 | Payment links | Generated per sale/customer; link state reflects payment outcome |
| FR-PAY-03 | Every payment links to Lead + Customer + Product + Sale | Orphan payment records cannot be created — enforced at DB and service level |
| FR-PAY-04 | Payment history and revenue reporting | Revenue aggregates by product, telecaller, campaign, and period |
| FR-PAY-05 | Partial payments accumulate correctly | Sum of partials equals paid amount; balance is derived, never manually edited |

### 3.12 Reporting

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-RPT-01 | Telecaller performance: assigned leads, calls, connected calls, talk time, avg call duration, interested leads, hot leads, follow-ups, sales, revenue, conversion rate, active time, idle time, office-hours activity | Every listed metric is present, computed by the formula in [GLOSSARY Part 2](GLOSSARY.md#part-2--metric-definitions), and matches an independently hand-computed fixture in tests |
| FR-RPT-06 | Every rate metric displays its denominator | "Conversion Rate (of leads assigned this period)" — an unlabelled rate is ambiguous and will be misread |
| FR-RPT-02 | Business dashboard: total leads, new leads, interested, hot, calls, AI calls, follow-ups, sales, payments, revenue, product performance, campaign performance, channel performance, telecaller performance, conversion rate | All listed tiles present |
| FR-RPT-03 | Charts rendered with Chart.js | Dashboard visualisations use Chart.js |
| FR-RPT-04 | Reports are date-range filterable | All reports accept a period filter |
| FR-RPT-05 | Reports must not degrade under data growth | Aggregates are indexed/pre-computed as needed; dashboard responds within an agreed budget *(target: < 2s, confirm)* |

### 3.13 Facebook / Instagram Lead Capture

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| FR-META-01 | Flow: Meta → webhook → Laravel → lead creation → duplicate check → campaign mapping → assignment | End-to-end covered by a feature test with a sample Meta payload |
| FR-META-02 | Webhook signature verification | Unsigned/invalid payloads rejected before processing |
| FR-META-03 | Webhook idempotency | A replayed Meta delivery does not create a duplicate lead |

---

## 4. Non-Functional Requirements

| ID | Requirement | Acceptance criteria |
|----|-------------|---------------------|
| NFR-01 | API-first: browser and mobile never touch MySQL directly | No DB credentials or queries exist outside the Laravel backend |
| NFR-02 | Versioned API under `/api/v1/` | All endpoints namespaced; breaking changes require a new version |
| NFR-03 | Consistent response envelope `{success, message, data, errors}` | Every endpoint, including error paths, returns the envelope — asserted in API contract tests |
| NFR-04 | Correct HTTP status codes | 200/201/204 success; 401/403/404/422/429 client; 5xx server |
| NFR-05 | No business logic duplicated between Web and Android | Both clients call the same endpoints; logic lives in services |
| NFR-06 | Bulk work via Laravel Queue; recurring work via Scheduler/Cron | No bulk send or import executes inline in a request |
| NFR-07 | Rate limiting on auth and bulk-trigger endpoints | Exceeding the limit returns `429` |
| NFR-08 | Structured logging and error handling | Failures are logged with context; no stack traces leaked to API responses |
| NFR-09 | Every module has tests before being marked complete | MODULE_STATUS.md never shows Done without a test result recorded in TESTING.md |
| NFR-10 | Critical rules have dedicated tests: DNC, status transitions, payment status, campaign eligibility | Named test cases exist per rule |
| NFR-11 | No hardcoded credentials | Secrets in `.env` only |
| NFR-12 | Security controls per SECURITY.md | Auth, RBAC, validation, CSRF, secure uploads, audit logs, SQLi/XSS protection |
| NFR-13 | Scalable toward multi-tenant SaaS / white-label / iOS | Schema reserves `tenant_id` from Phase 2; no design decision blocks later tenancy |
| NFR-14 | Provider-agnostic channels | Each channel sits behind an interface; swapping a vendor does not touch CRM or campaign logic |
| NFR-15 | Documentation stays current | `/docs` updated in the same phase as the code it describes |

---

## 5. Out of Scope (current roadmap)

| ID | Item | Note |
|----|------|------|
| OUT-01 | iOS application | Phase 36+ |
| OUT-02 | Multi-tenant billing and white-label UI | Phase 36; schema reserves `tenant_id` from Phase 2 |
| OUT-03 | Customer self-service portal | Phase 36+ |
| OUT-04 | Browser-based softphone/WebRTC calling | Not assumed — see ARCHITECTURE.md ADR-B |

---

## 6. Open Questions

Tracked in [TODO.md](TODO.md) and [ARCHITECTURE.md](ARCHITECTURE.md#open-decisions-requiring-confirmation): Web CRM delivery model, human calling mechanism, multi-tenancy timing, and the unnamed WhatsApp BSP / RCS / Voice vendors. Section 2 (Roles) and the thresholds marked *(confirm)* above are also pending.
