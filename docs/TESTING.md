# Testing

| | |
|---|---|
| **Version** | 1.3 |
| **Last updated** | 2026-09-05 |
| **Status** | **A large suite exists and is green.** Backend: **1,117 tests / 3,764 assertions passing** (§7, confirmed 2026-08-28). Flutter: **127 tests passing** (§9, Phase 34 — up from 119: +8 closing gaps a coverage audit found cheap and worth closing now). *(This line described the pre-Phase-3 state — "no test suite exists" — until now; it had not been touched since 2026-08-10 despite MODULE_STATUS's Definition of Done making a recorded result here the precondition for marking any phase Done, NFR-09.)* |
| **Related** | [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) · [BUSINESS_RULES.md](BUSINESS_RULES.md) · [MODULE_STATUS.md](MODULE_STATUS.md) |

---

## 1. Policy

1. **No module is marked complete without tests.** MODULE_STATUS.md never shows Done without a corresponding result row in §7 (NFR-09).
2. **Manual/UI verification is never sufficient evidence** for completion.
3. **Every test names the requirement or rule it proves** (see §3). Untraceable tests are churn.
4. **Bug fixes ship with a regression test** that fails before the fix.
5. **Tests must not call real provider APIs.** All external calls are faked/mocked; a green suite must never send a real SMS or dial a real number.

## 2. Test Levels

| Level | Location | Scope | Speed |
|-------|----------|-------|-------|
| **Unit** | `tests/Unit` | One service/class in isolation; business rules, calculations, state machines. No HTTP, minimal DB | Fast — the bulk of rule coverage |
| **Feature** | `tests/Feature` | Full request through middleware → controller → service → DB; auth, permissions, validation | Medium |
| **API contract** | `tests/Api` | Response envelope, status codes, pagination/filter params, error codes | Medium |
| **Integration** | `tests/Feature/Integration` | Queue jobs, scheduler commands, webhook pipelines with faked providers | Slower |

Weight toward Unit for rules, Feature for flows. Avoid one heavyweight end-to-end test standing in for missing rule tests.

## 3. Traceability

Test names cite requirement/rule IDs so coverage maps back to the specification:

```php
/** @covers FR-DNC-01, BR-DNC-02 */
public function test_suppressed_lead_is_excluded_from_sms_campaign(): void

/** @covers BR-STAT-02 */
public function test_converted_lead_cannot_transition_to_any_status(): void
```

The completion report for each phase lists which IDs became covered.

### 3.1 Rule-coverage audit — 2026-08-11

Traceability is only worth having if somebody checks it. Every `BR-*`, `FR-*` and `SEC-*` ID
was extracted from the specification and matched against the code and the suite:

| | Defined | Cited in code | Named in a test |
|---|---|---|---|
| **BR-** (BUSINESS_RULES.md) | 66 | 66 | 62 |
| **FR-** (PROJECT_REQUIREMENTS.md) | 84 | 61 referenced | — |
| **SEC-** (SECURITY.md) | 53 | 31 referenced | — |

**No ID is cited anywhere in the code or tests that is not defined in the specification.** That
was the check most likely to fail — invented rule numbers are how a spec quietly stops being the
source of truth — and it came back clean.

The **11 business rules with no test naming them** account for themselves exactly:

| Rules | Why there is no test |
|---|---|
| BR-CAMP-01..05 | Campaigns are Phase 18. Not built |
| BR-REC-01..03 | Call recording is Phase 11, blocked on **T-44** |
| BR-DUP-03, BR-DUP-04 | Email secondary matching and merge are **not built** — **T-64** |
| BR-DNC-04 | The matrix is code rather than configuration — **T-65**, needs a decision |

In other words: **every business rule that is both implemented and unblocked now has a test that
names it.** Two rules were implemented but unnamed until this audit — BR-CUST-02 (billing identity
belongs to the Customer) and BR-INT-03 (a signal retains its evidence) — and both now have one.

Two rules are named in tests but cited in no source file (BR-ASSIGN-06, BR-PROD-02). Both are
genuinely enforced; the behaviour is proven at the boundary rather than annotated at the
implementation, which is the weaker of the two places to record it but not a coverage gap.

> **Corrected 2026-08-28** — this was a point-in-time audit dated 2026-08-11 and was never revisited
> as the gaps it found closed. Three of the four rows above are stale: BR-CAMP-01..05 now has a named
> test (`Feature\Campaigns\CampaignEngineTest`, §7 "Campaign engine (Phase 18)", 2026-08-12);
> BR-DUP-03/BR-DUP-04 now has one (`Feature\Leads\LeadDuplicateTest`, §7 "Duplicate review and merge
> (T-64)", 2026-08-12); BR-DNC-04 now has one (§7 "DNC matrix overrides (T-65)", 2026-08-12). Only
> **BR-REC-01..03** is still accurately "no test" — call recording is still blocked on T-44 (MODULE_STATUS
> Phase 32). The Defined/Cited/Named counts above were not recomputed for this correction; a great deal
> of code and many tests were added after 2026-08-11 and those counts should be treated as historical,
> not current.

## 4. Critical Rule Coverage (mandatory)

These are the rules where a silent regression is a business or compliance problem. Each needs explicit, named tests — not incidental coverage (NFR-10).

### 4.1 DNC / Suppression — the highest-priority suite

| Test | Proves |
|------|--------|
| Suppressed lead excluded — one test **per channel**: Email, WhatsApp, SMS, RCS, Voice, AI Calling, human call, auto dialer, scheduled campaign | FR-DNC-01 — ✅ **all channels**, including scheduled campaigns, closed 2026-08-12 by Phase 18 (T-61 — see `Feature\Campaigns\CampaignEngineTest`, "a lead suppressed after audience build is skipped at dispatch"). See `Feature\Dnc\DncChannelMatrixTest` |
| Reason × channel matrix: Wrong Number blocks phone channels but **not** email; Bounced Email blocks email only | BR-DNC-02 |
| Suppression applied *after* audience build still blocks at dispatch time | BR-DNC-03 |
| Every skip writes a log row with reason | BR-DNC-05 |
| Automatic suppression triggers: status → Not Interested; call → Wrong/Invalid Number; STOP keyword; hard bounce | BR-DNC-07 |
| Merged duplicate leads union their suppression | BR-DUP-04 |
| Suppression add/remove is audited; removal requires Manager+ | BR-DNC-06 |

### 4.2 Lead status transitions

- Every allowed cell in the BR-STAT-02 matrix succeeds; every disallowed cell returns `422` (table-driven test over the full 11×11 grid).
- `Converted` is terminal from every status.
- Reopen from `Lost`/`Not Interested` requires Manager+ **and** a reason.
- Reopening from `Not Interested` does **not** silently clear suppression.
- Every transition writes `lead_status_history`; history is never overwritten.

### 4.3 Payment status

- Full BR-PAY-02 matrix, allowed and disallowed.
- Orphan payment (missing lead/customer/product/sale) is rejected.
- Successive partial payments sum correctly; balance is derived, not settable.
- `Converted` requires a Sale with a Partial/Paid payment.
- Overdue is set by the scheduled job, not manually.

### 4.4 Campaign eligibility

- Ineligible leads are **skipped and logged**, never silently dropped.
- Missing channel contact detail excludes the lead.
- Archived leads excluded.
- Frequency caps enforced (BR-CAMP-04).
- Large audience completes via queue without HTTP timeout (FR-CAMP-05).
- Stopped campaigns cannot resume (BR-CAMP-05).

### 4.5 Permissions

For each role in PROJECT_REQUIREMENTS §2: an in-scope request succeeds, an out-of-scope request returns `403`. Includes IDOR — a telecaller requesting another agent's lead by ID (SEC-AUTHZ-04).

### 4.6 Idempotency

- Replayed webhook (same provider event ID) creates no duplicate lead/message (FR-META-03).
- Retried send job does not double-send (unique `idempotency_key`).
- Duplicate lead create/import does not insert twice (BR-DUP-02).

## 5. Test Data & Doubles

| Concern | Approach |
|---------|----------|
| Fixtures | Model factories with states (`Lead::factory()->suppressed()`, `->hot()`) |
| Database | Migrations + transactions per test; isolated test DB, never a shared/real one |
| Providers | Each `ChannelProvider` has a fake implementation bound in tests; assertions on what *would* have been sent |
| Queues | `Queue::fake()` for dispatch assertions; real queue processing in integration tests |
| Time | Frozen/travelled clock for follow-up due, overdue payments, score decay, calling hours |
| Webhooks | Recorded real-shape sample payloads per provider, stored as fixtures |
| Randomness | Seeded — no flaky tests dependent on random data |

## 6. CI & Quality Gates *(workflow written 2026-08-11 — see `.github/workflows/ci.yml`)*

Runs on every push:

1. `composer audit` (SEC-OPS-01)
2. Static analysis — **Larastan at level 5, installed 2026-08-11**. **97** findings baselined (`phpstan-baseline.neon`), down from 280 after generating model annotations; the gate is "no new errors" and the remaining burn-down is T-63
3. Code style — Laravel Pint
4. Full test suite
5. Coverage report

**Merge blocked if:** any test fails, a security advisory is found, Pint reports a style violation, or the migration chain does not survive up/down/up.

**Coverage is reported but NOT enforced.** The proposed floor (90% on `app/Services/**`, 70% overall) has not been signed off, and failing a build on a number nobody agreed teaches people to disable the check rather than to write tests. Turn it on by dropping `--min=0` and removing `continue-on-error` once the floor is confirmed (T-06).

**The workflow pins MySQL 8.4 and PHP 8.2** (T-02). The local suite currently runs on MariaDB 10.4 (T-48), so **the first green CI build is what closes that caveat** - until then, every result in §7 carries it.

## 7. Test Results Log

Updated at the end of every phase, alongside the phase completion report.

| Phase | Date | Tests | Pass | Fail | Coverage | Notes |
|-------|------|-------|------|------|----------|-------|
| 1 | 2026-08-10 | 0 | — | — | — | Documentation-only phase; nothing to test |
| 2 | 2026-08-10 | 58 (298 assertions) | 58 | 0 | — | Schema + enum rule suites. **Caught a real bug**: a nullable `tenant_id` made every composite unique index inert, so duplicate leads would have been accepted in production. Fixed to `NOT NULL DEFAULT 0`, regression test added |
| 3 | 2026-08-10 | 86 (400 assertions) | 86 | 0 | — | +28 API contract tests. **Caught a real bug**: the exception handler rewrote `HttpResponseException` as a blank 500, discarding already-built responses (throttling, `abort()` with a response). Fixed by passing them through |
| 4 | 2026-08-10 | 117 (531 assertions) | 117 | 0 | — | +31 auth, RBAC and data-scoping tests. Includes the permission-denial matrix per role and scope isolation (telecaller/manager/admin) |
| 5 | 2026-08-10 | 137 (596 assertions) | 137 | 0 | — | +20 product API tests. Also fixed a latent tooling issue: PowerShell-written PHP files carried a UTF-8 BOM since Phase 2 |
| 6 | 2026-08-10 | 200 (720 assertions) | 200 | 0 | — | +63 lead tests. **Caught a design flaw**: unassigned leads were invisible at Team scope, deadlocking assignment — a manager could not assign a lead they were not allowed to see |
| 6 (complete) | 2026-08-10 | 231 (860 assertions) | 231 | 0 | — | +31 CSV import tests closing FR-LEAD-07. Migration rollback re-verified on both new tables |
| 10 (UI) | 2026-08-10 | 339 (1191 assertions) | 339 | 0 | — | +2 tests for the dialer screen: permission-gated access, and the skip panel present. The run itself is exercised by the API suite rather than through the page |
| 10 | 2026-08-10 | 337 (1187 assertions) | 337 | 0 | — | +21 auto-dialer tests. **Caught two design gaps**: a run that ended because everything left was skipped returned a bare null and threw away the reasons, and the queue's untouched-leads-first ordering meant a naive skip test never reached the skipped lead. Both fixed. Migration rollback verified |
| 9 | 2026-08-10 | 316 (1114 assertions) | 316 | 0 | — | +25 calling tests, including the **mandatory DNC-before-dial suite** (§4.1). Covers the gate reading `dnc_entries` rather than the cached flag, per-reason channel scoping, calling hours in the lead's timezone, write-once outcomes, and automatic suppression/callback side effects. Migration rollback verified |
| 8 | 2026-08-10 | 291 (1048 assertions) | 291 | 0 | — | +18 Web CRM tests. Pages are shells, so the suite covers what matters: session login opens a work session and audits like the token path, credentials give no enumeration oracle, page access is permission-gated, the dashboard counts inside the caller's scope, lead detail refuses an out-of-scope id, and **the API still accepts bearer tokens** — session auth was added alongside, not instead |
| 13 (Email) | 2026-08-11 | 23 | 23 | 0 | — | ✅ **Verified.** +13 in `Feature\Messaging\EmailSendTest` including the **mandatory DNC suite** (§4.1): suppressed lead refused *and* the skip recorded, a lead suppressed **after queueing** still not sent (BR-DNC-03), a bounced-email suppression leaving SMS contactable (BR-DNC-02), never dispatched inline, missing address refused, IDOR and permission gates, the unconfigured-channel fallback recording `provider=log`, a configured provider marking `sent` but **not** `delivered`, 4xx not retried vs 5xx retried, and template placeholders substituted **not executed**. +10 in `DeliveryWebhookTest`: forged/missing/wrong secret refused, unconfigured endpoint refusing everything, every call logged before it is acted on, the secret never landing in the log, delivery and bounce applied distinctly, unknown ids acknowledged, unrecognised events recorded rather than guessed, and redeliveries absorbed by the unique index |
| 15 (SMS) | 2026-08-11 | 10 | 10 | 0 | — | ✅ **Verified.** One driver class - the gate, queue, retry policy and status transitions were already channel-agnostic from Phase 13. Tests cover what is genuinely SMS-specific: national number on the wire (not E.164), a non-Indian number refused rather than truncated, **credentials never sent over plaintext HTTP**, a 200-with-error-body treated as a rejection rather than a success, a genuine non-200 retried, plus the DNC gate proved for this channel specifically and the subject field rejected |
| 12 (Meta capture) | 2026-08-11 | 18 | 18 | 0 | — | ✅ **Verified.** Subscription challenge echoed as bare text and failing closed when unconfigured; unsigned, forged and unconfigured deliveries all rejected **before anything is created** (FR-META-02, SEC-WH-01); a rejected body logged **without** storing the forged content (SEC-WH-04); replay absorbed by the unique index and a mixed new/repeat delivery processing only the new one (FR-META-03, SEC-WH-03); the endpoint proved to enqueue only, never calling Graph or creating a lead inline (SEC-WH-05); end-to-end creation with phone normalisation and auto-assignment (FR-META-01, FR-LEAD-10); Instagram attributed separately; a repeat enquiry merging rather than duplicating (BR-DUP-02); custom answers kept as a note; failures recorded against the delivery; and capture proved impossible without a page access token |
| 21 (Follow-ups) | 2026-08-11 | 31 | 31 | 0 | — | ✅ **Verified.** +21 lifecycle tests: owner defaulting, one-open-per-lead-product with different products coexisting, past-dated refused, reschedule preserving the original, completed follow-ups unreschedulable, scheduler-set `Missed` with late completion still allowed, future follow-ups untouched, reminders firing once at the lead time and not at all when too far out, disabled-account owners not retried for ever, dry run, follow-up transfer on reassignment, working-list scope and ordering, and the lead-delegated IDOR guard. +10 notification tests including **notifications reaching a user about a DNC-suppressed lead** (BR-NOTIF-01) and someone else's notification returning 404 rather than 403. **Caught a data-destroying Phase 2 schema bug** - see the regression test `updating_a_follow_up_does_not_reset_its_scheduled_time` |
| 22 (Sales) | 2026-08-11 | 26 | 26 | 0 | — | ✅ **Verified.** Opportunity value derived from lines and not settable, prices snapshotted against a later change, closed deals frozen; lost reasons enum-only with `Other` requiring a note and no reopening; discounts below/above threshold, **self-approval refused**, telecaller refused, rejection reason required, approval audited; quotation items copied not referenced; sale creating a deduplicated Customer with `origin_lead_id`, closing the opportunity, refused twice or at zero value, and **credit not moving on reassignment**; `Converted` still refused without a sale, allowed with one, and still terminal. Migration rollback verified. Also **fixed a pre-existing flaky test** in `LeadApiTest` |
| 23 (Payments) | 2026-08-11 | 22 | 22 | 0 | — | ✅ **Verified.** Includes the mandatory payment-status suite (section 4.3): the **full BR-PAY-02 matrix driven over every from/to pair**, orphan rejection with links taken from the sale not the caller, multi-product sales requiring an explicit product, partials summing with a derived balance, failed and refunded payments excluded, refund terminal and requiring both a reason and the refund permission, append-only history, overdue refused by hand and set by the scheduler with a notification, and the BR-PAY-05 conversion precondition in all four states. **Caught a data-scoping security bug** - see the regression test `a_manager_with_no_team_sees_only_the_unassigned_pool` |
| 20 (Interest engine) | 2026-08-11 | 26 | 26 | 0 | — | ✅ **Verified.** All seven BR-INT-02 effects asserted together; repeat signals rescheduling rather than stacking follow-ups; **the engine refusing to walk a deal backwards** even though the matrix would allow a human to; non-interest signals scoring without touching status or labels; low-confidence AI recorded but not acted on and excluded from the view (BR-INT-04); confidence-weighted points; the -10 cap on repeat no-answers; clamping at both ends; **a runtime re-weight applying to history**; the explain endpoint; recency gating Hot; suppression forcing Dormant; `score`/`temperature` unreachable by PATCH (BR-TEMP-01); decay including the no-engagement fallback; the sweep and its dry run; and the interested / temperature / product-wise views with scope and permission checks |
| 27 (Business reports) | 2026-08-11 | 23 | 23 | 0 | — | ✅ **Verified**, every figure against a hand-computed fixture as FR-RPT-01 requires. Rates carrying their denominator and a zero denominator returning null; **average call duration divided by connected calls not attempts**; talk time excluding unconnected calls; booked vs collected kept separate; collected counted by payment date; net revenue after refunds; **cohort-based conversion with a previous-cohort lead proving it does not leak**; win rate over closed opportunities only; pipeline value open-only; loss reasons with shares; archived leads excluded; period scoping by event time; the stated timezone; an explicit date range; product and source breakdowns including unattributed; and permission checks |
| User admin (T-51) | 2026-08-11 | 23 | 23 | 0 | — | ✅ **Verified.** +18 API tests, the load-bearing ones being the escalation guards: an Admin creating a user but refused role assignment, **nobody changing their own roles including a Super Admin**, roles unreachable from a general edit, no self-disable, and the last active Super Admin protected. Plus disabling revoking tokens and closing the work session, the disabled account then refused at login, role changes audited, weak and duplicate emails refused, and the password never returned. +5 page tests proving an Admin sees the page **without** the role control and a Super Admin with it |
| Report dashboards (T-60) | 2026-08-11 | 4 | 4 | 0 | — | ✅ **Verified.** The page renders with Chart.js for a Manager, is refused to a Telecaller (who holds `reports.view` but not `reports.business`), appears in navigation only for those who may see it, and **carries the sentence warning that booked and collected are never added together** - the mistake the side-by-side chart invites |
| DNC channel matrix (section 4.1) | 2026-08-11 | 27 | 27 | 0 | — | ✅ **Verified.** The mandatory suite, completed. A suppressed lead refused **and the skip recorded** on all six message channels, with a matching control test per channel proving an unsuppressed lead still gets through; the reason × channel matrix asserted through `DncService` rather than only in the enum; a channel-specific opt-out leaving the others reachable; the BR-DNC-03 dispatch-time re-check on every channel; and section 4.6 idempotency - a redelivered job not re-sending, and the unique constraint asserted directly. **Scheduled campaigns remain untested** (T-61) |
| Lead page tabs | 2026-08-11 | 4 | 4 | 0 | — | ✅ **Verified.** Follow-ups, Messages and Deals tabs present for a role that may use them; the Follow-ups tab absent for Accounts, which holds no follow_ups permission; no write controls for a read-only role; and a Telecaller getting the scheduling and send controls but not the deal one. **Corrected a wrong premise**: every seeded role holds `sales.view`, so the Deals tab is correctly visible to all |
| Full-chain verification | 2026-08-11 | — | — | — | — | ✅ Not tests, but checks that had never been run: **all 39 migrations tear down cleanly** leaving only the `migrations` table, come back up to 53 tables, and the suite is still green afterwards. `composer audit` reports **no advisories** (SEC-OPS-01). Both are now CI gates |
| Config contract | 2026-08-11 | 3 | 3 | 0 | — | ✅ **Verified.** `EnvExampleTest` asserts every `env()` key the config reads is documented, that nothing credential-shaped carries a value (SEC-CFG-02), and that `APP_KEY`, `DB_PASSWORD` and `APP_DEBUG` are safe defaults. **Caught a real misconfiguration**: provider config read `WHATSAPP_DRIVER`/`WHATSAPP_TOKEN` while the example documented `WHATSAPP_PROVIDER`/`WHATSAPP_API_KEY`, so a correctly-configured deployment would have silently read null |
| Model annotations | 2026-08-11 | — | — | — | — | ✅ Static-analysis findings **280 → 97** via `@property` / `@property-read` generated from the schema and from reflecting relation methods. Confirmed nothing dangerous was hidden behind the noise; removed two provably-unreachable defensive branches; verified one flagged branch as live by an existing test rather than assuming a false positive |
| Static analysis | 2026-08-11 | — | — | — | — | ✅ Larastan level 5. **Caught a production-only bug**: `AuthService` called `env('TOKEN_EXPIRY_DAYS')` directly, which returns its default once `config:cache` runs - so a configured token lifetime silently reverted to 30 days in production only. 280 remaining findings baselined after triage (T-63); one flagged branch was verified as live by an existing test rather than assumed to be a false positive |
| Dashboard work panel | 2026-08-11 | 4 | 4 | 0 | — | ✅ **Verified.** A telecaller sees their own follow-ups and not a colleague's; a future follow-up is not counted as today's work; blocks are omitted rather than zeroed for roles without the permission (Accounts gets payments but not the unassigned pool or follow-ups; a telecaller gets neither of the manager blocks) |
| Rule-coverage audit | 2026-08-11 | 3 | 3 | 0 | — | ✅ **Verified.** Closed the two coverage gaps the audit found: BR-CUST-02 (billing fields start empty on the Customer and have nowhere to live on the Lead) and BR-INT-03 (a signal resolves back to the call it came from, and still does after a later signal recalculates the score) |
| Bounce → suppression (T-54) | 2026-08-12 | 5 | 5 | 0 | BR-DNC-07 | ✅ **Verified.** A hard bounce suppresses email and leaves the phone contactable; an unqualified bounce is recorded without suppressing; a payload-confirmed hard bounce does suppress; an unsubscribe stops email but not calls or SMS; a redelivered bounce does not stack rows |
| Undecryptable settings (T-52) | 2026-08-12 | 4 | 4 | 0 | SEC-CFG-06 | ✅ **Verified.** A row this APP_KEY cannot read falls back to the config default; one bad row does not take unrelated keys with it; the settings screen still loads; and the bad row can be overwritten to recover |
| Credentials in the cache (SEC-CFG-07) | 2026-08-12 | 2 | 2 | 0 | SEC-CFG-07 | ✅ **Verified.** No cache row contains a provider credential, asserted against the **`database`** cache store rather than the array store the rest of the suite uses — on `array` the bug cannot be seen. A second test pins that non-secret settings are still cached, so the fix did not quietly disable the cache |
| DNC matrix overrides (T-65) | 2026-08-12 | 8 | 8 | 0 | BR-DNC-04 | ✅ **Verified.** "Not Interested" can be narrowed to stop blocking manual calls and a reason can be widened; **no settings row can unblock a lead who opted out**, even written straight to the table; absolute reasons are absent from the settings API and refused by the allowlist; an unknown channel falls back to the built-in list rather than the part that parsed; an empty override restores it; a malformed value is refused at the boundary |
| Campaign engine (Phase 18) | 2026-08-12 | 16 | 16 | 0 | BR-CAMP-01..05, BR-DNC-03/05 | ✅ **Verified.** Starting queues rather than sending inline (202, not 200); the audience is materialised once and not rebuilt on resume; **a lead suppressed after the audience was built is skipped at dispatch** with no message row; a lead with no address for the channel, and a paused campaign, each skip with their own reason; daily caps apply while transactional and skipped messages do not consume them; stop is terminal and clone is the way back; a running campaign cannot be edited; reading and sending are separate permissions and a telecaller has neither; campaigns cannot dial; every targeted lead ends with a message or a reason |
| Campaign screens | 2026-08-12 | 4 | 4 | 0 | — | ✅ **Verified.** All three screens load for a Manager; a Viewer reads the list but is refused the builder; a Telecaller gets 403 and **no nav link offering it**; the builder never renders a calling channel, matching the API that would refuse one |
| Duplicate review and merge (T-64) | 2026-08-12 | 14 | 14 | 0 | BR-DUP-03/04 | ✅ **Verified.** **Merging a suppressed lead suppresses the survivor, and merging an unsuppressed one never lifts existing suppression** — the two that matter most. Calls, messages and notes move; the duplicate is kept, flagged and points at its survivor; a shared product does not break the unique constraint; a chain resolves to the lead holding the history; self-merge and double-merge are refused. A shared email flags a candidate and **both leads still exist**; a pair is recorded once; a dismissal is not re-raised; backfill pairs every combination |
| Duplicate review API and screen | 2026-08-12 | 9 | 9 | 0 | BR-DUP-03/04 | ✅ **Verified.** The queue returns both records inline and defaults to what still needs a decision; a manager chooses which lead survives; **a survivor from outside the pair is refused**, so the endpoint is not a merge-any-two-leads primitive; a telecaller can do neither; a dismissal without a reason is refused; **suppression union holds through the HTTP path too**; every merge writes an audit row; the screen loads and a telecaller gets no nav link |
| Telecaller reports (Phase 26) | 2026-08-12 | 12 | 12 | 0 | FR-RPT-01/04/06 | ✅ **Verified.** The formulas are pinned against their plausible-looking wrong versions: **average duration divides by connected calls, not attempts**; talk time counts connected calls only; connect rate and contact rate are shown to answer different questions; **idle time does not count note-writing as slacking**; every rate carries its denominator and a zero denominator is null, not 0%. Attribution: last owner by default, switchable to first interest, an unrecognised value falls back rather than zeroing everyone, and the report states which model it used. A telecaller cannot read it; Accounts can read business reports but not people ones |
| **Everything to date** | **2026-08-12** | **720 (2376 assertions)** | **720** | **0** | — | ✅ **Verified.** +381 over the Phase 10 baseline of 339. ⚠️ Still on MariaDB 10.4, not the pinned MySQL 8.4.9 - the CI workflow is what will settle that (T-06, T-48). ⚠️ Still on MariaDB 10.4, not the pinned MySQL 8.4.9 - the CI workflow is what will settle that (T-06, T-48). ⚠️ Still on MariaDB 10.4, not the pinned MySQL 8.4.9 - the CI workflow is what will settle that (T-06, T-48), run once T-48 was worked around. Broken down in the rows below. **Caveat: run on MariaDB 10.4.32, not the pinned MySQL 8.4.9** — good evidence, not proof against the production engine (T-48) |
| ↳ Settings module | 2026-08-11 | 22 | 22 | 0 | — | +18 API tests in `Feature\Settings\SettingsApiTest`: Admin sees operational groups but no credential group, Super Admin sees both, Manager refused outright, values fall back to config defaults, a stored secret is never returned, a short secret is not half-disclosed by its own hint, secrets **and** non-secrets are encrypted at rest, an Admin cannot set a credential, a mixed payload is refused whole rather than partly applied, unknown keys and wrong types refused, overrides win and are correctly typed, clearing restores the config default, repeat saves update rather than duplicate, and both audit actions fire without recording the value. +4 page tests. **Caught a real bug**: permission was derived from `type === 'secret'`, so an Admin could change provider *endpoints*, `key_id` and the gateway selector — none secret, all credential configuration |
| ↳ 19 (DNC admin) | 2026-08-11 | 18 | 18 | 0 | — | +14 API tests in `Feature\Dnc\DncApiTest`: listing and lead-scoped visibility, orphan entries visible only at All scope, blocked channels resolved from the reason, manual suppression and its idempotency, channel-specific opt-out, telecaller refused removal **on their own lead**, removal deactivating rather than deleting, mandatory reason, double-removal refused, flag recomputed when one of two suppressions is lifted, flag cleared on the last one, and the audit entry. +4 page tests for permission-gated rendering of the removal control |
| ↳ 8 (account page) | 2026-08-11 | 3 | 3 | 0 | — | All six roles can reach their own account page, the profile is shown without offering to edit it, and the header links to it. The change-password endpoint itself was already covered by `Feature\Auth\AuthenticationTest` since Phase 4 — this row is about reachability, not the rule |
| ↳ 8 (assignment UI) | 2026-08-11 | 6 | 6 | 0 | — | +5 page tests: the pool opens for a manager, a telecaller is refused it, navigation hides it from non-assigners, the assignment card appears on a lead only for assigners, and the pool is in the session-required set. +1 API test for the **`null` filter operator**, which was untested and which the whole pool page depends on |
| ↳ 8 (lead form) | 2026-08-11 | 10 | 10 | 0 | — | +7 page tests: create-form permission gating, the `/leads/create` route-ordering regression, edit-form prefill, the IDOR check on the edit URL, read-only roles refused on both, edit not offering create-only fields, and the detail page linking to edit only for editors. +3 API tests for **two bugs the form exposed**: `PATCH /leads/{id}` never translated `alt_phone` to the `alt_phone_e164` column (a 500 in dev, a silent discard in production), and `name` was `sometimes\|string`, so an empty string blanked the lead's name. **The feature suite could not be run**: the server on :3306 is MariaDB 10.4.32 rather than the pinned MySQL 8.4.9, and `crm_user`, `marketing_crm` and `marketing_crm_test` are all absent. Views compile, routes register, and the 62 DB-free unit tests pass — re-run this row's tests once the database is restored |
| 7 | 2026-08-10 | 273 (993 assertions) | 273 | 0 | — | +42 status and product-interest tests. **Caught a real bug**: product-interest propagation called the status service with a null actor, and the reopen guard skipped authority checks for system callers — so recording interest against a `Lost` lead silently reopened it, with no manager and no reason. Fixed in both places |
| 27, 19, 24/25, 11 | 2026-08-12 | — | — | 0 | — | The rest of the server-side roadmap in one pass — see MODULE_STATUS Phases 11/19/24/25/27 for what each added. Suite reached **789 passing** at this point |
| 14/16/17 (WhatsApp/RCS/Voice) | 2026-08-12 | — | — | 0 | — | One driver class per channel on the Phase 13 pipeline; unkeyed via `LogDriver` |
| Password recovery (SEC-AUTH-06) | 2026-08-13 | 26 | 26 | 0 | SEC-AUTH-06 | Hardened against an adversarial review that found seven real findings (a bcrypt timing oracle, a token usable after suspension, sessions surviving a reset, a shared limiter enabling full lockout, a mail-failure existence leak, a Unicode limiter bypass, an unbounded audit write) |
| Sales + payments UI | 2026-08-13 | — | — | 0 | — | Deals tab (products, quotations, record-sale) and `/payments` for ROLE-05 |
| Phase 30 (Flutter client) | 2026-08-13 | 87 (Flutter) | 87 | 0 | — | First Flutter suite. Tests run against the real `ApiClient` and a scripted backend, not a mocked repository, so envelope decoding and status-to-exception mapping are genuinely exercised |
| Phase 23 gateway + Phase 29 | 2026-08-13 | — | — | 0 | — | `Feature\Payments\PaymentLinkTest` (606 lines) + `Feature\Security\SecurityHeadersTest` (142 lines) added. **Backend: 851 passing, 0 failing** at this point. Pint and PHPStan clean |
| Six correctness bugs + templates (FR-COMM-02) | 2026-08-17 | — | — | 0 | — | Each of the six bugs reproduced with a failing test before the fix (scheduled campaigns never dispatching, an archived lead stalling a campaign forever, no rate limiter on `campaigns.start`, delivery status stuck at `sent` for four channels, abandoned work sessions never closing, audit-log immutability being a docblock rather than a control). **Backend: 946 passing, 0 failing** at this point |
| Mobile contact actions + templates | 2026-08-17 | 119 (Flutter) | 119 | 0 | — | +13 over the Phase 30 baseline of 106 (`flutter test`). `flutter analyze`: no issues |
| Lead export, attendance, pre-aggregation, notification triggers | 2026-08-27 | — | — | 0 | — | New `Feature\Leads\LeadExportTest`, `Feature\Attendance\*`, `Feature\Reports\ReportPreAggregationTest`, `Feature\Notifications\NotificationTriggersTest` |
| Nine Web CRM screens | 2026-08-27 | — | — | 0 | — | Templates, notifications, cross-lead follow-up/call/message history, DNC skip log, tag management, plus lead-page score/archive/recording/AI-call/interest controls |
| **Everything to date** | **2026-08-28** | **1,117 (3,764 assertions)** | **1,117** | **0** | — | ✅ **Verified.** Backend suite, confirmed current at this reconciliation pass — three separate documents (MODULE_STATUS, TESTING, API_DOCUMENTATION) had each stated a different, older total before this pass. **Flutter: 119 (2026-08-17), 0 failing.** ⚠️ Still on MariaDB 10.4, not the pinned MySQL 8.4.9 (T-02, T-48) — good evidence, not proof against the production engine. *(The rows between "Everything to date, 2026-08-12, 720" and here are summarised from commit messages, not itemised phase-by-phase the way earlier rows are — the per-phase breakdown for this period lives in the commits' own test output, not restated here.)* |
| Phase 34 (Flutter test strategy) | 2026-09-05 | 127 (Flutter) | 127 | 0 | — | ✅ **Verified**, `flutter test` run directly (see §9). +8 over the Phase-30/31 baseline of 119, closing the cheap-and-real gaps a coverage audit found rather than expanding the suite open-endedly: `AuthRepository.login`'s two malformed-2xx branches (no `token`, no `user` in the response) were unexercised; `LeadRepository.callability`'s `data == null` branch (a 200 that is not the envelope's data shape) failed closed correctly but had no test proving it, distinct from the already-tested offline/5xx paths; and `OutboxBanner` — the only UI surface for the Phase 33 offline queue — had no test coverage at all despite `Outbox` and `OutboxFlusher` being tested exhaustively underneath it. `flutter analyze`: no issues. Backend untouched — out of scope for this phase |
| Phase 28 (Dusk/browser E2E) | 2026-09-05 | 12 (Dusk) | 12 | 0 | — | ✅ **Verified**, `vendor/bin/phpunit -c phpunit.dusk.xml` run directly against a real Chrome 152 + matching ChromeDriver, a real `php artisan serve`, and a dedicated `marketing_crm_dusk` database (see §10). Backend Feature/Unit suite untouched — out of scope for this phase, and deliberately not re-run (composer.json's dev dependency addition is additive) |

### Suite composition after Phase 2

| Suite | Tests | Covers |
|-------|-------|--------|
| `Unit\Enums\LeadStatusTransitionTest` | 9 | BR-STAT-02 full 11×11 matrix, Converted terminal, reopen rules, FR-STAT-01 |
| `Unit\Enums\DncSuppressionMatrixTest` | 10 | BR-DNC-02 every reason × every channel, BR-DNC-07 auto-suppression |
| `Unit\Enums\PaymentStatusTransitionTest` | 8 | BR-PAY-02 matrix, collected-vs-booked (GLOSSARY §2.5), BR-PAY-05 |
| `Unit\Enums\LeadTemperatureTest` | 8 | BR-TEMP-02 bands, recency-as-gate |
| `Feature\Database\SchemaIntegrityTest` | 11 | Table presence, DB-enforced duplicate detection, FK cascade/restrict, soft deletes, mass-assignment guards (SEC-IN-06) |
| `Feature\Database\SuppressionRecordTest` | 8 | Channel-scoped suppression, deactivation, flag-is-not-source-of-truth |
| `Feature\Api\ResponseEnvelopeTest` | 9 | NFR-03 envelope on success *and* error paths, `{}` vs `[]`, 201/202, no internals leaked (SEC-OPS-03) |
| `Feature\Api\HealthEndpointTest` | 5 | `/health`, versioned prefix (NFR-02), correlation ID propagation |
| `Feature\Api\QueryOptionsTest` | 10 | Pagination meta, filter operators, **unknown filter/sort/include rejected with 422** (FR-LEAD-02) |
| `Feature\Api\RateLimitTest` | 4 | NFR-07 throttling in-envelope, `Retry-After`, limit relationships |
| `Feature\Auth\AuthenticationTest` | 14 | Login/logout/token scope, work-session opening (FR-ATT-01), audit of success and failure, no user-enumeration oracle, brute-force throttling, password change |
| `Feature\Auth\PermissionAndScopeTest` | 17 | Per-role permission matrix (ROLE-01..06), Super Admin bypass, **data scoping** — telecaller own / manager team / admin all — permission middleware, sensitive-permission auditing, seeder idempotency |
| `Feature\Products\ProductApiTest` | 20 | CRUD, per-role permission denial, validation, duplicate-code conflict, archive/restore, **interest history survives archiving** |
| `Unit\Support\PhoneNumberTest` | 19 | E.164 normalisation across every real-world input format; the property that all variants of one number collapse to one string (BR-DUP-01) |
| `Feature\Leads\LeadApiTest` | 24 | CRUD, duplicate detection across formats, **IDOR** (telecaller cannot read a colleague's lead by id), scope cannot be widened by a client filter, protected fields, search/filter/archive |
| `Feature\Leads\LeadAssignmentTest` | 14 | Manual/auto assignment, load balancing, open-lead cap, history preservation, repeat-enquiry routing (BR-ASSIGN-05), ineligible assignees |
| `Feature\Calls\AutoDialerTest` | 21 | **BR-CALL-03: two telecallers are never handed the same lead**, and a dangling claim is released after the TTL. Plus one open session per user, pause keeping queue position *and* releasing the held lead, state surviving a reload, run completion, and every skip rule (suppressed mid-run, cooldown, future follow-up, per-lead calling hours) recorded with its reason. Closed leads never enter a queue; a colleague cannot drive somebody else's run but a supervisor can stop it |
| `Feature\Calls\CallApiTest` | 25 | **FR-CALL-08 / BR-CALL-01: a suppressed lead can never be dialled** — through the dial-intent path *or* the log-a-past-call path — while a bounced email leaves calling allowed (BR-DNC-02) and the cached flag is never the authority (BR-DNC-01). Plus calling hours in the lead's timezone with `next_opening` (BR-CALL-04), `202` dial intents with a null outcome, write-once outcomes (`409`), zero talk time on unconnected calls, `wrong_number` → suppression, `call_back_requested` → follow-up for the same telecaller, callback time required, scoped history, and IDOR on both dialling and outcome-writing |
| `Feature\Web\WebCrmTest` | 18 | Session login/logout with work-session and audit parity against the token path, no user-enumeration oracle on the form, disabled accounts refused, every page requires a session, permission-gated page access, scope-correct dashboard counts, IDOR on lead detail, nav hidden for unusable features, **bearer tokens still work and API 401s are not redirects** |
| `Feature\Leads\LeadStatusTest` | 25 | BR-STAT-02 as the API enforces it: illegal transitions return the legal moves, the diagonal is empty, `Converted` is refused pending a sale and is terminal, reopen needs Manager+ **and** a reason, **reopening does not clear suppression** (BR-DNC-06), `Not Interested` auto-suppresses but `Lost` does not (BR-DNC-07), append-only history with actor and source, system changes recorded with a null actor, IDOR on the status endpoint |
| `Feature\Leads\LeadProductInterestTest` | 17 | BR-PROD-01 independence (changing one product leaves the others untouched), multi-product states, same matrix as lead status, **product decline does not suppress the lead** (BR-PROD-03), BR-STAT-04 forward-only propagation, closed leads not reopened by a product edit, no auto-convert, scope-bound nested routes |
| `Feature\Leads\LeadImportTest` | 31 | FR-LEAD-07 end to end: `202` + queued (never inline), header auto-detection and explicit `column_map`, BOM/semicolon/short-row/blank-line handling, **imported vs duplicate vs invalid**, one bad row does not stop the run, status/score not settable from a file (SEC-IN-06), `auto_assign` gated on `leads.assign`, report **IDOR** across users of the same role and team, row-job idempotency, PII retention purge |

Tests run against **MySQL** (`marketing_crm_test`), not SQLite in-memory — the suite asserts FK `RESTRICT` and composite-unique behaviour that SQLite does not reproduce, and testing on a different engine than production is exactly the drift this project set out to avoid.

## 8. Not Yet Decided

Whether to run mutation testing on the DNC service. Tracked in [TODO.md](TODO.md). *(Load testing targets for campaign throughput and dashboard response, FR-RPT-05, moved out of this list 2026-09-05 - see [LOAD_TEST_RESULTS.md](LOAD_TEST_RESULTS.md) and TODO.md's T-43 row.)*

~~Flutter test strategy (Phase 34)~~ — ✅ **written 2026-09-05, see §9.**

~~Browser/E2E testing for the Web CRM (Dusk?)~~ — ✅ **built 2026-09-05, see §10.** Laravel Dusk, chosen because it was the only option that needed no separate device/grid infrastructure to answer T-42 - it drives a real Chrome against the app's own Laravel routes, which is what "does the Blade+jQuery shell actually work in a browser" needs and nothing more.

## 9. Flutter Client Test Strategy (Phase 34)

Phases 30/31 built the Flutter client and its first 119 tests. Neither phase asked "does this
amount to a strategy" — they asked "does the feature I just built have tests," which is a different
and narrower question. This section is Phase 34's actual deliverable: an audit of what a mobile CRM
client needs covered, what of that is covered today, what gaps were real and cheap enough to close in
this pass (closed below, +8 tests), and what gaps are real but belong to later work — named as such
rather than left implicit.

### 9.1 The pyramid, in Flutter's own vocabulary

Flutter draws the same triangle the backend's §2 does, but the middle tier means something different
on a client: a "Feature" test on the backend drives a real HTTP request through middleware; the
equivalent here is a **widget** test, which mounts a real widget tree in a simulated binding — no
device, no emulator — and drives it with real taps and real text entry.

| Level | Location | What it exercises | Device/emulator needed? |
|-------|----------|--------------------|:---:|
| **Unit** | `test/core/`, `test/repositories/`, `test/state/` | `ApiClient`'s envelope/exception mapping, every repository, `Outbox`/`OutboxFlusher`, `AuthController` — against the **real** `ApiClient` and a scripted HTTP backend (`ScriptedApi`), never a mocked repository interface | No |
| **Widget** | `test/widgets/` | Real screens (`LeadDetailScreen`, `HomeShell`, `TemplateManagementScreen`, `OutboxBanner`) mounted via `pumpApp`/`Harness`, driven with `tester.tap`/`enterText`, asserting on what is actually painted | No — `flutter_test`'s binding simulates a device without running on one |
| **Integration** | *(does not exist yet)* | End-to-end on a real device or emulator via `package:integration_test` — the actual dial intent leaving the app, the actual `tel:` screen, actual audio capture | **Yes** |

**127 of 127 tests today are Unit or Widget.** There is no `integration_test/` directory, no
`flutter_driver` dependency, and nothing in `pubspec.yaml`'s `dev_dependencies` beyond `flutter_test`
itself (confirmed by reading `pubspec.yaml` directly, not assumed). This is not an oversight this phase
is closing — see §9.4. It is the honest boundary: everything this app does *except* actually placing a
call and actually capturing audio can be, and is, proven without a device.

The **Harness pattern** (`test/support/harness.dart`) is what makes the Unit tier possible without
mocking: it wires the *real* `AppDependencies` graph — the real `ApiClient`, the real `Outbox`, the real
`AuthController` — to fakes only at the three edges that would otherwise touch the outside world:
`ScriptedApi` (an `http.MockClient` standing in for the network), `InMemoryTokenStore` /
`InMemoryKeyValueStore` (standing in for device storage), and `RecordingDialer` (standing in for the OS
`tel:` intent). A test that mocked `LeadRepository` itself, the way some Flutter suites do, would pass
even if the envelope contract broke; this suite would not, because the real decoder sits between the
scripted response and the assertion.

### 9.2 What is covered today (127 tests, by file)

| File | Tests | Covers |
|------|:---:|--------|
| `core/api/api_client_test.dart` | 13 | Every status-to-exception mapping (401/403/404/409/422/429/5xx), bearer header, Idempotency-Key header, malformed/HTML/`success:false` bodies, `204` |
| `core/api/api_envelope_test.dart` | 7 | Envelope decoding, `dataMap`, field-error extraction |
| `core/offline/outbox_test.dart` | 8 | Restart persistence, FIFO order, pending/failed counts, listener notifications, corrupt-file recovery, idempotent load |
| `core/offline/outbox_flusher_test.dart` | 10 | Delivery, Idempotency-Key replay, 409-as-delivered, offline deferral, halt-at-first-failure (not reordering), 422 permanent-fail, **401 defers rather than permanently failing**, single-flight under concurrent triggers, connectivity-triggered flush, 5xx retry |
| `repositories/lead_repository_test.dart` | 13 | List/search/scoping, IDOR (403), malformed list envelope, and the **callability gate** — callable, suppressed, outside-hours, stale-flag-vs-gate, offline-fails-closed, 5xx-fails-closed, **malformed-200-fails-closed** (new) |
| `repositories/call_repository_test.dart` | 12 | Dial intent, suppressed-at-dial (not just at the check), **never queued offline** (a dial intent skipping the gate would be worse than losing it), write-once outcomes, offline queueing end to end |
| `repositories/follow_up_repository_test.dart` | 5 | Diary read, server-default status filter, outcome completion, IDOR |
| `repositories/message_repository_test.dart` | 8 | Every channel goes through the CRM (never a deep link), subject only on email, suppressed-lead 403, offline throws rather than queues (an ungated send must never be replayed) |
| `state/auth_controller_test.dart` | 16 | Restore (4, incl. **malformed `/auth/me`**, new), sign-in (8, incl. **malformed login response ×2**, new), global 401 handling (1), sign-out (2) |
| `widgets/login_flow_test.dart` | 7 | Full login → home → 401-mid-session → sign-out flow through real screens |
| `widgets/lead_contact_actions_test.dart` | 11 | The four contact actions, suppressed-lead refusal per channel, and the **SEC-PII-04 regression guard** (below) |
| `widgets/template_management_test.dart` | 13 | Template picker rendering server-rendered text, both permission gates (below), CRUD through the screen |
| `widgets/outbox_banner_test.dart` | 4 | **New.** The queue's only UI surface (below) |

### 9.3 The four areas this phase was asked to audit

**Auth/session.** Login, session restore (`GET /auth/me` against the stored token), global 401 handling,
and sign-out are covered at both the Unit tier (`auth_controller_test.dart`, 16 tests) and the Widget
tier (`login_flow_test.dart`, 7 tests) — restore with no token, a good token, a revoked token, and
offline-at-launch were all already covered before this phase. **There is no token-refresh flow, and
that is not a gap**: this app carries a Sanctum personal access token, which does not rotate — a 401
anywhere signs the user out (`AuthController.handleUnauthenticated`, tested), and `AuthRepository` has
no refresh method to be missing. What genuinely had no test: `AuthRepository.login` throws
`MalformedResponseException` itself if the `200` response is missing `token` or missing `user` — a
defensive branch for "the server said success but sent something this app cannot use." Two tests now
cover it. `GET /auth/me` returning a `200` with no data (the same shape of failure, on the restore path)
now has one too.

**The DNC/callability gate (ADR-B).** `GET /leads/{id}/callability` is asked before every dial and never
answered locally (`Callability`'s own doc comment: *"it does not read `lead.is_suppressed`, it does not
compare the clock to an office window, it does not check the phone string"*). Before this phase,
6 tests already proved: callable, DNC-suppressed, outside-calling-hours, the denormalised
`is_suppressed` flag losing to the gate when they disagree, offline failing closed, and a `5xx` failing
closed. The one branch with no test was `data == null` — a `200` that is not the envelope's data shape,
which is not an exception at all (no `NetworkException`, no `ServerException`), just a value the
repository has to notice and refuse to trust. One test closes it. **Provider-refusal** (a suppressed
send from an actual channel provider) is a different endpoint's concern — `POST /leads/{lead}/messages`
— and was already covered per-channel in `lead_contact_actions_test.dart`'s "a suppressed lead is
refused on every channel" group, which this phase re-read rather than duplicated.

**The offline outbox.** `Outbox` (8 tests) and `OutboxFlusher` (10 tests) were already the most
thoroughly tested part of this client — restart survival, FIFO ordering, the single-flight guard against
a double-trigger flush, the halt-at-first-undeliverable-entry rule, 409-as-success, 422-as-permanent,
and 401-as-deferred-not-discarded were all covered before this phase touched anything. What had **zero**
coverage was `OutboxBanner` — the widget a telecaller actually sees, and the only way they know a call
outcome is still waiting or can push it by hand. Four new tests cover it: absent when empty, showing the
pending count after a launch-time auto-flush could not deliver, showing the *rejected* count
distinctly for a permanently-failed entry (and proving the auto-flush does not touch it — a flusher that
retried a 422 forever would show a growing number for something already known unfixable), and "Send
now" actually draining the queue once the network is back.

One thing the audit found but did **not** fix, because it is a product decision and not a test gap: a
permanently-failed entry has no way to be dismissed from the app. `Outbox.discard()` exists and is
identical to `remove()`, but nothing in `lib/` calls it — `OutboxBanner`'s only control is "Send now",
which the flusher itself skips for a `permanentlyFailed` entry. A telecaller who gets a validation
failure they cannot fix (say, a stale `callback_at`) is stuck looking at "1 rejected by the CRM"
forever with no button to acknowledge it. This is real, but it is a UI feature to design and build, not
a test to write against code that does not exist — flagged separately rather than absorbed into this
phase's scope.

**Permission-gated UI.** The audit's honest finding: this client has exactly **two** permission gates
in the whole of `lib/`, both on templates (`user.can('templates.view')` for the entry point in
`home_shell.dart`, `user.can('templates.manage')` for the write controls in
`template_management_screen.dart`) — grep for `.can(` confirms it. Every other action in this app either
has no client-side gate at all (the four contact actions render unconditionally; the server's `403`
is what actually refuses) or is gated by a fact on the record rather than a permission (the Email button
disappears when `lead.email` is null, not when a permission is absent). **Both of the two real gates
already had both branches tested** — `templates.view` present/absent for the entry point, and
`templates.manage` present/absent for the write controls — so there was nothing to close here. If a
third permission-gated control is added later, the standard this suite already sets is: one test for
held, one for not held, same as these.

**SEC-PII-04 (phone numbers never rendered).** There is no runtime helper enforcing this — it is
enforced by the model shape: `Lead.phone` and `Lead.phoneFormatted` exist (the number has to be held to
be handed to `PhoneDialer`), but grepping `lib/screens/` and `lib/widgets/` for `.phone` finds exactly
one call site, `deps.dialer.dial(lead.phone)` in `lead_detail_screen.dart` — never a `Text` widget.
`TemplateRender` goes further and simply **does not model** the preview endpoint's `recipient` field,
which is the lead's actual address — the field cannot reach a screen because there is nowhere on the
class to hold it, which is a compile error away from a review comment. No other model in `lib/models/`
carries a phone number at all (`Call`, `FollowUp`, `OutboundMessage`, `User` — checked directly). The
existing guard, `expectPhoneNeverRendered` in `lead_contact_actions_test.dart`, walks **every**
`Text`/`RichText`/`EditableText` actually painted rather than checking specific widgets, and is applied
to the lead detail screen, the lead list (including the regression case where a missing company/city
used to fall back to the number), and a queued-send confirmation. Given no other screen holds a phone
number to leak, this is complete, not merely applied to "the screens it was written for" — there is
nothing else to apply it to.

### 9.4 The device-dependency boundary (Phase 32/33)

Phase 32 (Android call recording) is blocked on **T-44**: third-party call recording is blocked outright
on most Android 10+ handsets, and the only way to know what a given OEM/OS combination actually allows
is a physical-device test. Phase 33 built the offline queue (`Outbox`/`OutboxFlusher`) in anticipation of
recording uploads, reasoning that FR-REC-02's "durable queue, dedupe, single-flight flush" requirement
would apply to an upload exactly as it applies to a call outcome — and wired it to call outcomes now,
because that is the write that exists today.

**Everything up to and including that queue is tested, and tested without a device**, because none of it
touches a platform channel:

- The queue itself — persistence, ordering, dedupe via `Idempotency-Key`, single-flight (§9.2).
- The flusher's retry policy — what is retryable, what is permanent, what defers (§9.2).
- The dial *sequence* short of the actual dial — `CallRepository.start` creates the call record and
  never queues a dial intent offline (a call that skipped the DNC gate would be worse than a lost one),
  covered by `call_repository_test.dart`.
- `PhoneDialer` is faked by `RecordingDialer` in every test — it records that `dial(number)` was called
  and returns a scripted success/failure, but no test claims to know what happens after that call, because
  nothing in this suite can observe it.

**What genuinely cannot be tested without a physical device**, and what this phase deliberately did
**not** fake:

- Whether call recording is possible at all on a given handset (T-44's actual question).
- The real behaviour of the OS `tel:` intent — whether it actually opens the dialer, backgrounds this
  app correctly, and returns control the way `PhoneDialer`'s real implementation assumes.
- Actual audio capture and actual upload — there is no upload code yet (Phase 32 has not built it), so
  there is nothing to write a fake-device test against; writing one now would be testing a mock of code
  that does not exist.
- Anything in the `android/` native project itself (permissions, foreground services, OEM-specific
  restrictions) — none of that runs under `flutter test`, which never leaves the Dart VM.

The rule this phase applied throughout: if a test would need to assert something about behaviour this
suite cannot actually observe (a dialer that really opened, a device that really recorded), it does not
get written with a fake standing in for the unknown answer. The `RecordingDialer` fake is honest about
this — it only ever asserts *that this app asked the OS to dial*, never what the OS did next.

### 9.5 How these tests actually run today, versus how they should once CI exists

**Today: locally, on demand, by whoever is making the change.** `.github/workflows/ci.yml` exists (T-06)
but has never executed — there is no git remote yet (confirmed this session) — and even once one exists,
the workflow as written **does not run the Flutter suite at all**: it is scoped to
`working-directory: backend` and has no `flutter test` step. So today, "the Flutter suite is green" means
exactly what this phase's own report means: someone ran `flutter test` (and `flutter analyze`) in
`mobile/` by hand and read the output, the same way every Flutter test count in this document
(87 → 119 → 127) was produced. That is a materially weaker guarantee than the backend's — a backend
regression is *supposed* to be caught by the first CI run once a remote exists; a Flutter regression today
is caught only if a human remembers to run the suite before shipping.

**What CI should do, once a remote exists** (this is aspirational — nothing below runs today):

1. A second job in `ci.yml` (or a separate workflow), triggered the same way as the backend job, scoped to
   `working-directory: mobile`.
2. `flutter pub get`, then `flutter analyze` as a gate — this suite is analyze-clean today
   (`flutter analyze`: no issues, confirmed this session) and should stay a merge blocker the same way
   Pint is for the backend (§6).
3. `flutter test` as a gate, same as the backend's "any test fails" rule.
4. **No emulator/device runner in this first pass.** All 127 tests today are Unit/Widget tier (§9.1) and
   run under the standard `flutter test` host binding — no Android emulator, no `integration_test`
   runner, no self-hosted device farm. Adding one is future work gated on Phase 32 actually producing
   device-dependent code worth running in CI; standing up emulator infrastructure to run zero
   device-dependent tests would be effort spent proving nothing.
5. Coverage reporting, unenforced at first, matching the backend's own stance in §6 — the same argument
   applies: a coverage floor nobody has signed off on teaches people to disable the check.

This is written as a plan, not a workflow file, because writing the YAML now — before a remote exists to
run it, and in a project whose own CI has literally never executed once — would be exactly the kind of
aspirational artifact TESTING.md's own history warns against (see the corrected §3.1 audit and the
Status line's own history above). When Phase 28/29's web deploy work stands up the remote, this plan is
what the mobile job should implement.

### 9.6 Relationship to the Web CRM's Dusk/E2E work

`backend/tests/Browser/` already exists (Components, Pages, an `ExampleTest.php`) — Laravel Dusk
scaffolding for the Web CRM, tracked separately in TODO.md as T-42 (E2E tool choice) and TESTING §8
("Browser/E2E testing for the Web CRM (Dusk?)"). That work is **explicitly out of scope for this
phase** — Phase 34 is the Flutter client's tracker entry (NFR-09), not the Web CRM's, and this pass did
not read, run, or modify anything under `backend/`.

The two suites answer different questions and should stay separate rather than be unified into one
"E2E" effort:

- **Dusk** drives a real browser against the real Laravel app — its value is proving the Blade
  shells + jQuery/AJAX actually work end-to-end in a DOM, which nothing in `backend/tests/Feature`
  can see.
- **This suite** never touches a browser or a real server — `ScriptedApi` stands in for the backend
  entirely, so what it proves is the Flutter client's own contract handling: does it decode what the API
  documents, does it show what the server says, does it fail closed the way ADR-B requires.

Where the two suites *should* eventually agree, and currently can only agree by both citing the same
source rather than by any shared test: the response envelope shape (`API_DOCUMENTATION` §2, asserted by
`Feature\Api\ResponseEnvelopeTest` on one side and `core/api/api_envelope_test.dart` on the other), and
the wording of server-authored refusal messages this app shows verbatim (NFR-05's "Web and Android
refuse in the same words") — a wording change on the backend that Dusk would not catch (it drives the
Web CRM's own Blade view, not the API envelope) could still silently break the Flutter widget tests that
assert on those exact sentences, which is in fact why several of this suite's tests assert on the
literal server sentence rather than a substring. There is no contract test today that pins the sentence
itself against both consumers at once; recording that as a known limitation is more honest than
implying either suite currently guards it.

## 10. Web CRM Browser/E2E Testing (Phase 28, T-42)

Phase 8 and its follow-ups built twelve-plus Blade screens as thin shells over `/api/v1/*`, and every
one of them was tested at the HTTP-response level - session auth, permission-gated page access, `assertSee`/
`assertDontSee` on the rendered HTML (`tests/Feature/Web/*`, TESTING §7). What that suite structurally
cannot see is what those pages are actually *for*: real JavaScript executing in a real DOM, an AJAX
round trip landing and repainting something, a Bootstrap modal opening and closing, a client-side branch
that only runs after a fetch this suite never made. That gap is what T-42 asked for and what this phase
closes with Laravel Dusk, in a new `tests/Browser/` directory that touches nothing under `tests/Feature`.

### 10.1 Does it actually run on this machine

Yes, confirmed with a real command and real output, not assumed — **but `vendor/bin/phpunit -c phpunit.dusk.xml` alone is not sufficient**, and running it without the prerequisite below produces `ERR_CONNECTION_REFUSED` or (worse, if a stale server from an unrelated earlier command is still bound to the port) confusing failures against the wrong environment entirely. This was found the hard way on a later verification pass and is recorded here so it is not rediscovered:

**Prerequisite**: a `php artisan serve` process must already be running, serving the *Dusk* environment specifically — `phpunit.dusk.xml`'s own header comment explains why: the `<env>` entries in that file only cover the PHPUnit process itself, while the browser talks to a completely separate OS process that reads `.env` directly. `php artisan dusk` (the Artisan wrapper) does **not** start that server for you — confirmed by running it directly and getting the same connection-refused failure. The working sequence is:

```bash
cp .env .env.backup        # save whatever is there
cp .env.dusk.local .env    # the served app must read the Dusk env, not the real one
php artisan serve --host=127.0.0.1 --port=8000 &   # leave running in the background
vendor/bin/phpunit -c phpunit.dusk.xml
cp .env.backup .env        # restore before doing anything else in this checkout
```

Also check for a stale `php artisan serve` already squatting on port 8000 from an earlier, unrelated command (`netstat -ano | grep :8000`) before starting a new one — two processes bound to the same port is indistinguishable from the app being broken (garbled selectors, wrong redirects) rather than the actual cause (requests landing on whichever process the OS happened to route them to).

With that prerequisite met:

```
$ vendor/bin/phpunit -c phpunit.dusk.xml
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
............                                                      12 / 12 (100%)
Time: 00:52.428, Memory: 52.00 MB
OK (12 tests, 49 assertions)
```

Chrome 152.0.7977.76 is installed on this machine (`Google\Chrome\Application\chrome.exe`), and
`php artisan dusk:install` auto-matched and downloaded ChromeDriver 152.0.7977.82 for it - no manual
version pinning was needed. Getting there took an actual iteration loop, worth recording because the
failures were real and not staged: a `meta[name="csrf-token"]` lookup failed because Dusk's plain-CSS
`attribute()` scopes selectors under `<body>` (the tag lives in `<head>`, so it needed `script()`
instead); a status-history assertion raced `loadTransitions()` and `loadHistory()`, which fire in
parallel from the same `.done()` handler rather than in sequence, so waiting on the badge alone did not
guarantee the history call had landed too; and a permission-gated visibility assertion tripped on the
DNC screen's own "Removed only" filter option, which legitimately contains the substring "Remove" and
has nothing to do with the `dnc.remove` permission the test was actually checking - fixed by scoping the
assertion to `#dnc-rows` rather than the whole page. All three are the ordinary cost of writing
JavaScript-aware tests against JavaScript that was not written with tests in mind, not evidence against
the approach.

**What this does NOT yet prove**: that this same run succeeds in CI. There is no git remote yet (§9.5
records the same caveat for the Flutter side), so `.github/workflows/ci.yml` has never executed once,
Dusk step or no Dusk step. A CI runner additionally needs a Chrome/ChromeDriver pair actually present
(GitHub's `ubuntu-latest` image ships one; a self-hosted runner would not without installing it) and a
`--headless=new` Chrome to run without a display, which `tests/DuskTestCase.php`'s generated
`driver()` already requests by default. Nothing about the tests themselves is headless-unfriendly - this
run above **was** headless, on this same Windows machine, with no virtual display server needed - but
"runs headless locally" and "is wired into a CI job that runs on every push" are different claims, and
only the first one is true today. Wiring the second is scoped separately (see 10.5).

### 10.2 Why a separate, fourth database

`.env.dusk.local` (gitignored, matched by the existing `.env.*` rule) points Dusk at
`marketing_crm_dusk` - not `marketing_crm`, not `marketing_crm_test`, and not any of the parallel
suite's `_test_a`..`_test_d` databases. Two reasons, recorded in the file's own header comment because
they are easy to reverse by a well-meaning future edit:

1. **Process boundary.** Dusk's browser talks to a real `php artisan serve` process; the PHPUnit
   process asserting against the database is a *different* process. `RefreshDatabase`'s transaction-
   per-test trick (what the Feature suite uses) is invisible across that boundary - a transaction the
   PHPUnit process opens is never visible to queries the served app makes answering the browser's
   requests. `tests/DuskTestCase.php` uses `Illuminate\Foundation\Testing\DatabaseTruncation` instead,
   which issues real, committed statements both processes agree on - at the cost of one `migrate:fresh`
   per run instead of per test.
2. **Pool isolation.** The `_test_a`..`_test_d` suffixes are Laravel's parallel-testing databases,
   claimed and released per worker process for the Feature/Unit run (itself running as `root` per T-48).
   Dusk has its own lifecycle, invoked as its own command - reusing one of those names would either
   collide with a live parallel worker or silently assume the two suites never run at the same time.

`phpunit.dusk.xml`'s own `<env>` block mirrors `.env.dusk.local`'s database settings rather than
overriding them, and says why in a comment: the two files are read by two different processes (the
PHPUnit runner and the served app), and if they ever disagreed on `DB_DATABASE` the assertions in one
process would be checking data the browser in the other process never touched.

### 10.3 What was covered, and why these five journeys

Not "what was easiest" - what genuinely needs a real DOM and a real AJAX round trip, the same test this
task was set against:

| Journey | File | Why Dusk, not Feature |
|---|---|---|
| Session login → dashboard, incl. the CSRF meta tag | `AuthenticationTest.php` | `Feature\Web\WebCrmTest` already proves the server side (work session, audit row, disabled-account refusal). It cannot prove a real browser's `$.ajaxSetup` actually reads the `<meta name="csrf-token">` tag `layouts/app.blade.php` renders and attaches it to a request - that plumbing only exists once JavaScript runs |
| Create a lead via `leads/form.blade.php`, confirm it via the list's own AJAX read | `LeadCreationTest.php` | Two separate Blade shells over the same API, written by one page and read back by a completely different one moments later. A Feature test can hit `POST` and `GET /api/v1/leads` directly and prove the API works; it cannot prove the FORM produces that payload or that the LIST's own fetch picks up what a different screen just wrote |
| Change a lead's status; badge, history and timeline all catch up live | `LeadStatusTransitionTest.php` | `#apply-status`'s success handler fires `loadTransitions()`, `loadHistory()` and `loadTimeline()` - three more AJAX calls, in parallel, against DOM that has to still exist when each response lands. `Feature\Leads\LeadStatusTest` owns the transition rule itself; this owns the orchestration a Feature test cannot execute at all |
| A destructive action behind a Bootstrap modal (DNC removal) - validation failure then success | `DncRemovalModalTest.php` | The modal's `#confirm-remove` handler hand-maps a 422's `reason` field error onto `.is-invalid`/`.invalid-feedback` on an already-open modal, then closes it and re-fetches the list on success. A Feature test sees the 422 body; it cannot see whether the modal painted it, stayed open, or closed and refreshed correctly afterward |
| A permission-gated control genuinely absent from the rendered DOM | `PermissionGatedDncControlTest.php` | `dnc/index.blade.php` never puts a "Remove" button in server-rendered markup at all - every row is drawn by the page's own `statusCell()` function, after `GET /api/v1/dnc` resolves, branching on a `canRemove` flag. A Feature test that never executes that script cannot distinguish "the button is genuinely absent" from "the button would appear once an AJAX call this test never made had finished" |

Twelve test methods across these five files plus the infrastructure smoke test (`ExampleTest.php`,
kept as a fast "is the environment even wired up" check rather than deleted). None of them duplicate
what `tests/Feature/Web` already owns - the transition matrix, the permission matrix, the duplicate-
detection rule are all still asserted once, at the API/Feature level, exactly per this document's
existing convention (§2: "avoid one heavyweight end-to-end test standing in for missing rule tests").

### 10.4 What was NOT attempted, and why

**No journey needed a blocker workaround.** Chrome and a matching ChromeDriver were both available on
this machine (10.1), so unlike Phase 34's honest "cannot test a physical device" boundary, nothing here
hit a hardware or environment wall. The scope was narrowed by *value*, not by what would run: dialer/
auto-dialer screens, campaign builder, reports/Chart.js dashboards and the settings screen were all
candidates and are all real Blade+AJAX shells, but none of them exercises anything the five journeys
above do not already exercise structurally (an AJAX round trip, a modal, a client-side permission branch,
multi-call orchestration after a write). Adding more screens would grow the test count without covering
a new kind of gap - exactly the "easiest, not most valuable" trap this task named directly.

**Load testing (T-43)** is explicitly a different phase concern (FR-RPT-05, campaign throughput and
dashboard response targets) and untouched here - Dusk answers "does the browser path work," not "how
many of them at once." *(Answered separately, same day: see [LOAD_TEST_RESULTS.md](LOAD_TEST_RESULTS.md)
and TODO.md's T-43 row - a dedicated Artisan-command harness, not Dusk, since this needed queue
throughput and response-time numbers rather than a browser.)*

### 10.5 Honest state of Phase 28

**Not Done**, and here is exactly what is and is not true:

- ✅ Dusk is installed (`composer.json`), scaffolded (`tests/DuskTestCase.php`), and runs a real,
  passing 12-test suite against a real Chrome browser on this machine, verified with the command output
  in §10.1 - not assumed, not simulated.
  Journeys: session login (incl. the CSRF meta tag), lead creation confirmed via a separate screen's
  AJAX read, a live multi-call status-transition update, a destructive Bootstrap modal's validation and
  success paths, and a permission-gated control's genuine absence from a real DOM.
- ✅ A dedicated `marketing_crm_dusk` database, isolated from both the dev database and every database
  the parallel Feature/Unit run touches, with the isolation choice documented in the env file itself
  (§10.2).
- ❌ **Not wired into CI.** `.github/workflows/ci.yml` has no Dusk (or Chrome/ChromeDriver installation)
  step, and - as §9.5 records for the Flutter side - there is no git remote yet for any workflow to have
  run against even once. Adding the step is close to mechanical (GitHub's `ubuntu-latest` runners ship
  Chrome and a matching driver; the job would need `php artisan serve` started in the background,
  `marketing_crm_dusk` migrated, and `--headless=new` confirmed, which `DuskTestCase` already requests
  by default) but it is unverified until a remote exists to prove it, the same standard this document
  holds every other "aspirational" CI claim to.
- ✅ **T-43 (load-test targets) is now answered** - a distinct concern from browser E2E tooling, never
  something Dusk was going to answer. See [LOAD_TEST_RESULTS.md](LOAD_TEST_RESULTS.md) and TODO.md's
  T-43 row: the <2s dashboard budget holds comfortably, and campaign fan-out meets FR-CAMP-05's literal
  no-timeout requirement but drains a 50,000-lead audience in roughly 30 minutes (measured and
  extrapolated, not guessed) on the current `database` queue driver - a real number for T-30's still-open
  VPS/Redis question, not a resolution of it.

MODULE_STATUS's Phase 28 row is updated to reflect this precisely: browser E2E tooling is chosen, built
and passing locally, and the load-test question now has a real answer; CI wiring is what keeps the phase
from Done.
