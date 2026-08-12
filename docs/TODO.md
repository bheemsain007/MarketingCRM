# TODO

| | |
|---|---|
| **Last updated** | 2026-08-11 (UI pass, DNC slice, settings, Phases 12/13/15/20/21/22/23/27, T-46 + T-60 closed, DNC matrix, lead-page tabs, CI, config contract, static analysis, model annotations, dashboard, README, rule-coverage audit, T-54, T-52, cache leak, T-65, Phase 18) |
| **Current position** | Phase 8 UI pass complete, Phase 19 admin surface built early. **Suite: 685 passing, 0 failing** — T-48 diagnosed and worked around. **Phase 11 is still genuinely blocked**: it needs T-44 answered and ADR-B confirmed |
| **Open items** | 49 of 55 |

Every task has a **stable ID (`T-nn`)**. IDs are never reused or renumbered — a completed task keeps its number and moves to §1, so "T-14 is done" means the same thing in six months. Reference them in commits and phase reports.

Items are grouped by **when an answer is actually needed**, not by topic. Only T-15 blocks the next phase.

---

## 1. Resolved

| ID | Task | Resolution |
|----|------|------------|
| **T-01** | ADR-C — `tenant_id` on all tables | ✅ Phase 2 — implemented but **amended**: `NOT NULL DEFAULT 0`, not nullable. A nullable column silently disabled every composite unique index (`NULL != NULL`), which would have allowed duplicate leads in production |
| **T-02** | Stack versions | ✅ Phase 2 — PHP 8.2.30, Laravel 12.65, MySQL 8.4.9, Composer 2.10.2. Pinned and verified |
| **T-03** | Hosting target | ✅ Phase 2 — Hostinger shared hosting. Constraints in [DEPLOYMENT §3A](DEPLOYMENT.md#3a-shared-hosting-mode-hostinger--active-target-) |
| **T-04** | API rate limits | ✅ Phase 3 — implemented with the proposed values (5/120/10/600 per min), tunable in `config/crm.php`. Review if they prove wrong in practice |
| **T-15** | BR-STAT-02 — transition matrix + reopen authority | ✅ **Signed off 2026-08-10**, enforced in Phase 7. All five judgment calls confirmed as built: Converted terminal; conversion only from Proposal/Negotiation/Decision Pending; reopens Manager+ with a reason; reopening does **not** clear suppression; `Not Interested` suppresses but `Lost` does not. **BR-STAT-04 was amended** in the same phase — it contradicted BR-STAT-02/05 as written; see BUSINESS_RULES |

---

## 2. Still open from Phase 3

| ID | Task | Notes |
|----|------|-------|
| **T-05** | OpenAPI spec generation | **Deferred, not rejected.** Contract tests already prevent envelope drift; OpenAPI would additionally give clients a generated schema. Revisit when the Flutter app starts (Phase 30) or if a third party needs the API |
| **T-06** | CI pipeline + coverage floor — **workflow written, waiting on a remote** | `.github/workflows/ci.yml` is in place and runs `composer audit`, Pint, the migration chain up/down/up, then the suite - pinned to **MySQL 8.4 and PHP 8.2** per T-02. Nothing runs until a remote exists, but the first push will. **Two things still need you**: a git remote, and the coverage floor (proposed 90% on `app/Services/**`, 70% overall) - coverage is currently reported and not enforced, because failing a build on a number nobody signed off teaches people to disable the check |
| ~~**T-07**~~ | ~~ADR-A — Web CRM delivery model~~ | ✅ **Confirmed 2026-08-10** and built at Phase 8: one Laravel app, Blade shells + jQuery/AJAX to `/api/v1`, session auth for the browser and tokens for Flutter. See ARCHITECTURE ADR-A "As built" |

## 3. Needed for Phase 4 (Auth + Roles)

| ID | Task | Notes |
|----|------|-------|
| **T-08** | Role model **review** (no longer blocking) | ✅ Implemented in Phase 4 with the 6 proposed roles and 41 permissions. Seeded data, so changes need no migration. **Worth a review** — see the per-role permission map in `App\Enums\Permission::defaultsFor()`. Key calls I made: telecallers cannot export or un-suppress; Accounts is read-only on leads; Admin cannot manage credentials |
| **T-09** | 2FA for Admin/Super Admin | SEC-AUTH-07 — deferred, recommended before production (Phase 29) |
| **T-10** | Token lifetime for the mobile app | 30 days. ⚠️ **It was not actually configurable until 2026-08-11**: `AuthService` read `env('TOKEN_EXPIRY_DAYS')` directly, which returns the default once `config:cache` runs, so any configured value silently reverted to 30 in production. Now `config('crm.auth.token_expiry_days')` and genuinely tunable. **Confirm the 30 days** |
| **T-11** | Idle threshold for active-time | Config is in place (5 min). Used when active/idle rollup lands with reporting |
| **T-12** | Hostinger plan tier | SSH + Composer need Business tier or above. Verify what the current plan provides |

## 4. Business rules needing sign-off

| ID | Task | Blocks | Proposed default |
|----|------|--------|------------------|
| **T-13** | BR-ASSIGN-01/02 — auto-assignment method + open-lead cap (**review**, no longer blocking) | Phase 6 — shipped | Implemented with the proposed defaults: load-balanced, 150 open leads. Both are config (`crm.assignment`), so changing them is not a deployment |
| ~~**T-14**~~ | ~~BR-ASSIGN-04 — do open follow-ups transfer on reassignment?~~ | ~~Phase 21~~ | ✅ **Built 2026-08-11 as "yes, transfer".** Open and missed follow-ups move to the new owner and the new owner is notified; completed history stays put, because it records who actually did the work. Reversible in one service method if you disagree |
| ~~**T-15**~~ | ~~BR-STAT-02 — lead status transition matrix~~ | ~~Phase 7~~ | ✅ **Resolved** — see §1 |
| **T-16** | BR-SCORE-01 / BR-TEMP-02 — score model and temperature bands | Phase 20 — shipped | Built to the proposed table. Weights live in `config('crm.scoring')`, and the score is **derived from `interest_signals`** rather than accumulated, so re-weighting is a config change plus `crm:decay-lead-scores` rather than a guess at history. **Worth confirming**: the 13 signal weights, the -10 cap on repeated no-answers, -5 per 7 days of decay, and the Hot/Warm/Cold/Dormant bands |
| **T-17** | BR-CALL-04 — calling hours window | Phase 9 | 09:00–20:00 lead-local |
| **T-18** | BR-DNC-02 — reason × channel suppression matrix | Phase 19 | Implemented in `DncReason`. Key question: should *Not Interested* block manual human calls? Currently yes |
| **T-19** | BR-CAMP-04 — frequency caps | Phase 18 | 2/day, 5/week per channel |
| **T-20** | BR-NOTIF-02/04 — notification triggers + reminder lead time | Phase 21 — shipped | Built at 15 min (`crm.follow_up.reminder_lead_minutes`), one reminder per follow-up. Triggers built so far: follow-up due, follow-up assigned/transferred, lead assigned. The rest of BR-NOTIF-02's list (campaign completed, payment overdue, upload failure, approval requests) lands with the phases that create those events. **Confirm the 15 minutes** |
| **T-21** | BR-SALE-03 — discount approval threshold | Phase 22 — shipped | Built at 15% (`crm.discount_approval_threshold`), so changing it is config not a deploy. Above the threshold a quotation cannot be issued without Manager+ approval, and **the approver may not be the person who raised it**. **Confirm the 15%** |
| **T-22** | BR-INT-04 — AI interest confidence threshold | Phase 24/25 | 0.75 |
| ~~**T-23**~~ | ~~BR-CUST-01 — Lead and Customer as separate linked records~~ | ~~Phase 22~~ | ✅ **Built 2026-08-11 as specified.** The lead survives conversion; the customer links back through `origin_lead_id` and is deduplicated on phone then email (BR-CUST-04) |
| **T-47** | Should a manually created lead auto-assign to its creator? | Nothing — **de-escalated** | **No** (current behaviour), and the third option is now built: the unassigned pool has a manager's inbox, so a lead created by a telecaller is visible to someone and does not vanish. That matches how imports already behave. The question is now about convenience rather than correctness: a telecaller who creates a lead still cannot work it until a manager assigns it. Remaining options if that proves annoying — self-assign on manual creation, or run auto-assignment (BR-ASSIGN-01) on it |

## 5. Reporting definitions — before Phase 26

| ID | Task | Notes |
|----|------|-------|
| **T-24** | ⚠️ **Attribution model** | On reassignment, who gets conversion + revenue credit? Last owner (proposed) / first interest creator / split. **This affects telecaller pay** ([GLOSSARY §2.6](GLOSSARY.md#26--attribution--needs-a-business-decision-)) |
| **T-25** | Campaign vs. telecaller credit | Proposed: report both separately — marketing ROI vs. incentives |
| ~~**T-60**~~ | ~~Chart.js report dashboards (FR-RPT-03)~~ | ✅ **Built 2026-08-11** at `/reports`: eight tiles, a lead funnel, a money chart, loss reasons, source performance and revenue by product. Every rate is drawn with its denominator (FR-RPT-06) and a zero denominator shows an em dash. **T-27 is still worth doing** - the charts render whatever the API returns, so confirming the formulas changes the numbers, not the screen |
| **T-26** | Headline conversion rate basis | Cohort-based (proposed) vs. simple period ratio |
| **T-27** | Review metric formulas — **now built, so worth reviewing** | [GLOSSARY Part 2](GLOSSARY.md#part-2--metric-definitions) — especially Talk Time, Average Call Duration (÷ *connected* calls), and Revenue collected vs. Booked Value |

## 6. Architecture — confirm before the phase lands

| ID | Task | Notes |
|----|------|-------|
| **T-28** | 🔴 **ADR-B — calling architecture** | *Highest-impact open item.* Android dials natively and records locally; Web CRM orchestrates and tracks. **Narrowed at Phase 9:** the server side — call records, the DNC and calling-hours gates, outcomes and history — is built and is unaffected by how ADR-B lands, since every dialling mechanism needs it. What still depends on the answer is Phases 10, 11, 31, 32 (auto dialer, recording, the Android client). **Answer T-44 first** — the whole decision rests on device recording actually working |
| **T-29** | DNC sequencing — **partly acted on** | A minimal `DncService` (`suppress`, `canContact`, flag-sync) was built at Phase 7 because `Not Interested` must write suppression (BR-DNC-07) and the alternative — writing `dnc_entries` from `LeadStatusService` — is the per-module suppression ADR-E forbids. **The same argument applied again on 2026-08-11** to removal: suppression was being written with nothing able to read it back or lift it, which is a compliance problem rather than a missing feature — so `DncService::remove()`, three endpoints and the admin screen are now built too. **Phase 19 still owns:** policy configuration (BR-DNC-04 — the reason × channel matrix currently lives in the `DncReason` enum, i.e. code, not data), skip reporting (BR-DNC-05), the inbound-keyword source, and the full per-channel matrix. **Nothing further needed from you** unless you want that scope moved earlier too |
| **T-30** | VPS migration budget | Bulk campaign throughput on shared hosting is the known bottleneck — decide before Phase 18 |

## 7. Vendors & credentials

| ID | Task | Blocks |
|----|------|--------|
| ~~**T-35**~~ *(partly)* | Mailercloud + BhashSMS are now **built** against conventional wire formats and need only keys; their real API contracts are T-53. Meta, Vaaad and the unnamed vendors are still outstanding | Phases 12, 14, 16, 17, 24 |
| **T-31** | WhatsApp BSP — Meta direct, or Gupshup / Interakt / other? | Phase 14 |
| **T-32** | RCS provider — not named | Phase 16 |
| **T-33** | Voice SMS / Voice provider — not named | Phase 17 |
| **T-34** | Payment gateway — Razorpay / PayU / Stripe / other? | Phase 23 |
| **T-35** | Credentials + API docs: Mailercloud, BhashSMS, Vaaad, Meta app | Phases 12–17, 24 |
| **T-36** | ⏳ **Start WhatsApp Business verification now** | Meta business verification + template approval takes weeks and can be rejected. Begin well before Phase 14 or the channel will not be ready when the code is |

## 8. Data protection — before Phase 29

| ID | Task | Notes |
|----|------|-------|
| **T-37** | Retention periods per data class | Call recordings, AI transcripts, webhook payloads, application logs (SEC-PII-05) |
| **T-38** | Encryption-at-rest mechanism | Disk-level or application-level, for recordings/transcripts (SEC-PII-02) |
| **T-63** | Burn down the PHPStan baseline — **280 → 97 on 2026-08-11** | The big win is taken: model `@property` and `@property-read` annotations, generated from `information_schema` and from reflecting the relation methods rather than hand-written, so they cannot drift. **Nothing dangerous was hiding behind the noise** - the remaining 97 are `?->` on non-nullable values and narrow array-shape inference. Next steps, in order of value: annotate the resource/service locals the analyser cannot infer, then raise the level one at a time. Low priority |
| **T-62** | Confirm `.env.example` defaults `APP_DEBUG=false` | Changed 2026-08-11, against Laravel's shipped example. The deployment path is copying this file on the server (DEPLOYMENT §3A), and a debug page leaks credentials in a stack trace (SEC-CFG-03). Cost: a developer must set `APP_DEBUG=true` in their own `.env`. **Say if you would rather have the convenience** - it is one line either way, and the risk is asymmetric |
| **T-39** | CSP strictness | Given inline jQuery/Bootstrap usage (SEC-OPS-02) |
| **T-50** | What authority should `DncReason::requiresElevatedRemoval()` demand? | The enum has flagged `Do Not Contact` and `Opted Out` as "never undone casually" since Phase 2, and nothing has ever enforced it — the method was tested but unused. BR-DNC-06 only says removal is Manager+, which `dnc.remove` already satisfies, so there is no rule saying what *stricter* means. **Built as: the API reports the flag and the DNC screen shows a warning before lifting one; authority is not raised.** Decide whether it should be a distinct permission (the codebase's idiom, as with `leads.reopen`), Admin-only, or dropped from the enum as an idea that did not survive |
| **T-49** | `POST /auth/change-password` sits on the 120/min limiter | Noticed while building the account screen. It verifies `current_password`, which makes it a credential oracle, but it carries `throttle:api-standard` (120/min) rather than `throttle:api-auth` (5/min) like the other credential endpoint. The attacker model is narrow — they need a live session or token first — so the prize is lockout rather than entry. **Not changed unilaterally**: `api-auth` keys partly on `input('email')`, which this endpoint does not send, so it would fall back to the IP limit alone and share a bucket with login attempts. Wants a deliberate decision, probably a third limiter (SEC-AUTH-03) |

## 9. Deferred / revisit later

| ID | Task | Revisit by |
|----|------|-----------|
| **T-40** | Lead custom fields — JSON column vs. EAV | Not requested; defer until asked |
| **T-41** | Partitioning/archival for `messages` and `calls` at volume | Phase 29 |
| **T-42** | Browser/E2E testing tool for Web CRM (Dusk?) | Phase 28 |
| **T-43** | Load-test targets for campaign throughput and dashboard response | Phase 28 (FR-RPT-05) |
| **T-44** | 🔴 Android call-recording feasibility test on real devices | ⚠️ **Now blocking.** Android 10+ blocks third-party call recording on most modern phones, and both ADR-B and Phase 11 rest on it working. Phases 9 and 10 were built around the question; Phase 11 cannot be. Needs a physical handset — put a test build on two or three real devices and confirm whether the audio is actually capturable |
| ~~**T-46**~~ | ~~Web CRM screens not yet built~~ | ✅ **Closed 2026-08-11.** All twelve screens exist: shell, dashboard, leads list/detail, lead create/edit, assignments, account, DNC, settings, users, dialer, imports, products. The only UI still outstanding is the Chart.js report dashboards, tracked separately as T-60 |
| **T-55** | 🔒 BhashSMS puts credentials in a URL query string | Their documented API is plain HTTP with `user` and `pass` as query parameters. **Mitigated, not solved**: the driver forces HTTPS, so they are no longer in the clear on the wire, but a query string still lands in the provider's own access logs and any intermediary that terminates TLS. Ask BhashSMS whether they support POST-body or header auth. If not, treat that account's password as low-trust and rotate it on a schedule (SEC-CFG-04) |
| **T-58** | A payment must name one product | BR-PAY-03 requires lead + customer + **product** + sale on every payment, taken literally so revenue-by-product (FR-PAY-04) is exact rather than apportioned. Cost: an instalment against a multi-product sale has to be split, or the payer has to say which product a single transfer covers. A single-product sale is filled in automatically, so most sales are unaffected. **Confirm this is what you want**, or relax it to a nullable product with apportioned reporting |
| **T-59** | Payment links + gateway collection (FR-PAY-02) | Not built - needs T-34 (gateway named). The schema (`gateway`, `gateway_payment_id`, unique pair) and the `gateway` payment method are in place, so this lands as a driver plus a webhook, the same shape as the messaging channels |
| **T-56** | No separate `lost_sales` table | DATABASE_SCHEMA plans one. Built instead as `status = 'lost'` plus `lost_reason` / `lost_notes` / `closed_at` on `opportunities` - a lost deal is the same row in a different state, not a different entity, and grouping by `lost_reason` answers FR-SALE-05 directly. **Deliberate deviation**; confirm or ask for the separate table |
| ~~**T-57**~~ | ~~`Converted` does not yet require a payment~~ | ✅ **Closed 2026-08-11 by Phase 23.** `Converted` now requires a sale AND a Partial/Paid payment against it, as BR-PAY-05 always asked |
| **T-53** | Mailercloud API + webhook signing contract | Built against the conventional shapes because the vendor documentation has not been supplied (T-35). The transactional send body and the webhook signature scheme both need confirming against the real contract before this is pointed at production. **Everything around them — the DNC gate, the queue, idempotency, status transitions — is independent of the answer**, so this is a driver-class change, not a rework |
| ~~**T-54**~~ | ~~Bounce → automatic suppression~~ ✅ **Built 2026-08-12.** Routed through `DncService` rather than writing `dnc_entries` in the controller, so ADR-E's single gate still holds and provider redeliveries cannot stack rows. Hard bounce → `bounced_email` (email only); unsubscribe/complaint → `opted_out` **scoped to email**, because that reason is otherwise absolute and would have silently stopped phone contact. An unqualified `bounce` does **not** suppress — see BR-DNC-07 for why the asymmetry runs that way. 5 tests. Original note: | A hard bounce is a BR-DNC-07 trigger (reason `BouncedEmail`), and the webhook currently records the bounce **without** writing suppression. Writing `dnc_entries` from a webhook controller is the per-module suppression ADR-E forbids, so it belongs with the other automatic triggers in Phase 19. Until then, a bounced address stays contactable — worth doing early if email volume ramps first |
| ~~**T-52**~~ | ~~SEC-CFG-01 needs amending to describe the override layer~~ ✅ **Amended 2026-08-12** (SECURITY §7A, plus a new SEC-CFG-06). Writing it up found **two real bugs**, both now fixed and tested: an undecryptable settings row threw out of the bulk override load and took every other key with it, and overwriting such a row threw as well — so the recovery action was blocked by the corruption it was meant to clear. 4 tests. **One question still yours:** whether provider credentials sitting in database backups is acceptable at all. Original note: | As written it says provider credentials live in `.env` → `config/*` only. As built (2026-08-11), `config/*` is still the source of **defaults** and the `settings` table is an audited, encrypted **override** — which is what SEC-CFG-04 (rotation without code changes) and SEC-AUD-02 (audited credential changes) actually require, and what the Hostinger target needs since `.env` editing wants SSH (T-12). **Nothing is broken; the document is behind the code.** Confirm the wording, and confirm that DB-stored credentials are acceptable given they land in database backups |
| ~~**T-51**~~ | ~~User administration has no API~~ | ✅ **Built 2026-08-11.** Service, 7 endpoints and a screen. `users.manage` (Admin) creates and edits people; `roles.manage` (Super Admin only) assigns roles, and nobody may assign their own (SEC-AUTHZ-05). New accounts start role-less. Lockout guards: no self-disable, and the last active Super Admin cannot be disabled |
| **T-48** | 🟠 Corrupt MariaDB privilege table — **worked around, not fixed** | **Root cause found 2026-08-11, and it is not "the database is gone".** `mysql.db` — the Aria table holding *database-level* grants — is corrupt: `CHECK TABLE` returns `Page 0: Got error: 176 when reading datafile / Corrupt`. Every `GRANT ... ON <db>.* TO ...` writes there, so `crm_user` can be created but can never be granted anything: it ends up with `USAGE ON *.*` and is denied. `mysql.global_priv` is intact, which is why `root` still works — its privileges are global, not per-database. **Worked around**: both databases created, and a gitignored `backend/.env.testing` runs the suite as `root`. `php artisan test` → **376 passing, 0 failing**. **Two things still open:** (1) `REPAIR TABLE mysql.db` was **deliberately not run** — repairing a corrupt Aria page generally discards it, which would destroy the database-level grants of the ~9 unrelated databases on this server (`billing_saas`, `epaper`, `niviyo_billing`, `tenant1`, `tenant2`, `landlord_*`, `itnn_admin`). Back those grants up first, then decide. (2) This is still **MariaDB 10.4.32, not the pinned MySQL 8.4.9** (T-02) — the suite passing here is good evidence, not proof against the production engine |
| ~~**T-61**~~ | ~~Scheduled-campaign DNC test is still missing~~ ✅ **Closed 2026-08-12 by Phase 18.** The row TESTING §4.1 asked for now exists, and it tests the case that actually matters: a lead suppressed *after* the audience was built is skipped at dispatch with reason `suppressed`, and no message row is created. Original note: | TESTING section 4.1 asks for a suppression test per channel **including scheduled campaigns**. Every other row now has one - six message channels, human calling and the auto dialer. This one cannot be written until Phase 18 exists. **Do not close Phase 18 without it**: a campaign is the highest-volume outbound path in the system, so it is the one where a missed gate reaches the most people who said no |
| **T-64** | 📋 **Duplicate handling stops at phone — BR-DUP-03 and BR-DUP-04 are not built** | Found 2026-08-11 by auditing every `BR-*` ID against the code. BR-DUP-01/02 are solid: phone is normalised to E.164 and every entry path (manual, CSV, Meta webhook) refuses a second row for the same number. **BR-DUP-03** (same email + different phone → flag for review) and **BR-DUP-04** (merge preserves both timelines; suppression is union) have no implementation at all — there is no merge anywhere in the codebase, and `ImportRowStatus` has no "needs review" case. Neither is a bug: both need a **review surface** before they can exist, and that is a product decision. The risk of building BR-DUP-03 blind is real — two people at one company legitimately share an email domain, and often the address itself (`info@`), so auto-merging on it fuses distinct humans. **Proposed:** add `ImportRowStatus::NeedsReview` plus a duplicate-review queue, and treat merge as its own small phase. Until then `LeadService` says so in its docblock rather than implying all four rules hold |
| ~~**T-65**~~ | ~~BR-DNC-04 says the DNC matrix is configuration; it is code~~ ✅ **Decided and built 2026-08-12**, as the split proposed below. `Do Not Contact` and `Opted Out` stay absolute in code and are not offered by the settings API; the four inference-based reasons are settings-backed with `config/crm.php` as defaults. Malformed overrides fall back to the built-in list rather than the part that parsed, so suppression widens on error and never narrows. 8 tests, five of them negative. Original analysis: | The rule is explicit: "Changing whether *Not Interested* blocks manual human calls must not require a code change in any channel module." As built, the reason × channel grid is a `match` inside `DncReason::blocksChannels()` — changing it is an edit and a deploy. **This is a genuine spec-vs-code divergence, and I do not think it should be closed by simply moving the grid into config.** Suppression is the compliance-critical rule in this system: a runtime-editable matrix means one bad settings write silently re-enables contacting people who opted out, with no code review in the path. **Proposed:** split it — `DoNotContact`, `OptedOut` and `Complaint` stay absolute in code and are never overridable, while the discretionary reasons (`NotInterested`, `BouncedEmail`, `WrongNumber`) become settings-backed with the enum as the default. That satisfies what BR-DNC-04 is actually for — policy tuning without a deploy — without making "stop contacting me" a toggle. **Needs your call before it is built** |
| **T-45** | 📄 **`.xlsx` import support** | FR-LEAD-07 says "CSV/Excel"; **only CSV/TSV is built**. `.xlsx` is a ZIP of XML and needs `phpoffice/phpspreadsheet` — a real dependency that loads a sheet into memory, which is the wrong shape for Hostinger shared hosting (T-03). Held as a decision, not an oversight. In practice most exports are CSV and the upload error says so explicitly. Reader is isolated behind `App\Support\CsvReader`, so adding a format touches one class. **Decide when a client actually sends an `.xlsx`, or alongside the VPS move (T-30)** |

---

## Priority right now

0. ~~**T-48** — restore a working test database~~ ✅ **Worked around 2026-08-11**; the suite runs again. The corrupt `mysql.db` table and the MariaDB-vs-MySQL mismatch both remain — see T-48
1. **T-44** — Android call-recording feasibility on real devices. Now the *first* thing, not the third: Android 10+ blocks third-party call recording on most modern handsets, and ADR-B is built on the assumption that it works. Answer this and T-28 largely answers itself
2. **T-28** — ADR-B, the calling architecture. Still gates Phases 10, 11, 31 and 32 (the Phase 9 server side is built and is unaffected either way)
3. **T-17** — BR-CALL-04 calling hours. Implemented at 09:00–20:00 in the **lead's** timezone and now enforced on every dial. Worth confirming the window, since it silently blocks work outside it
4. **T-36** — start WhatsApp verification, because the clock is external and not ours

## Roadmap

Full Phase 1–36 tracking in [MODULE_STATUS.md](MODULE_STATUS.md). **Next: Phase 11 — Call Recording**, which cannot start until T-44 is answered and ADR-B is confirmed. The alternative next step, if those stay open, is the dialer UI (T-46).
