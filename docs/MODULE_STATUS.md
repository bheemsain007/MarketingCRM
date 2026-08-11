# Module Status

| | |
|---|---|
| **Version** | 1.7 |
| **Last updated** | 2026-08-11 |
| **Current position** | **Phase 8 UI pass complete and Phase 19's admin surface built early. Suite: 657 passing, 0 failing.** Phase 11 (Call Recording) still needs T-44 answered and ADR-B confirmed before it can start |
| **Related** | [TESTING.md](TESTING.md) · [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) · [TODO.md](TODO.md) |

## Definition of Done

A phase is marked **Done** only when all five hold:

1. Code implemented following the layering rules ([ARCH §2](ARCHITECTURE.md#2-request-lifecycle--layering))
2. Tests written and passing, including any critical-rule suites ([TESTING §4](TESTING.md#4-critical-rule-coverage-mandatory))
3. Test results recorded in [TESTING.md §7](TESTING.md#7-test-results-log)
4. Docs updated in the same phase — API endpoints documented, schema recorded, rules captured
5. Completion report delivered: files changed, DB changes, endpoints, test results

Status values: **Done** · **In progress** · **Blocked** · **Not started**

---

## Phase Tracker

| Phase | Module | Status | Requirements covered | Tests | Blockers / notes |
|:-----:|--------|--------|----------------------|-------|------------------|
| 1 | Requirements + Architecture | **Done** | — (defines all) | n/a | 12 docs written. ADR-A..E recorded; ADR-B needs confirmation before Phase 9 |
| 2 | Database | **Done** | NFR-13, BR-DUP-01/02, BR-CUST-01, BR-PROD-01, FR-ATT-01, BR-DNC-02, BR-STAT-02, BR-PAY-02, BR-TEMP-02 | 58 pass / 0 fail | Laravel 12.65 + MySQL 8.4. 28 migrations, rollback verified. 6 enums, 21 models, seeders idempotent. ADR-C amended: `tenant_id` is `NOT NULL DEFAULT 0`, not nullable — see CHANGELOG |
| 3 | Laravel API Foundation | **Done** | NFR-02, NFR-03, NFR-04, NFR-07, NFR-08, SEC-OPS-03 | 86 pass / 0 fail | Envelope, error catalogue, exception handler, correlation ID, rate limiters, list conventions, `/health`. Rate limits use proposed defaults (T-04). OpenAPI generation deferred (T-05) |
| 4 | Authentication + Roles | **Done** | ROLE-01..07, FR-ATT-01, SEC-AUTH-01..04/06, SEC-AUTHZ-01..06, SEC-AUD-02 | 117 pass / 0 fail | Sanctum auth, 6 roles, 41 permissions, data scoping, work sessions, audit logging. Roles are seeded data — still adjustable (T-08). 2FA (T-09) deferred |
| 5 | Products | **Done** | P1–P7, NFR-03, SEC-AUTHZ-02 | 137 pass / 0 fail | CRUD + archive/restore, permission-gated. Delete archives rather than destroys so interest history and product reporting survive |
| 6 | Leads | **Done** | FR-LEAD-01..11, BR-DUP-01/02, BR-ASSIGN-01..06, SEC-AUTHZ-03/04, SEC-IN-06, SEC-FILE-01/02, SEC-PII-05 | 231 pass / 0 fail | CRUD, E.164 normalisation, duplicate detection, assignment (manual/round-robin/load-balanced), notes, timeline, scoping + IDOR policy. FR-LEAD-07 closed: queued CSV import with header auto-detection, per-row report, retention purge. **CSV/TSV only — `.xlsx` needs a spreadsheet dependency (T-45)** |
| 7 | Lead Status + Product Interest | **Done** | FR-STAT-01..03, FR-STAT-05, BR-STAT-01..05, BR-PROD-01..03, BR-DNC-07, SEC-IN-06 | 273 pass / 0 fail | Transition matrix enforced, append-only history, reopen authority, per-product interest, BR-STAT-04 clarified. **`Converted` deliberately blocked until Phase 22** (needs a sale). FR-STAT-04 (temperature) belongs to the Interest Engine, Phase 20. `DncService` built early — see below |
| 8 | Web CRM UI | **Core done** | ADR-A, ROLE-01..07 (UI surface), SEC-AUTH-06, SEC-AUTHZ-02/03/04, FR-LEAD-01 | 657 pass / 0 fail (suite total) | ADR-A confirmed and built: session auth, role-aware shell, dashboard, leads list + detail (status, products, notes, timeline, history), products, imports. Dialer screen added with Phase 10; **lead create/edit form, assignment UI, account page, DNC screen and settings module added 2026-08-11**. **Dashboard gained a scoped, permission-gated "your work" panel and the lead page gained Follow-ups, Messages and Deals tabs, both 2026-08-11**, so Phases 13/15/21/22/23 are reachable from the screen telecallers work in. **T-46 is closed**: user administration shipped 2026-08-11 as a full slice - service, 7 endpoints and a screen - with `users.manage` and `roles.manage` deliberately separate (SEC-AUTHZ-05) |
| 9 | Calling | **Server side done** | FR-CALL-01..05, FR-CALL-08, BR-CALL-01/04/05, BR-DNC-07, SEC-AUTHZ-03/04 | 316 pass / 0 fail | Dial intents, DNC gate before every dial, calling hours in the lead's timezone, write-once outcomes, automatic suppression and callbacks, scoped history, call panel in the UI. **Built on ADR-B's proposed default; nothing here changes if ADR-B is decided differently** — a call record and an orchestrated gate are needed under any dialling mechanism. The device-side half is Phases 31/32 |
| 10 | Auto Dialer | **Server side done** | FR-CALL-06..08, BR-CALL-02/03, SEC-AUTHZ-03/04 | 337 pass / 0 fail | Sessions with start/pause/resume/stop surviving reload, ordered queues, all five skip rules logged with reasons, single-assignment via row lock + claim TTL, **and the telecaller-facing dialer screen**. **ADR-B-agnostic**: the dialer decides *which* lead is next; the device places the call |
| 11 | Call Recording | Not started | FR-REC-01..05, BR-REC-01..03 | — | Depends on ADR-B; retention period TBD |
| 12 | Facebook + Instagram Lead Capture | **Done (needs keys to run)** | FR-META-01..03, SEC-WH-01..05, FR-LEAD-10, BR-DUP-02 | 18 pass / 0 fail | Subscription challenge, HMAC-SHA256 over the raw body, per-leadgen replay protection, enqueue-only endpoint, Graph fetch, duplicate merge, Instagram/Facebook source attribution, auto-assignment. **Unlike the outbound channels this cannot run unkeyed** - the webhook carries a `leadgen_id`, not the lead, so a page access token is required for anything to happen |
| 13 | Email | **Done (unkeyed)** | FR-COMM-01..06, FR-EMAIL-01, BR-DNC-01/03/05 | 23 pass / 0 fail | Channel-agnostic driver layer, DNC-gated at queue **and** dispatch, queued sending with backoff, delivery webhook. **Works end to end with no credentials** via `LogDriver`, which records `provider=log` rather than pretending to deliver. Mailercloud wire format + webhook signing are provisional pending their docs (T-53); bounce-to-suppression left to Phase 19 (T-54) |
| 14 | WhatsApp | Not started | FR-COMM-*, FR-WA-01 | — | **BSP not named** (ADR-D) |
| 15 | SMS | **Done (unkeyed)** | FR-COMM-01..06, FR-SMS-01, BR-DNC-01/03/05 | 10 pass / 0 fail | One driver class - everything else was already channel-agnostic from Phase 13. Forces HTTPS because the provider documents credentials in an HTTP query string (T-55); sends the national number, refusing non-Indian ones rather than truncating; treats a 200-with-error-body as a rejection, not a success. Wire format provisional (T-53) |
| 16 | RCS | Not started | FR-COMM-*, FR-RCS-01 | — | **Vendor not named** (ADR-D) |
| 17 | Voice | Not started | FR-COMM-*, FR-VOICE-01 | — | **Vendor not named** (ADR-D) |
| 18 | Campaign Engine | Not started | FR-CAMP-01..05, BR-CAMP-01..05 | — | Confirm frequency caps (BR-CAMP-04) |
| 19 | DNC Engine | **Partly built early** | FR-DNC-01/02/04 (partly), BR-DNC-01/02/06/07 | 18 pass / 0 fail | **Critical.** Gate + suppression writing built at Phase 7; **admin surface built 2026-08-11** — list, manual suppression, and audited removal that deactivates rather than deletes. **The full per-channel test matrix landed 2026-08-11** - all six message channels plus human calling and the auto dialer, with scheduled campaigns outstanding until Phase 18 (T-61). **Still owned by this phase:** policy configuration (BR-DNC-04/FR-DNC-03), skip reporting (BR-DNC-05) and the inbound-keyword source. See note below |
| 20 | Interested Lead Engine | **Done** | FR-INT-01..03, FR-STAT-04, BR-INT-01..04, BR-SCORE-01, BR-TEMP-01/02 | 26 pass / 0 fail | One engine, seven atomic effects, derived and explainable score, config-driven weights, confidence-gated AI signals, capped negatives, decay sweep, and the interested / hot / warm / product-wise views. **Closes FR-STAT-04**, deferred here from Phase 7. Score model and bands are still PROPOSED - confirm (T-16) |
| 21 | Follow-up + Notifications | **Done** | FR-FUP-01..05, FR-NOTIF-01..03, BR-FUP-01..03, BR-NOTIF-01/03/04, BR-ASSIGN-04 | 31 pass / 0 fail | Full lifecycle, append-only reschedule chain, scheduler-owned `Missed`, once-only reminders, follow-ups transferring on reassignment (**T-14 answered**). **Caught a data-destroying Phase 2 schema bug**: `follow_ups.scheduled_at` carried MySQL's implicit `ON UPDATE CURRENT_TIMESTAMP`, so every write reset the schedule to now. Fixed by migration with a regression test. Push/email channels (BR-NOTIF-03) deferred to the Android phases; in-app is complete |
| 22 | Sales | **Done** | FR-SALE-01..05, BR-SALE-01..04, BR-CUST-01..04, BR-STAT-05 | 26 pass / 0 fail | Opportunities with per-product lines, quotations with threshold-gated discount approval (approver is never the raiser), lost reasons as a closed enum, sales creating deduplicated Customers, and **`Converted` unblocked**. 5 tables, rollback verified. Money is `decimal(12,2)`; prices and quotation items are snapshotted. **BR-PAY-05's payment condition on `Converted` is not yet enforced** - Phase 23 (T-57). One schema deviation: no separate `lost_sales` table (T-56) |
| 23 | Payment | **Offline half done** | FR-PAY-01, FR-PAY-03..05, BR-PAY-01..06, BR-STAT-05 | 22 pass / 0 fail | Recording, the full BR-PAY-02 matrix, append-only history, derived balances, partials summing, and scheduler-owned overdue with notification. **Closes T-57**: `Converted` now requires a sale AND a Partial/Paid payment. **Caught a data-scoping security bug** - a teamless Team-scoped user saw every teamless user's records (SEC-AUTHZ-03). **Not built: payment links and gateway collection (FR-PAY-02)** - needs the gateway named (T-34); the columns and the `gateway` method are in place for it to land as a driver |
| 24 | AI Calling | Not started | FR-AI-01 | — | Vaaad credentials + API docs needed |
| 25 | AI Call Analysis | Not started | FR-AI-01, BR-INT-04 | — | |
| 26 | Telecaller Reports | Not started | FR-RPT-01, FR-RPT-04, FR-RPT-06 | — | **Confirm attribution model** ([GLOSSARY §2.6](GLOSSARY.md#26--attribution--needs-a-business-decision-)) — affects telecaller pay |
| 27 | Business Reports | **Done** | FR-RPT-02, FR-RPT-04..06 | 23 pass / 0 fail | Summary, revenue, pipeline, product and source reports, all against GLOSSARY Part 2 formulas with hand-computed fixtures. Rates carry their denominator structurally (FR-RPT-06); a zero denominator is null, never 0%. Cohort-based conversion; booked and collected never summed; org-timezone periods. **Chart.js dashboards added 2026-08-11** (FR-RPT-03), so the phase is complete bar the two things that need other work: campaign performance (needs Phase 18) and telecaller performance (FR-RPT-01 - needs T-24, Phase 26) |
| 28 | Web CRM Testing | Not started | NFR-09, NFR-10 | — | |
| 29 | Web CRM Production | Not started | SEC-OPS-* | — | Hosting/infra not yet specified — [DEPLOYMENT §11](DEPLOYMENT.md#11-open-decisions) |
| 30 | Flutter Android | Not started | NFR-05 | — | |
| 31 | Android Calling | Not started | FR-CALL-03 | — | Depends on ADR-B |
| 32 | Android Recording | Not started | FR-REC-01..03 | — | Depends on ADR-B |
| 33 | Offline Sync | Not started | FR-REC-02 | — | |
| 34 | Android Testing | Not started | NFR-09 | — | |
| 35 | Production Release | Not started | — | — | |
| 36 | Future SaaS / White Label | Not started | NFR-13 | — | `tenant_id` reserved from Phase 2 |

---

## Sequencing Note — DNC (Phase 19)

The roadmap places the DNC Engine at Phase 19, *after* the communication channels (13–17) that must call it. Since every channel is required to route through `DncService` and no channel may implement its own suppression ([ADR-E](ARCHITECTURE.md#adr-e--centralized-dnc-gate), BR-DNC-01), building channels first would mean either stubbing the gate or retrofitting six modules.

**Recommendation:** implement `DncService` and `dnc_entries` early — with Phase 6/7 (Leads/Status), since suppression is written by status changes anyway — and keep Phase 19 as the phase that completes the policy configuration, admin UI, reporting, and the full per-channel test matrix.

**Partly acted on at Phase 7.** Lead status `Not Interested` must write suppression (BR-DNC-07), and status changes landed in Phase 7. The alternative was writing `dnc_entries` directly from `LeadStatusService`, which is precisely the per-module suppression ADR-E forbids — so a minimal `App\Services\Dnc\DncService` was built with `suppress()`, `canContact()` and the flag-sync.

**Further acted on 2026-08-11.** The same argument applied again to removal: suppression was being written with no way to read it back or lift it, which is a compliance problem rather than a missing feature. `DncService::remove()`, three endpoints and the admin screen are now built. **Phase 19 still owns:** policy configuration (BR-DNC-04 — the reason × channel matrix is currently in the `DncReason` enum, i.e. code, not data), skip reporting (BR-DNC-05), the inbound-keyword source, and the full per-channel test matrix.

---

## Blockers Summary

| Blocker | Blocks | Owner |
|---------|--------|-------|
| ~~T-48 — tests could not run~~ | ~~Every phase's Definition of Done~~ | ✅ **Worked around 2026-08-11.** Root cause was a corrupt `mysql.db` Aria table, not a missing database. Suite runs as `root` via a gitignored `.env.testing`. Still outstanding: the corruption itself, and that this is MariaDB 10.4 rather than the pinned MySQL 8.4.9 |
| ADR-B — calling architecture | **Phases 9, 10, 11, 31, 32 — blocking now** | Stakeholder |
| ~~ADR-A — Web CRM delivery model~~ | ~~Phase 8~~ | ✅ Confirmed 2026-08-10 |
| Role model sign-off | Phase 4 | Stakeholder |
| ~~BR-STAT-02 transition matrix~~ | ~~Phase 7~~ | ✅ Signed off 2026-08-10 |
| Attribution model (who gets conversion credit) | Phase 26 — **affects pay** | Stakeholder |
| Stack versions + hosting target | Phases 3, 29 | Stakeholder |
| WhatsApp BSP / RCS / Voice vendors | Phases 14, 16, 17 | Stakeholder |
| Payment gateway choice | Phase 23 | Stakeholder |
| Provider credentials (Mailercloud, BhashSMS, Vaaad, Meta) | Phases 12–17, 24 | Stakeholder |
