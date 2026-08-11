# Testing

| | |
|---|---|
| **Version** | 1.1 |
| **Last updated** | 2026-08-10 (Phase 1) |
| **Status** | Strategy defined; **no test suite exists** — scaffolding lands in Phase 3 |
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
| **BR-** (BUSINESS_RULES.md) | 66 | 62 | 57 |
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

## 4. Critical Rule Coverage (mandatory)

These are the rules where a silent regression is a business or compliance problem. Each needs explicit, named tests — not incidental coverage (NFR-10).

### 4.1 DNC / Suppression — the highest-priority suite

| Test | Proves |
|------|--------|
| Suppressed lead excluded — one test **per channel**: Email, WhatsApp, SMS, RCS, Voice, AI Calling, human call, auto dialer, scheduled campaign | FR-DNC-01 — ✅ **all but scheduled campaign**, which needs Phase 18 (T-61). See `Feature\Dnc\DncChannelMatrixTest` |
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
| **Everything to date** | **2026-08-12** | **665 (2239 assertions)** | **665** | **0** | — | ✅ **Verified.** +326 over the Phase 10 baseline of 339. ⚠️ Still on MariaDB 10.4, not the pinned MySQL 8.4.9 - the CI workflow is what will settle that (T-06, T-48). ⚠️ Still on MariaDB 10.4, not the pinned MySQL 8.4.9 - the CI workflow is what will settle that (T-06, T-48). ⚠️ Still on MariaDB 10.4, not the pinned MySQL 8.4.9 - the CI workflow is what will settle that (T-06, T-48), run once T-48 was worked around. Broken down in the rows below. **Caveat: run on MariaDB 10.4.32, not the pinned MySQL 8.4.9** — good evidence, not proof against the production engine (T-48) |
| ↳ Settings module | 2026-08-11 | 22 | 22 | 0 | — | +18 API tests in `Feature\Settings\SettingsApiTest`: Admin sees operational groups but no credential group, Super Admin sees both, Manager refused outright, values fall back to config defaults, a stored secret is never returned, a short secret is not half-disclosed by its own hint, secrets **and** non-secrets are encrypted at rest, an Admin cannot set a credential, a mixed payload is refused whole rather than partly applied, unknown keys and wrong types refused, overrides win and are correctly typed, clearing restores the config default, repeat saves update rather than duplicate, and both audit actions fire without recording the value. +4 page tests. **Caught a real bug**: permission was derived from `type === 'secret'`, so an Admin could change provider *endpoints*, `key_id` and the gateway selector — none secret, all credential configuration |
| ↳ 19 (DNC admin) | 2026-08-11 | 18 | 18 | 0 | — | +14 API tests in `Feature\Dnc\DncApiTest`: listing and lead-scoped visibility, orphan entries visible only at All scope, blocked channels resolved from the reason, manual suppression and its idempotency, channel-specific opt-out, telecaller refused removal **on their own lead**, removal deactivating rather than deleting, mandatory reason, double-removal refused, flag recomputed when one of two suppressions is lifted, flag cleared on the last one, and the audit entry. +4 page tests for permission-gated rendering of the removal control |
| ↳ 8 (account page) | 2026-08-11 | 3 | 3 | 0 | — | All six roles can reach their own account page, the profile is shown without offering to edit it, and the header links to it. The change-password endpoint itself was already covered by `Feature\Auth\AuthenticationTest` since Phase 4 — this row is about reachability, not the rule |
| ↳ 8 (assignment UI) | 2026-08-11 | 6 | 6 | 0 | — | +5 page tests: the pool opens for a manager, a telecaller is refused it, navigation hides it from non-assigners, the assignment card appears on a lead only for assigners, and the pool is in the session-required set. +1 API test for the **`null` filter operator**, which was untested and which the whole pool page depends on |
| ↳ 8 (lead form) | 2026-08-11 | 10 | 10 | 0 | — | +7 page tests: create-form permission gating, the `/leads/create` route-ordering regression, edit-form prefill, the IDOR check on the edit URL, read-only roles refused on both, edit not offering create-only fields, and the detail page linking to edit only for editors. +3 API tests for **two bugs the form exposed**: `PATCH /leads/{id}` never translated `alt_phone` to the `alt_phone_e164` column (a 500 in dev, a silent discard in production), and `name` was `sometimes\|string`, so an empty string blanked the lead's name. **The feature suite could not be run**: the server on :3306 is MariaDB 10.4.32 rather than the pinned MySQL 8.4.9, and `crm_user`, `marketing_crm` and `marketing_crm_test` are all absent. Views compile, routes register, and the 62 DB-free unit tests pass — re-run this row's tests once the database is restored |
| 7 | 2026-08-10 | 273 (993 assertions) | 273 | 0 | — | +42 status and product-interest tests. **Caught a real bug**: product-interest propagation called the status service with a null actor, and the reopen guard skipped authority checks for system callers — so recording interest against a `Lost` lead silently reopened it, with no manager and no reason. Fixed in both places |

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

Browser/E2E testing for the Web CRM (Dusk?) · load testing targets for campaign throughput and dashboard response (FR-RPT-05) · Flutter test strategy (Phase 34) · whether to run mutation testing on the DNC service. Tracked in [TODO.md](TODO.md).
