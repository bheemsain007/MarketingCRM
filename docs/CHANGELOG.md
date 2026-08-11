# Changelog

All notable changes to this project's specification and implementation. Phases are the unit of release.

---

## The encryption was being undone by its own cache - 2026-08-12

Found while re-reading the document written an hour earlier. SECURITY §7A claimed a leaked database dump "does not yield plaintext" credentials. **It did.** 2 tests. **Suite: 657 passing, 0 failing.**

### A comment that was true when written
`overrides()` cached decrypted settings with `Cache::rememberForever`, describing the result as "a private in-process concern". That holds for an in-memory driver. The deployment target has no Redis, so `CACHE_STORE=database` (DEPLOYMENT §3A) - and every provider credential was written to the `cache` table **in plaintext, in the same database, and therefore in the same backups** as the encrypted rows it was derived from.

Encrypting `settings.value` and then caching the plaintext beside it protects nothing.

### Split by sensitivity, not cached uniformly
Non-secret settings are still cached across requests - the dialer reads its cooldown on every skip check and that query is worth avoiding. **Secrets are read from their encrypted rows and decrypted into memory for one request only.** The service is now bound `scoped()`, so a request shares one instance and a credential written through one path is visible to the next.

The cost is one query per request, on paths that were already about to make an HTTP call to a provider.

### The test had to fight the test environment
`phpunit.xml` sets `CACHE_STORE=array`, so **on the suite's own driver this bug is invisible**. The regression test forces the `database` store - the one production uses - and reads the cache table directly. A test that passes because it never exercises the configuration it is protecting is worse than no test, because it reports safety.

### Worth stating plainly
This was found by re-reading a document written an hour before, not by a test failing. The previous entry closed a documentation ticket that turned out to hide two bugs; writing that documentation honestly is what exposed this third one. **The claims a security document makes are worth checking against the code, especially the reassuring ones.**

---

## The recovery path depended on the broken thing - 2026-08-12

T-52 was filed as a documentation defect: SEC-CFG-01 said provider credentials live in `.env` -> `config/*`, and since the settings layer landed that has not been the whole picture. Writing the amendment honestly meant describing what happens when `APP_KEY` and the stored data disagree - and that turned up **two real bugs**. 4 tests. **Suite: 655 passing, 0 failing.**

### One unreadable row took everything down
Settings values are encrypted with `APP_KEY`. Restoring a production database into staging is the ordinary way to end up with rows the current key cannot decrypt; rotating `APP_KEY` is the deliberate one.

The overrides load in a **single pass**, so an unguarded `DecryptException` threw out of the bulk load and took every other key with it - both webhooks, both message drivers, and the settings screen an operator would use to fix it. Now each row is decrypted defensively: an unreadable one is skipped, logged by key and never by value, and falls through to the config default.

**Degrading that way fails closed.** An unset credential resolves the message driver to the log driver, and a webhook with no secret refuses every request. Falling back never opens anything.

### And then the fix could not be applied
The test written for the obvious recovery - overwrite the bad credential - failed. Eloquent computes what changed by comparing the new value against the stored one, and **that comparison decrypts it**, so writing over an unreadable row threw too.

So the state was: one bad row, every integration down, and the one action that would clear it rejected. `set()` now replaces an unreadable row instead of updating it. **A recovery path must never depend on the thing that is broken** - which is the whole reason this was worth chasing past the documentation change it started as.

### The trade, written down
Credentials in the database are credentials in **every database backup**. Encrypted with `APP_KEY`, which lives in `.env` - so a leaked dump alone yields nothing, but only if backups and `.env` are not stored together. Now stated plainly in SECURITY §7A rather than left implied, along with the question still outstanding: whether that trade is acceptable to you at all. The alternative is `.env`-only, which costs the audit trail and rotation without SSH.

---

## A bounced address stayed contactable - 2026-08-12

T-54, closed. BR-DNC-07 has always said a hard bounce suppresses the email address; the webhook recorded the bounce and stopped there, so we kept emailing dead mailboxes. 5 tests. **Suite: 651 passing, 0 failing.**

### Through the gate, not around it
The suppression is written by `DncService`, not by the controller. A webhook keeping its own suppression list is exactly the per-module DNC that ADR-E exists to prevent, and `suppress()` is already idempotent - so a provider redelivering an event cannot stack rows, which the tests pin.

### Only when the bounce is confirmed hard
`hard_bounce`, or a `bounce` whose payload names the type as hard or permanent. An unqualified bounce is recorded and the address stays contactable.

**The asymmetry is the whole design.** Failing to suppress a hard bounce costs sender reputation. Suppressing a *soft* bounce - a full mailbox, a server having a bad afternoon - permanently stops email to a real customer, and lifting it requires Manager+ (BR-DNC-06). Given one guess must be wrong more often, it should be the cheap one. Mailercloud's payload contract is still unconfirmed (T-53), which is a second reason to require the explicit signal rather than assume it. The message's `failure_reason` now says which of the two happened instead of "bounced" for both.

### Unsubscribe suppresses too - scoped to email
Beyond T-54's wording, and worth saying why. The webhook already recognised `unsubscribe` and `spam`: it marked the message failed and kept the address contactable, so the next campaign would email someone who had asked us not to.

It writes `opted_out` **scoped to the email channel**. `OptedOut` is otherwise an absolute reason - a null channel blocks everything - and **silently ending all phone contact because somebody clicked unsubscribe in a newsletter infers far more than the click said**. A lead who wants no contact at all is `DoNotContact`, which is a deliberate act by a person.

---

## Auditing the specification against the code - 2026-08-11

Every `BR-*`, `FR-*` and `SEC-*` ID pulled out of the specification and matched against the source and the suite. 3 tests. **Suite: 646 passing, 0 failing.**

### The check that mattered came back clean
**Nothing is cited in the code that is not defined in the specification.** Invented rule numbers are how a spec quietly stops being the source of truth - a comment citing `BR-LEAD-99` looks exactly as authoritative as one citing a real rule. 66 business rules, 61 cited in code, 55 named in a test, zero ghosts.

### Two rules were implemented but untested
- **BR-CUST-02** - billing identity belongs to the Customer. The fields start empty rather than copied from the lead, and the lead has nowhere to put them, so it cannot become a second answer to "who gets invoiced".
- **BR-INT-03** - a signal keeps a resolvable link to the call it came from, and keeps it after a later signal recalculates the score. **A score nobody can audit is a number nobody will act on**, and the first question asked of any hot lead is "why is it hot?".

### Three real divergences, now tracked
- **BR-DUP-03 and BR-DUP-04 do not exist** (**T-64**). Duplicate handling stops at phone. There is no merge operation anywhere in the codebase, and no "needs review" import status for email matches to land in. Not a bug - both need a review surface first, and auto-merging on a shared `info@` address would fuse two real people.
- **BR-DNC-04 is not honoured** (**T-65**). It says the reason x channel matrix is configuration; it is a `match` in an enum. Worth saying plainly: **I do not think moving it wholesale into config is the right fix.** A runtime-editable suppression matrix means one bad settings write silently re-enables contacting people who opted out, with no code review in the path. The proposal is to split it - absolute reasons stay in code, discretionary ones become settings-backed.
- `LeadService` claimed `BR-DUP-01..04` in its docblock while implementing two of them. Corrected, with the reason the other two are absent.

The 11 rules still without tests now map exactly onto unbuilt phases (campaigns, recording) and those two items. **Every rule that is implemented and unblocked has a test naming it.**

---

## Dashboard: "what do I need to do today?" - 2026-08-11

The dashboard had not been touched since Phase 8. It predated follow-ups, payments, deals and scoring, so the first screen everybody sees still answered only "how many leads are there" - a question nobody opens a CRM to ask. 4 tests. **Suite: 643 passing, 0 failing.**

### Six figures, each one actionable
Follow-ups due, missed, hot leads, awaiting an owner, payments overdue, unread notifications. The first and fourth link straight to the screen that clears them, because **a number you cannot act on is decoration**.

- **"Due" means due, not open.** A count including next month's reminders is not a to-do list.
- **Missed sits next to today's work**, not in a report. It is late, not void (BR-FUP-02), and burying it is how it stays missed.

### Scoped and permission-gated, block by block
Every figure is either the caller's **own** work or inside their data scope. Each block is gated on the permission that owns it: a telecaller sees their follow-ups, a manager additionally sees the unassigned pool, and only somebody with `payments.view` sees money owed.

Blocks are **omitted rather than zeroed** for roles that cannot see them. Showing Accounts "0 follow-ups due" would imply they have none, when the truth is that follow-ups are not their job.

### A convention worth keeping
The first version rendered each figure on its own line, which broke `assertSee('>2<')` - the convention the existing dashboard tests already relied on. Collapsed to match. Small, but the alternative was a test asserting on whitespace, which fails the next time somebody reformats the file.

---

## Model annotations: 280 static-analysis findings down to 97 - 2026-08-11

T-63's first step, done. **Suite: 639 passing, 0 failing; Pint clean; PHPStan clean against the baseline.**

### Generated from the schema, not hand-written
Two passes over `app/Models`:

- **Columns** - read from `information_schema` plus each model's casts, so `@property \App\Enums\QuotationStatus $status` comes from the cast that actually exists rather than from somebody remembering to write it.
- **Relations** - read by calling each relation method and asking the `Relation` object what it relates to, so `@property-read \App\Models\Lead|null $lead` cannot disagree with the code.

Hand-written annotations rot. Generated ones are wrong only if the schema is, and the schema is what the application already runs against.

**280 → 191 → 97.** The first pass killed every "comparison between string and enum will always evaluate to false"; the second killed the `Illuminate\Database\Eloquent\Model::$id` class, where the analyser could not tell what a relation returned.

### Nothing dangerous was hiding behind the noise
That was the question worth answering, and it is the reason for doing this rather than leaving 280 suppressed forever: **a suppressed finding you have never read might be a real one.**

With the noise gone, what remains is `?->` on values that cannot be null, and array shapes inferred more narrowly than they are. Two things did come out of it, and neither was baselined:

- **A dead defensive branch, twice.** `FollowUpResource` and `FollowUpService` both did `$status instanceof FollowUpStatus ? ... : FollowUpStatus::from((string) $status)`. Written before the cast existed; provably unreachable once the cast was visible. Removed - dead code implying a case that cannot occur is worse than no code.
- **`User::$open_lead_count` documented.** Not a column: it is the `withCount(['assignedLeads as open_lead_count'])` alias the assignment service adds. Annotated as such, so the next reader does not go looking for it in a migration.

One flagged branch in `LeadImportService` was **verified live by an existing test** rather than assumed to be a false positive. That check is the whole discipline: "probably a false positive" is how a real one gets waved through.

### Safety, given nothing is committed
There is still no commit in this repository, so a bad bulk edit across 40 model files would have been unrecoverable. The models were copied aside first, both passes were insert-only, every file was `php -l`'d afterwards, and the suite was run before and after. Belt and braces because there is no net.

---

## Static analysis, and a production-only bug it found - 2026-08-11

TESTING section 6 has proposed PHPStan since Phase 3 and the CI workflow written an hour earlier omitted it. Larastan added at level 5. **Suite: 639 passing, 0 failing.**

### The bug
`AuthService::login()` called **`env('TOKEN_EXPIRY_DAYS', 30)`** directly.

`config/crm.php`'s own header says: *"This is the ONLY layer allowed to call env(). Once config is cached in production, env() returns null everywhere else - reading env() from a service or controller is a bug that only appears after `config:cache` runs."* The project documented the failure and then shipped an instance of it.

The effect: an operator setting `TOKEN_EXPIRY_DAYS=90` gets 90 days in development and **silently gets 30 in production**, because `config:cache` stops `.env` being read and `env()` falls back to its default. No error, no log line, and the only symptom is mobile users being signed out two months early.

Moved to `config('crm.auth.token_expiry_days')`. This is the answer to T-10's "confirm the value" - the value was never being read in the first place.

### 281 findings, 1 real - and the triage is the point
The distribution:

- **~178** are one root cause: the models carry no `@property` annotations, so the analyser reads every cast attribute as a string and then objects to `$quotation->status === QuotationStatus::Approved`. The tests prove that comparison works.
- **29** `nullsafe.neverNull` - `?->` on something never null. Harmless.
- **16** looked like dead or always-wrong code, which is the category worth reading. Fifteen were the same annotation problem wearing a different hat. One was the `env()` call.

One of the fifteen was worth chasing properly: PHPStan called an import branch unreachable, which would have meant tag and product options silently doing nothing on every CSV import. **A passing test proves that branch runs** - the analyser's array-shape inference is simply too narrow. Checked rather than assumed, because "probably a false positive" is how a real one gets waved through.

### Baselined, deliberately, with the reasoning written down
280 findings are suppressed in `phpstan-baseline.neon` so CI enforces **no NEW errors** from today. Adopting a tool by suppressing everything and never looking again is the failure mode, so two things are on record: the triage happened *before* the baseline was generated, not after, and clearing it is a tracked task (**T-63**) with a known first step - annotating the models removes most of it in one pass.

Level 5 rather than 9 for the same reason. The top levels demand generics on every collection; adopting them here would produce thousands of findings, get suppressed wholesale, and teach everybody the tool is noise.

---

## Config contract fixes - 2026-08-11

Writing the CI pipeline exposed two things it would have failed on, and one of them was a real misconfiguration bug. 3 tests. **Suite: 639 passing, 0 failing.**

### The provider keys did not match the documented ones
`.env.example` has said `WHATSAPP_PROVIDER` and `WHATSAPP_API_KEY` since Phase 1. Phase 14's config read **`WHATSAPP_DRIVER`** and **`WHATSAPP_TOKEN`**. Same for RCS, Voice and Vaaad, where the example documents `*_PROVIDER` and I had invented `*_ENDPOINT`.

An operator would have filled in every documented key, deployed, and watched the integration do nothing. **No error, no log line** - the config would simply have read null and the channel would have fallen through to the log driver, which is designed to look calm.

`config/providers.php` and `SettingsRegistry` now use the documented names. `.env.example` is the deployment contract; the code was wrong, not the document.

### Nine tunables were readable in code and invisible in the example
`DIALER_MAX_QUEUE_SIZE`, `INTEREST_AUTO_FOLLOW_UP_HOURS`, all four `RATE_LIMIT_*` and others. Every one has a working default, so nothing was broken - but an operator cannot tune what they cannot see, and the rate limits in particular are a security control somebody may need to raise.

### `.env.example` now defaults `APP_DEBUG=false`
Against Laravel's shipped example, deliberately. The deployment path here is copying this file on the server (DEPLOYMENT §3A - shared hosting, no build step), and a debug page leaks stack traces containing database credentials to anyone who can trigger an error (SEC-CFG-03).

Defaulting to true means one forgotten edit exposes them. Defaulting to false costs a developer one line in their own `.env`, which they were going to write anyway. Fail closed.

### The document now has tests
`EnvExampleTest` asserts that every `env()` key the config reads is documented, that nothing credential-shaped carries a value (SEC-CFG-02), and that `APP_KEY`, `DB_PASSWORD` and `APP_DEBUG` are safe defaults.

This is a test of a **document**, which is unusual and is the point: `.env.example` drifts silently. A phase adds a key, nobody updates the example, and the omission surfaces months later as an integration that quietly does nothing. That is precisely what had happened, and nothing would have caught it.

### Style is clean codebase-wide
15 files carried pre-existing Pint drift - which would have failed the pipeline written an hour earlier on its first run. Formatting only; the suite is unchanged at 639.

---

## CI pipeline and full-chain verification - 2026-08-11

Three checks that had been asserted but never actually run end to end, plus the CI workflow to keep running them.

### The migration chain proved in both directions
Individual migrations have had their rollback verified per phase. The **whole chain** had not. Running it down and back up:

- 39 migrations tear down cleanly, leaving only the `migrations` table - so every foreign key drops in a workable order
- 39 come back up to 53 tables, and the suite is still green at 636

A `down()` that does not work is only ever discovered by somebody who needs it, usually at the worst moment. Now it is a check rather than an assumption.

### `composer audit` run, not just proposed
SEC-OPS-01 asks for it. **No advisories.** It had been listed as a proposed CI gate since Phase 3 without anybody running it.

### The CI workflow exists before the remote does
T-06 has said "no git remote, so nothing to run CI on" since Phase 3. That is a reason not to *have* a pipeline running, not a reason not to have written one - so `.github/workflows/ci.yml` is now in place and the first push will run it.

Gates, in order: `composer audit`, Pint, the migration chain up/down/up, then the suite. The audit runs first deliberately - a known-vulnerable dependency is worth failing on whether or not the tests pass.

**It pins MySQL 8.4 and PHP 8.2**, the versions T-02 records. The local machine runs MariaDB 10.4 (T-48) and the suite passes there, but `phpunit.xml` targets MySQL on purpose - the schema leans on FK `RESTRICT`, composite unique semantics and strict mode. **CI is where that claim finally gets tested**, which also means the first green build closes the MariaDB caveat that has sat on every test result this session.

Coverage is **reported, not enforced**. TESTING section 6 proposes 90% on services and 70% overall; failing a build on a number nobody has signed off teaches people to disable the check rather than to write tests.

---

## Lead page: follow-ups, messages and deals - 2026-08-11

Phases 13, 15, 21, 22 and 23 all shipped their APIs with no UI. A telecaller could not send an email, book a follow-up or open an opportunity from the screen they actually work in. Three tabs, 4 tests. **Suite: 636 passing, 0 failing.**

This is the same gap the dialer and DNC screens closed earlier: a feature that exists only as JSON is a feature nobody uses.

### What each tab does
- **Follow-ups** - schedule, complete, cancel, with the full history including reschedules. The panel says that scheduling again *moves* the open follow-up rather than adding a second (BR-FUP-01), because otherwise the behaviour looks like a bug. Missed follow-ups keep their action buttons: late is not void.
- **Messages** - send on any channel plus the full history. `provider` is shown, so `log` makes "why did nobody receive this?" answerable on the spot rather than from application logs.
- **Deals** - open an opportunity, see its value and status, mark it lost with a reason from the closed list the API accepts.

### A DNC refusal is shown as a standing fact, not a toast
A suppressed lead produces a persistent warning in the message panel rather than a disappearing alert. It is not a transient error - it is a fact about this lead that will still be true in ten seconds, and a toast that fades implies otherwise.

### Tabs load on first open
Three extra requests on every lead view, for tabs most people never touch, is a slow page for nothing. Each loads once, when its tab is first shown.

### A test premise I got wrong
I first asserted a Telecaller would not see the Deals tab. **Every seeded role holds `sales.view`**, so that was wrong about the system rather than a bug in it - the tab is correctly visible to everyone, and only its write controls are gated. The test now asserts what actually differs: Accounts has no `follow_ups` permission and so has no Follow-ups tab, and no write controls anywhere.

The JS handlers are bound inside the same permission blocks that draw the tabs, so a role without the permission has neither the tab nor a handler mentioning it.

---

## The mandatory DNC channel suite - 2026-08-11

TESTING section 4.1 calls the suppression suite "the highest-priority" one and asks for a test **per channel**. Only three of the nine rows had one. 27 tests. **Suite: 632 passing, 0 failing.**

### "It uses the same service" is an argument, not evidence
Email, SMS and human calling were covered. WhatsApp, RCS, Voice and AI Calling went through the same `DncService`, which is a good reason to expect them to work and not a reason to have never checked. A channel that quietly bypassed the gate is the single worst defect this system can have, and the whole point of the requirement is that each one is proved rather than inferred.

They all pass. That is the outcome worth having: the claim is now evidence.

### The control case matters as much as the refusal
Each channel gets a matching test that an **unsuppressed** lead IS allowed through. Without it, a gate that refused everything would pass every suppression test and read as excellent compliance.

### The reason x channel matrix, at the gate rather than in the enum
`DncSuppressionMatrixTest` has proved the enum since Phase 2. This proves the same matrix through `DncService::canContact()` - wrong number blocking six phone channels and not email, a hard bounce blocking email only, an SMS opt-out leaving WhatsApp reachable. The enum being right does not prove the service reads it correctly.

### Dispatch-time re-check, on every channel
BR-DNC-03 was tested for email. It is now tested for all six - a lead who opts out while a message sits in the queue must not receive it, whichever channel it was queued on.

### Idempotency (section 4.6)
A redelivered send job leaves the message `sent` with its original timestamp, and the `idempotency_key` unique constraint is asserted directly - the constraint, not an application check, is what makes double-sending structurally impossible.

### One row still genuinely outstanding
Section 4.1 also lists **scheduled campaigns**. Phase 18 does not exist, so there is nothing to test. Recorded as **T-61** rather than quietly treated as covered - a checklist with an unmarked gap is worse than one with a visible hole.

---

## Report dashboards - T-60, FR-RPT-03 - 2026-08-11

Chart.js dashboards over the Phase 27 API. 1 page, 4 tests. **Suite: 605 passing, 0 failing.**

Phase 27 shipped the figures as JSON, which is not a report anybody can read. This is the screen: eight tiles, four charts and a product table, all drawn from `/api/v1/reports/*` so there is one implementation of each formula and the browser cannot disagree with the API.

### Every rate is drawn with its denominator
FR-RPT-06 is enforced twice over now - the API returns rates as objects carrying `numerator`, `denominator` and `of`, and the page has exactly one function that formats them. A bare percentage is not reachable from either side.

A null value renders as an em dash, never 0%. "Nothing happened" and "things happened and none succeeded" are different facts.

### The page says the thing the chart invites you to get wrong
Booked and collected sit next to each other in the money chart, which is precisely the arrangement that tempts somebody to add them. So the card says in words that they are separate figures and are never added together (GLOSSARY 2.5). There is a test asserting the sentence is on the page.

The source chart plots leads and conversions **together**, for the same reason: volume without outcomes is the number that flatters a bad source.

### The period and its timezone are on screen
Rows are UTC, aggregation is in the organisation's timezone, and both endpoints of the window are printed. Two people comparing dashboards can see at a glance whether they are looking at the same thing.

### Chart.js from a CDN
Like Bootstrap and jQuery, and for the same reason - no Node on the Hostinger target (DEPLOYMENT §3A). Same CSP trade-off, already tracked as T-39.

---

## User administration - T-46 closed - 2026-08-11

The last missing Web CRM screen, and the last item in T-46. 7 endpoints, 1 service, 1 page, 23 tests. **Suite: 601 passing, 0 failing.**

### Two permissions, deliberately not one
`users.manage` creates and edits people. `roles.manage` decides what they may do. Admin holds the first; **only Super Admin holds the second**. So an Admin can onboard a telecaller and cannot promote one - which is what SEC-AUTHZ-05 asks for, and which collapses the moment role assignment is folded into a general edit.

It is not folded in. `PATCH /users/{id}` ignores a `roles` key entirely, and there is a test that sends `roles: [super_admin]` in an edit body and asserts nothing happened.

### Nobody changes their own roles, including a Super Admin
SEC-AUTHZ-05's second clause. The rule exists so that compromising one account is not the same as compromising every permission, and an exception for the most powerful account would defeat it entirely. Tested against a Super Admin trying to re-grant themselves the role they already hold.

### New accounts start with no role
An account with no role can sign in and do nothing. That is the safe default - the alternative is guessing at somebody's authority, and the guess that gets shipped is always the generous one. The API says so in its response message, and the screen repeats it for Admins who cannot fix it themselves.

### Lockout protection
- **Nobody can disable their own account.** Not paternalism: an admin who disables themselves may be the only person who could have re-enabled it.
- **The last active Super Admin cannot be disabled.** The system must always have somebody who can restore it.

### Disabling actually disables
Revokes every token and closes the open work session. Leaving either behind means a "disabled" user keeps working from an app that never re-authenticates, and keeps accruing attendance time against FR-ATT-01. Both are asserted, along with the disabled account then being refused at login.

### `is_active` is not mass-assignable
Discovered by a 500 while building this: the column is deliberately outside `$fillable` so no request body can re-enable a disabled account. `enable()` and `disable()` are the only things that write it, and the create path lets the database default apply.

---

## Phase 27 - Business Reports - 2026-08-11

Aggregates over everything the previous phases built. 5 endpoints, 2 value objects, 1 service, 23 tests. **Suite: 578 passing, 0 failing.**

### Every rate carries its own denominator, structurally
FR-RPT-06 asks that a rate displays what it is a rate *of*. Making that a UI convention would last exactly until the first developer in a hurry, so it is enforced by the type: `Rate` cannot be constructed without naming its denominator, and no endpoint can return a bare percentage.

**A zero denominator returns `null`, never `0`.** "No leads were contacted" and "leads were contacted and none showed interest" are different facts, and a dashboard rendering both as 0% is lying about one of them.

### Each formula is the one in the GLOSSARY, and cited
Most of these metrics have a plausible-looking wrong version, and the wrong version is what gets written by accident:

- **Average call duration divides by *connected* calls, never attempts.** The wrong version still looks reasonable and silently punishes whoever is working the deadest list. Tested against a hand-computed fixture.
- **Talk time excludes calls that never connected** - even ones carrying a recorded duration.
- **Booked and collected are separate numbers and are never summed.** Adding them reports the money twice: once when the deal was signed and again when it was paid.
- **Collected revenue counts by payment date, not sale date.** Money is collected when it arrives.
- **The headline conversion rate is cohort-based** - of leads *assigned* in the period, how many eventually converted. "Conversions this month over leads this month" mixes two unrelated populations and produces nonsense whenever volume changes. Tested with a lead from a previous cohort that must not inflate this month.
- **Interest and contact counts read the append-only status history, not the current status.** A lead contacted in March and now at Proposal was still contacted in March; reading current status would drop it from the denominator and inflate every rate built on it.

### The period states its own timezone
Rows are stored UTC and aggregated in the organisation's timezone. Without that conversion a "March" figure in Asia/Kolkata quietly includes five and a half hours of February - small enough to go unnoticed, large enough to make two dashboards disagree. The window and its timezone come back with every response so two people comparing numbers can see they used the same one.

The default period is **this month**, not all time: an unbounded aggregate over a growing table is the query that takes the dashboard down (FR-RPT-05).

### Not data-scoped, deliberately
Gated on `reports.business` (Manager+) instead. A business dashboard *is* the organisation-wide view; scoping it to the caller's own leads would produce a number that looks like a company total and is not one - worse than refusing the page.

### Unattributed leads are reported, not dropped
A source breakdown that silently omitted leads with no source would disagree with the lead total, and somebody would spend an afternoon on the difference.

### What is NOT here
- **Telecaller performance (FR-RPT-01)** - needs the attribution model decided, and that decision affects pay (T-24, Phase 26).
- **Campaign performance** - Phase 18 has not been built, so there is nothing to aggregate yet.
- **Chart.js dashboards (FR-RPT-03)** - the API returns the figures; the visualisation is a Web CRM screen, tracked as T-60.

---

## Phase 20 - Interested Lead Engine - 2026-08-11

Scoring, temperature and the interested-lead views. 1 table, 2 services, 3 endpoints, 1 scheduled command, 26 tests. **Suite: 555 passing, 0 failing.**

### The score is derived, never accumulated
Computed from `interest_signals` on every read rather than incremented on the lead. Two reasons, both load-bearing:

- **BR-SCORE-01 requires it to be explainable.** A telecaller must see *why* a lead is Hot, and "why" is those rows with their points. `GET /leads/{id}/score` returns the arithmetic the number came from.
- **The model is still a proposal (T-16).** Re-weighting an accumulated integer means guessing at history; re-weighting a derived score just recomputes. A test changes a weight at runtime and asserts the old lead re-scores.

The weights live in `config('crm.scoring')`, not in code, so re-weighting is not a deployment.

### Decay is the absence of events, so it is computed
Never stored as signals - inventing rows for silence would corrupt the very explanation the score exists to give. Measured from `last_engagement_at`, falling back to the most recent signal of any kind.

**That fallback is a real fix, not a detail.** `last_engagement_at` tracks *inbound* signals only, so a lead we sent a proposal to six months ago who never replied has no engagement at all - and without the fallback their score would sit at 20 for ever, which is precisely the stale-pipeline problem decay exists to solve.

### The engine will not walk a deal backwards
The transition matrix permits `Negotiation -> Interested`, because a **human** may legitimately step a stalled deal back. An automated signal must never do that: a prospect replying to an email while a proposal is on the table is not a reason to demote the deal, and a score engine that quietly reversed pipeline stages would make the funnel report meaningless.

So the engine promotes only from `New`, `Contacted`, `Follow-up` and `Callback` - an explicit list rather than "whatever the matrix allows". Caught by the test asserting a Negotiation lead stays put.

### Low-confidence AI is recorded without being acted on
BR-INT-04. Discarding it would lose the evidence that the model saw something; acting on it would let a 0.4-confidence guess reclassify a lead. The signal is stored with `acted_on = false`, the API says so in its response, and the interested view excludes it - because that view is what a telecaller works from.

Points for AI signals are weighted by the reported confidence.

### Recency gates temperature rather than bonusing it
BR-TEMP-02, already encoded in `LeadTemperature::derive()` since Phase 2 and now actually driven. A lead with enough points for Hot but sixty days of silence is not Hot; a suppressed lead is Dormant however warm it was. Both tested, along with `score` and `temperature` being unreachable through a `PATCH /leads/{id}` body (BR-TEMP-01).

### Repeat negatives are capped
Ten unanswered calls at -2 stop at -10 total, so a genuinely busy prospect is not buried. The breakdown flags the line as capped rather than silently clipping it.

### One route-ordering trap avoided
`/interested-leads`, not `/leads/interested` - the leads group registers `GET /leads/{lead}` earlier in the file, so the nested form would have bound "interested" as a lead id. A distinct path beats a rule about registration order that the next person has to rediscover.

---

## Phase 23 - Payments - 2026-08-11

The offline half of payments: recording money, the BR-PAY-02 matrix, derived balances and overdue detection. 2 tables, 22 tests. **Suite: 529 passing, 0 failing.**

### It found a data-scoping security bug
Writing the payment scope test surfaced this: **a Team-scoped user with no team could see every record owned by any other teamless user.**

Laravel rewrites `where('team_id', null)` as `WHERE team_id IS NULL`, so `applyDataScope`'s Team branch matched teamless owner against teamless owner. In an install where nobody has been put in a team yet - which is every install on day one - that is every lead, and through the lead scope every message, opportunity, payment, DNC entry and follow-up (SEC-AUTHZ-03).

`LeadPolicy::withinScope()` already had the `team_id !== null` guard, so the two disagreed: **the list leaked rows the policy then refused by id.** Both files carry comments warning that they must agree; one of them was wrong. Fixed with a regression test asserting a teamless manager sees only the unassigned pool.

### T-57 closed
`Converted` now requires a sale **and** a `Partial` or `Paid` payment against it, which is what BR-PAY-05 asked for all along. Phase 22 shipped the weaker gate knowingly and logged it; this closes it. A sale alone is a promise. Two Phase 22 tests were updated to record a payment first - the behaviour change is the point, not a regression.

### Money rules, stated once
- **Balance is derived, never stored** (BR-PAY-04). A stored balance is a second source of truth that drifts the first time two instalments land in the same second, and it is the number an argument with a customer turns on. A test asserts no `balance` column exists on either table.
- **The opening status is derived, not declared.** A payment that settles the remainder is `Paid`, one that covers part of it is `Partial`, one that is future-dated is `Pending`. Letting a caller declare "paid" on a part-payment is how a half-collected sale reports as settled.
- **Failed and refunded payments do not count** toward collected revenue or conversion.

### No orphan payments, taken seriously
BR-PAY-03 wants lead, customer, product and sale all non-null. They are, at the database as well as the service - and the service takes them **from the sale**, not from the request. A request that could name its own `customer_id` is one that can attach a payment to the wrong account, and the foreign key would happily accept it.

`product_id` is the strictest clause and is taken literally: it makes revenue-by-product exact rather than apportioned. A single-product sale is filled in automatically; a multi-product sale must say which product an instalment is against. More work at the till, correct in the ledger - **T-58** records the ergonomic cost in case you would rather apportion.

### Overdue belongs to the scheduler
Set by `crm:mark-overdue-payments` daily, never by hand - the same argument as `Missed` on follow-ups. Allowing it manually would let someone backdate a collections report. The sweep notifies whoever made the sale, because an overdue payment nobody is told about is one nobody chases.

### What is NOT built
**Payment links (FR-PAY-02) and gateway collection.** Both need the gateway named (T-34). The `gateway`, `gateway_payment_id` and `failure_reason` columns and the `gateway` method are in place for that half to land as a driver, exactly as the messaging channels did.

---

## Phase 22 - Sales - 2026-08-11

The phase that finally unblocks `Converted`, refused by the status matrix since Phase 7. 5 tables, 3 enums, 3 services, 12 endpoints, 26 tests. **Suite: 506 passing, 0 failing.**

### `Converted` is open, but only just
A lead can now reach `Converted` **if and only if a sale exists against it**. It is still not a status anyone may simply set: it is terminal, it drives revenue reporting and telecaller pay, and the matrix offers no way back out, so a lead converted in error cannot be corrected.

**BR-PAY-05's additional condition - a non-failed payment - is not yet enforced**, because payments are Phase 23. The gate is deliberately weaker than the rule ultimately asks for, and that is recorded as **T-57** rather than left to be discovered.

### Money is decimal, never float
`decimal(12,2)` throughout. A float cannot represent 0.1 exactly, and a discount calculated in floats disagrees with the invoice by a paisa often enough to be noticed - by an accountant, in front of a customer.

### Prices are snapshotted
A line's `unit_price` is copied when the product is added, and a quotation copies its items rather than referencing them. A price rise next quarter must not silently reprice every open deal, and a product renamed two years later must not retroactively change somebody's paperwork. Both are tested.

### A discount cannot be approved by the person who raised it
`discounts.approve` alone would have let an Admin approve their own 30% discount - permission is not the same as separation of duties. A salesperson who can approve their own discount does not have an approval step, they have a checkbox. The threshold stays configuration (T-21, proposed 15%), and the decision - which quotation, at what discount - is written to the audit log alongside the access record the middleware already writes.

### Lost reasons are an enum, not free text
FR-SALE-05 asks for lost sales reportable **by reason**. Free text produces "price", "Price" and "too expensive" as three answers to one question. `Other` requires a note, because it is what people reach for when the list is inconvenient.

### Customers are deduplicated, and the lead survives
BR-CUST-01: the lead is not converted in place. It survives with its own history and the customer links back through `origin_lead_id`, which is what makes "where did this customer come from?" answerable a year later. BR-CUST-04 dedupes on phone then email - without it a second deal with the same person splits the account's history in two, and neither half is complete.

**Sale credit is fixed at the moment of sale.** Deriving it from the lead's current owner would silently move commission when the lead is reassigned (T-24).

### One deviation from the planned schema
DATABASE_SCHEMA lists a separate `lost_sales` table. A lost opportunity is the same row in a different state, not a different entity, and a second table would need keeping in step with the first for no reporting benefit. Recorded as a deliberate deviation - **T-56**.

### A flaky test fixed on the way past
`leads can be searched by name company phone or email` seeded three leads from the faker as noise. With random Indian names, "Sharma" turned up in that noise often enough to fail roughly one run in twenty. Pinned to fixed values - a suite that fails at random teaches people to re-run rather than to look (TESTING section 5).

---

## Phase 21 - Follow-ups and Notifications - 2026-08-11

The `follow_ups` and `notifications` tables had been sitting unused since Phase 2. 6 endpoints, 2 services, 1 scheduled command, 31 tests. **Suite: 480 passing, 0 failing.**

### It found a data-destroying schema bug from Phase 2

`follow_ups.scheduled_at` was declared as a bare `$table->timestamp('scheduled_at')`. MySQL and MariaDB apply automatic initialisation **and automatic update** to the first `TIMESTAMP NOT NULL` column in a table when it has no explicit default, so the column came out as:

```sql
scheduled_at TIMESTAMP NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**Every write to a follow-up row reset its scheduled time to the moment of the write.** Completing, cancelling, rescheduling, transferring on reassignment, or the scheduler flagging a miss - all of them silently destroyed the schedule. A follow-up booked for Friday became "due now" the first time anything touched it, and the missed-follow-up report would have been measuring nothing but its own last write.

It was caught by the BR-FUP-03 test asserting that rescheduling preserves the original's time, which it could not, through no fault of the service. Fixed by migration, with a regression test that updates an unrelated column and asserts the schedule survives. No other table is affected - the rest declared `useCurrent()`, which sets a default and therefore suppresses the automatic-update half.

### Reschedule creates a row rather than editing one
BR-FUP-03 wants the prior schedule retained. The original is closed and a new row points back at it through `rescheduled_from_id`; editing `scheduled_at` in place would erase the evidence that a commitment was moved, which is exactly what FR-FUP-04 asks the history to show.

### `Missed` is the scheduler's word, not a user's
There is deliberately no endpoint to mark a follow-up missed. The difference between "nobody got to it" and "somebody decided not to" is the entire value of the report, and a telecaller who could relabel their own overdue work would make it worthless. A missed follow-up **can** still be completed late - it is late, not void, and refusing would push people to book a fresh one and lose the miss from the record.

### Reminders fire once
`reminder_sent` guards a command that runs every minute. Without it the same person is notified every minute from the lead time until the follow-up is due, which is how people learn to ignore notifications. A follow-up owned by a disabled account is still marked as reminded, or the tick spends its life retrying a notification nobody can receive.

### T-14 answered: follow-ups transfer with the lead
BR-ASSIGN-04's open question is now built as "yes, transfer". A follow-up left pointing at the previous owner is invisible to the new one and meaningless to the old. Completed history does **not** move - it records who actually did the work.

### Notifications are never DNC-filtered
BR-NOTIF-01, and it sounds like a bug until you say it out loud: `DncService` protects leads from being contacted; notifications target staff. A test asserts a telecaller still hears about a follow-up on a lead that is on the do-not-contact list.

Reading someone else's notification is a **404, not a 403** - sequential ids with one row per user make it the most trivially enumerable resource in the system, and a 403 would confirm it exists.

### A circular dependency, resolved honestly
`LeadAssignmentService` needs follow-ups (to transfer them), `FollowUpService` needs `LeadService` (to write the timeline), and `LeadService` needs `LeadAssignmentService` (to auto-assign on create). Constructor-injecting all three exhausts the container's memory before anything runs. `FollowUpService` is resolved at call time instead - which breaks the cycle without pretending one of those three dependencies is not real. The cleaner fix, extracting the timeline writer out of `LeadService`, is a wider refactor than reassignment warranted.

---

## Phase 12 — Facebook / Instagram Lead Capture — 2026-08-11

Inbound, where the previous two phases were outbound. 2 endpoints, 1 service, 1 job, 18 tests. **Suite: 449 passing, 0 failing.**

### This one genuinely cannot run unkeyed
Unlike the outbound channels, where `LogDriver` lets the whole path execute with no credentials, **a Meta webhook does not contain the lead**. It carries a `leadgen_id` and nothing else useful; the answers must be fetched from the Graph API with a page access token. Without one there is nothing to create a lead from, and the job says so rather than inventing a placeholder. That difference is worth knowing before Phase 12 is called "ready".

### The endpoint does no privileged work inline
SEC-WH-05 shapes the whole controller: verify → persist raw → enqueue → acknowledge. Creating a lead means a Graph call, duplicate detection and auto-assignment, and Meta retires a subscription that responds slowly. A test asserts the request cycle never touches Graph and never creates a lead.

### Replay protection is keyed on the leadgen, not the delivery
One POST can carry several leads across several pages. Logging per **leadgen id** rather than per delivery means a redelivery is absorbed by the `(provider, provider_event_id)` unique index whatever envelope it arrives in — and a delivery containing one new lead and one repeat processes only the new one. Both cases are tested (FR-META-03, SEC-WH-03).

### The signature is computed over the raw body
`X-Hub-Signature-256` is HMAC-SHA256 over the **exact bytes received**, not the re-encoded array — re-encoding changes key order and whitespace and the digest would never match. This is the entire protection on an endpoint anyone can POST to, so an unsigned request, a forged one, and an unconfigured install are all rejected and all tested.

### A rejected payload is logged, but not verbatim
SEC-WH-04 wants raw payloads kept for audit. A *forged* payload is attacker-controlled content we would be storing indefinitely, so only the structural fields survive — asserted with `drop table leads` in a rejected body.

### Duplicates, attribution and answers nobody asked for
A repeat enquiry from a known number does **not** create a second lead (BR-DUP-02); the existing owner gets a timeline entry so the new enquiry is not invisible to them (BR-ASSIGN-05). Instagram leads are attributed to `INSTAGRAM_ADS` separately from Facebook — lumping them together would hide which one actually pays. Custom form questions have nowhere to live until lead custom fields exist (T-40), so they are written into a note rather than dropped.

New leads are **auto-assigned** (FR-LEAD-10). Nobody is watching a queue at 2am, and an unassigned inbound lead is one nobody calls.

---

## Phase 15 — SMS (BhashSMS) — 2026-08-11

One driver class and its tests. 10 tests, **suite: 431 passing, 0 failing**.

This is the payoff from Phase 13's shape: the DNC gate, queue, idempotency key, retry policy, status transitions and history all already existed and are channel-agnostic, so a whole communication channel cost one class and no changes anywhere else.

**Forced to HTTPS.** BhashSMS documents its API over plain HTTP with the account username and password as **query parameters**. Over TLS that is merely poor practice; over plaintext it puts working credentials into every proxy log, reverse-proxy access log and network capture between us and them. The driver forces `https://` and a test asserts it. Getting them out of the query string entirely needs provider support — **T-55**.

**The number goes on the wire national, not E.164.** Storage stays `+919876543210`; the gateway gets `9876543210`. A non-Indian number is **refused rather than truncated** — a mangled number reaches a real person who did not ask to hear from us.

**A 200 is not a success.** The gateway answers HTTP 200 for business-level refusals — wrong password, unregistered DLT sender ID, a template that does not match its registration. Trusting the status code would mark every one of those `sent`, and nobody would ever find out why the SMS never arrived. The driver reads the body: `S.` prefix is acceptance, anything else is a rejection that will not change on retry. A genuine non-200 is transport-level and *is* retried.

**Wire format provisional** (T-53/T-35), like Mailercloud's — the `sendmsg.php` shape and the `S.` convention are how the gateway is commonly documented, not a contract we have been given.

---

## Phase 13 — Email (Mailercloud) — 2026-08-11

The first communication channel, built without the credentials. 3 endpoints, 1 webhook, 4 services, 1 job, 23 tests. **Suite: 421 passing, 0 failing.**

### Built channel-agnostic, deliberately
`MessageDriver` + `MessageDriverManager` mean SMS, WhatsApp, RCS and Voice each arrive as **one more driver class** — no route, controller, service or job changes. Email is simply the first one with a provider behind it.

### The whole path runs with nothing configured
An unconfigured channel resolves to `LogDriver` instead of throwing. That is what makes "build now, key later" real: the DNC gate, template render, queue, message record and status transitions all execute today, and adding a key changes which driver resolves and nothing else. The fallback is **visible, not silent** — the message records `provider = "log"`, and the API response says so in words rather than implying delivery.

### The DNC gate is checked twice, on purpose
Once when queueing, and again inside the job at dispatch time. BR-DNC-03 exists for exactly the window in between: a lead who opts out while a campaign sits in the queue must not receive what was already prepared for them. Both checks are tested.

A suppressed send produces **a recorded `skipped` message AND a 403**. Both matter — the skip is the audit trail (BR-DNC-05), the error is what stops a telecaller assuming it went out.

### Templates are substituted, never executed
`{{ lead_name }}` is a `str_replace`, not Blade. A template body is operator-supplied text, and rendering it as Blade would make the template editor a remote-code-execution primitive for anyone holding `templates.manage` (SEC-IN-06). Tested with `{{ config("app.key") }}` in a body.

### Retry policy distinguishes "wrong" from "unlucky"
A provider **4xx** is a rejection — the request itself is malformed, so three more identical attempts end in the same place. A **5xx or timeout** is retried with 30s/2m/10m backoff (FR-COMM-06). A message is never left `queued` forever: `failed()` catches the exhausted case.

### The webhook treats its input as hostile
Unauthenticated by necessity, so: every call is logged **before** it is acted on (including forged ones), the shared secret is compared in constant time, an unconfigured secret refuses everything rather than accepting everything, an unknown message id is acknowledged rather than retried forever, redeliveries are absorbed by the `(provider, provider_event_id)` unique index, and an **unrecognised event is recorded rather than guessed at** — guessing is how a bounce silently becomes a delivery.

### Two things deliberately not built
- **Bounce → suppression.** A hard bounce is a BR-DNC-07 trigger, but writing `dnc_entries` from a webhook controller is exactly the per-module suppression ADR-E forbids. Left with the other automatic triggers in Phase 19 — **T-54**.
- **The Mailercloud wire format is provisional.** Their transactional API and webhook-signing docs have not been supplied (T-35), so the request body and signature scheme are the conventional shapes and need confirming before production — **T-53**. Everything around them is independent of that detail.

---

## Settings and provider credentials — 2026-08-11

Eight phases are blocked on credentials nobody has yet. This gives them somewhere to land, so the integrations can be built now and keyed later. 1 table, 2 endpoints, 1 screen, 22 tests. **Suite: 398 passing, 0 failing.**

### Two requirements pointed opposite ways
**SEC-CFG-01** says provider credentials live in `.env` → `config/*`. But **SEC-AUD-02** requires auditing "provider credential changes" — you only audit what happens *in the app* — and **SEC-CFG-04** wants rotation without code changes, on a Hostinger target where editing `.env` needs SSH the lower plan tiers do not provide (T-12).

Built so both hold: **`config/*` remains the source of defaults, and the `settings` table is an audited override on top.** `SettingsService::get()` reads the row, falls back to `config()`. An empty table behaves exactly as the application did before. A bad stored value is cleared, not deployed around. SEC-CFG-01 wants a wording amendment to say so — tracked as **T-52**.

### The registry is an allowlist, not a convenience
`SettingsRegistry` defines every settable key. Without it, `PATCH /settings` is an arbitrary-config-write primitive — `app.debug`, `database.connections.*`, anything (SEC-IN-06). An unknown key is a 422.

### A bug caught by its own test
The first cut derived permission from `type === 'secret'`. That meant an **Admin could change `providers.rcs.endpoint`, `providers.payment.gateway` and `providers.meta.app_id`** — none of which are secrets, all of which are credential configuration. An endpoint points every future request somewhere of the caller's choosing; that is worse than reading a key. Permission now follows the **provider group**, so all of it is `credentials.manage` (Super Admin only, SEC-AUTHZ-06).

### Secrets
Never returned by the API — only `is_configured`, a hint, and who changed it last. The hint is the last four characters, and only when the value is at least twelve long; an eight-character token would otherwise be half-disclosed by its own mask. `value` is encrypted **unconditionally**, not only when `is_secret` — one forgotten flag would otherwise write an API key in clear text, and encrypting an integer costs nothing. The audit log records the key and never the value, for non-secrets too: a redaction rule with an exception is a redaction rule that leaks.

### Clearing restores the default
An emptied field deletes the override so the shipped default applies again, rather than nulling the setting. Clearing a field must not be a way to break the application.

### Vendor-shaped placeholders, deliberately
WhatsApp BSP, RCS, Voice and the payment gateway are still unchosen (T-31–T-34), so those groups carry a generic endpoint + key rather than invented vendor-specific fields that would be rewritten the moment a vendor is picked.

---

## T-48 diagnosed and worked around — 2026-08-11

Four sessions of work had accumulated 37 tests that had never executed. This is what was actually wrong, and what is still wrong.

**Suite now: 376 passing, 0 failing** (1311 assertions), up from the Phase 10 baseline of 339. Every test written across the UI pass and the DNC slice passes.

### The diagnosis was wrong the first time
T-48 said "the test database is gone". It was not. `CHECK TABLE mysql.db` returns `Page 0: Got error: 176 when reading datafile / Corrupt` — **the Aria table that stores database-level grants is damaged**. Every `GRANT ... ON <db>.* TO ...` writes there, so `crm_user` could be *created* but never *granted* anything: it ends up with `GRANT USAGE ON *.*` and is denied on connect. `mysql.global_priv` is intact, which is precisely why `root` still worked — root's privileges are global, so they never touch the broken table.

That also explains the original symptom. The account did not vanish; its grants did.

### What was changed
Both databases created, and a **gitignored** `backend/.env.testing` points the suite at `root`. `php artisan test` now works with no environment prefixes. Nothing was committed, and no existing database was touched.

### What was deliberately NOT done
`REPAIR TABLE mysql.db` was not run. Repairing a corrupt Aria page generally **discards** it, and this server hosts roughly nine unrelated databases — `billing_saas`, `epaper`, `niviyo_billing`, `tenant1`, `tenant2`, `landlord_news`, `landlord_ngo`, `itnn_admin`. Discarding `mysql.db` would strip the database-level grants of all of them, breaking those applications' logins. Back the grants up, then decide.

### The caveat that remains
This is **MariaDB 10.4.32, not the pinned MySQL 8.4.9** (T-02). `phpunit.xml` targets MySQL deliberately, because the schema leans on FK `RESTRICT`, composite unique semantics and strict mode. A green suite here is strong evidence, not proof against the production engine.

---

## DNC administration — 2026-08-11

Suppression has been *written* since Phase 7 — by `Not Interested`, by Wrong Number call outcomes — with nothing able to read it back or lift it. This is the Phase 19 admin surface brought forward, for the same reason `DncService` itself was (T-29). 3 endpoints, 1 service method, 1 policy, 1 page, 17 tests.

**Test status: still not verified** — T-48 unchanged.

### This was not the screen it was advertised as
T-46 said the remaining items had working APIs and only lacked screens. That was wrong: there was **no DNC controller, no routes, and no removal method on `DncService` at all**. What shipped here is a vertical slice — service, API, policy, screen. The same correction applies to user administration, now tracked as **T-51**.

**Removal deactivates, never deletes.** The schema anticipated this in Phase 2 (`active`, `removed_by`, `removed_at`, `removal_reason`) and nothing had used those columns. A `DELETE` would destroy exactly the evidence a compliance question needs.

**A removal reason is mandatory.** Actor and timestamp the server knows; the reason only exists if the caller is made to supply it, and "why was this person put back on the call list?" is the question a review actually asks (BR-DNC-06).

**A telecaller may add a suppression but never lift one** — including on their own lead. The person most motivated to un-suppress a lead is the one whose target it was. `dnc.remove` is Manager+ and already sat in `Permission::isAudited()`, so every removal writes an audit entry from the middleware before the controller runs.

**The list is scoped through the lead.** The organisation's DNC list is a list of everyone who has ever refused us; a telecaller sees their own book's entries only. Entries with no lead — an inbound STOP from a number nobody has imported — have no owner to scope by, so they are visible only at All scope rather than shown to everyone by default.

**Lifting one suppression does not un-suppress the lead.** The flag is recomputed from the table, so a lead with a bounced email *and* a wrong number stays suppressed when only the first is lifted.

### Noticed, not fixed: T-50
`DncReason::requiresElevatedRemoval()` has flagged `Do Not Contact` and `Opted Out` as "never undone casually" since Phase 2, tested but never enforced. BR-DNC-06 only requires Manager+, which `dnc.remove` already gives, so no rule defines what *stricter* would mean. Built as: the API reports the flag, the screen warns before lifting one, authority is unchanged.

---

## Account / password change — 2026-08-11

The change-password endpoint has existed since Phase 4 and no browser could reach it. 1 route, 1 page, 3 tests.

**Test status: still not verified** — T-48 unchanged.

**No permission gate**, deliberately. Every role must be able to change its own password; gating it would leave a Viewer unable to respond to a password they believe is compromised. The test asserts this for all six roles rather than the convenient one.

**Profile fields are read-only.** Name, email, role and team are administrative — a self-service role field is privilege escalation with extra steps. The page says who to ask instead.

**"Sign out everywhere" really does include here.** `/auth/logout-all` deletes tokens and closes the work session, but it cannot clear this browser's session cookie — so the button would otherwise leave the user signed in at the one place they were trying to secure, with their attendance session closed underneath them. The page calls the API and then submits the ordinary web logout form. Tokens first, so a failure leaves them still signed in here to retry.

### Noticed, not fixed: T-49
`POST /auth/change-password` verifies `current_password` — a credential oracle — but carries the 120/min standard limiter rather than the 5/min auth one. The attacker needs a live session first, so the prize is lockout rather than entry. Not changed unilaterally: `api-auth` keys partly on `input('email')`, which this endpoint does not send, so it would silently degrade to an IP-only bucket shared with login. Wants a deliberate third limiter.

---

## Assignment UI — 2026-08-11

The unassigned pool had no screen. Leads landed there from manual creation and from any import or webhook where auto-assignment found nobody eligible, and then sat there — the API could assign them, but nothing listed them, so nobody knew they existed. 1 route, 1 page, 1 card, 6 tests.

**Test status: still not verified** — T-48 is unchanged, the feature suite cannot run. Views compile, routes register, Pint is clean, and the 62 DB-free unit tests pass.

**Two surfaces.** A dedicated pool page as a manager's inbox, and an assignment card on the lead detail page for re-homing one lead. Both are gated on `leads.assign`, which a telecaller does not hold — they can work a lead but not claim one or push one at a colleague (BR-ASSIGN-05).

**The assignee list carries open-lead counts**, because "who is free?" is the actual question being asked. Nobody eligible is shown as an operational state, not an error: it means everyone is inactive or at the cap (BR-ASSIGN-02).

**Bulk assignment is sequential, one request per lead.** There is no bulk endpoint, and firing N parallel requests would race the open-lead cap — each assignment changes who is eligible for the next. Slower and correct beats faster and wrong. The page counts what actually happened rather than what returned 200: auto-assign returns a success envelope with the lead *still unassigned* when nobody is eligible, and that is a skip, not an assignment.

**This de-escalates T-47.** A lead created by a telecaller is now visible to somebody instead of disappearing. Whether manual creation should self-assign is now a convenience question, not a correctness one.

**The `null` filter operator was untested until now.** The pool page is built entirely on `?filter[assigned_to][null]=true`, and an empty filter value would have been `WHERE assigned_to = ''` — an empty pool rather than an error. Now covered.

---

## Lead create / edit form — 2026-08-11

Closes the widest gap in T-46: until now a lead could only arrive by CSV import or a direct API call, so a telecaller handed a number on a phone call had nowhere to put it. 2 routes, 1 view, 10 tests.

### Two API bugs the form exposed
Building the edit screen meant sending fields nothing had sent before, and both broke:

- **`PATCH /leads/{id}` could not update the alternate phone.** `alt_phone` is a request field; the column is `alt_phone_e164`. `store()` translated between them and `update()` passed the request field straight into `Model::update()` — a `MassAssignmentException` in dev, and a silently discarded value in production. Now translated in both places, and an empty value clears the number.
- **`PATCH /leads/{id}` could blank a lead's name.** The rule was `sometimes|string`, and an empty string is a valid string. Now `sometimes|filled|string`, so "omit to leave alone" and "send blank to erase" stay distinct. `UpdateLeadRequest` also gained the `alt_phone` validity check `StoreLeadRequest` already had — an unparseable number was normalising to null and reporting success.

**Test status: not verified.** The feature suite could not be run — the local `crm_user` MySQL account and both project databases are absent from the server currently on port 3306, which is MariaDB 10.4 rather than the pinned MySQL 8.4.9 (T-02). Views compile, routes register and the 62 DB-free unit tests pass; the 7 new feature tests are written but unexecuted. See TESTING §7.

**One view for create and edit**, because the fields are the same and a second copy would drift. What differs is which sections are offered: product interest, tags and an opening note are accepted by `POST /leads` and rejected by `PATCH /leads/{id}`, so the edit form does not draw them. Status, temperature, score, suppression and ownership are absent from both — each moves through its own endpoint (SEC-IN-06), and a control here would be a control guaranteed to 422.

**Duplicates point at the existing lead rather than just refusing.** `BR-DUP-02` already returns the owning telecaller in the error context (BR-ASSIGN-05); the form now surfaces that with a link, because "this number is already in the system" is only useful if you can find out whose it is.

**A telecaller cannot be redirected to the lead they just created.** Manual creation does not auto-assign, and a telecaller is scoped to their own leads — so the obvious redirect lands on a 403 for their own new record. The form detects this server-side and shows a "created, awaiting assignment" panel instead. Whether manual creation *should* self-assign is a business decision, now tracked as **T-47**.

---

## Dialer screen — 2026-08-10

The auto-dialer API was complete and tested but unreachable by a telecaller. This closes that gap (part of T-46). 1 page, 2 tests. Suite: **339 passing, 0 failing**.

Three states on one screen: no run open, a run with a lead in hand, and a finished run. Everything is driven through `/api/v1/dialer/*`, so the browser and a future Flutter dialer share one implementation of the skip rules and the single-assignment guarantee.

**The number is a `tel:` link, not a call button.** Under ADR-B the handset dials and the CRM is the system of record — a button implying the browser can place the call would be a lie about what the software does.

**Skips get their own panel.** FR-CALL-07 requires skips to be logged; showing them is what makes that useful. Eleven numbers skipped for cooldown is a very different run from eleven suppressed, and the panel distinguishes temporary skips from permanent ones at a glance.

**Moving on asks for confirmation when no outcome was saved.** Outcomes are write-once, so an unsaved one is gone for good once the run advances — worth one confirmation rather than a silent gap in somebody's call history.

An open run is picked up on page load, so a reload or a closed laptop resumes where it left off (FR-CALL-06).

---

## Phase 10 — Auto Dialer (server side) — 2026-08-10

7 endpoints, 2 tables, 21 new tests. Suite: **337 passing, 0 failing**.

### Still ADR-B-agnostic
The dialer decides **which lead is next** and whether it may be called. It does not place the call. That is the same split as Phase 9, and it means this phase holds whichever way ADR-B is decided. Phase 11 (Call Recording) is where that stops being true — it needs T-44 answered first.

### The queue is a table, not a browser variable
FR-CALL-06 asks that "pause halts dialling without losing queue position". That is only true if the position lives in the database, so `auto_dialer_sessions` and `auto_dialer_queue_items` hold the run: a telecaller can reload the page, close the laptop, or come back after lunch and `GET /dialer/current` puts them back where they were.

Pausing also **releases the lead being held** — someone who steps away must not sit on a lead nobody else can call.

### Skips are recorded, never silent
FR-CALL-07 requires skips to be logged, so skipped leads stay in the queue as rows with a reason rather than disappearing. A lead that silently vanished from a run is indistinguishable from one that was never queued, and "why did the dialer never ring this person?" is a real question six weeks later.

Seven reasons, each flagged temporary or permanent: `suppressed`, `no_phone`, `cooldown`, `follow_up_scheduled`, `outside_calling_hours`, `claimed_elsewhere`, `archived`. Compliance skips and scheduling skips are deliberately distinct — reporting them together would hide how much of a list is legally unreachable.

### The dialer cannot disagree with the manual-call gate
Suppression, calling hours and "has a usable number" are asked of `CallService`, not reimplemented. If the dialer had its own copy of those rules, the two would eventually disagree, and the disagreement would only ever be discovered by ringing somebody on the do-not-contact list. Claiming a lead then runs the **full** gate again: the pre-check exists to report the skip nicely, the gate is what protects the lead.

### Single assignment without Redis
BR-CALL-03 is enforced with a `lockForUpdate` row check at claim time, not a Redis mutex — the shared-hosting target has no Redis (DEPLOYMENT §3A), and Laravel's cache lock is used for the per-session serialisation so the code does not care which driver is configured.

Claims older than 15 minutes are treated as released. Without that, one closed laptop takes a lead out of circulation permanently — a correctness fix that only shows up in production.

### Two gaps the tests found
- **A run that ended because everything left was skipped returned a bare `null`**, throwing away the reasons. That is exactly the moment a telecaller needs them — "the queue finished" with no explanation reads as a bug. `next()` now always returns the skip list, with `lead: null` when exhausted.
- **The queue orders untouched leads first** (the leakage metric, GLOSSARY §2.9), which is correct — and meant a naive skip test never reached its skipped lead. Worth knowing: a recently-contacted lead sorts to the *back* of a run, not the front.

### Queue building
Data scope first — a run is the caller's own book, never the database. `Converted` / `Lost` / `Not Interested` are excluded at build time rather than skipped one at a time; finished work is not dialling work. Ordered by priority, then longest-waiting, untouched first. Capped at 200: a run is a shift's work, and a queue built once and worked for days is stale by the second hour.

### Added
- Tables `auto_dialer_sessions`, `auto_dialer_queue_items`. Rollback verified. Index names are explicit — the generated ones exceeded MySQL's 64-character identifier limit.
- `AutoDialerService`, `AutoDialerSessionPolicy`, `DialerSessionResource`, enums `DialerState`, `QueueItemState`, `DialerSkipReason`.
- `config/crm.php` → `dialer` block (queue cap, claim TTL).

### Not built
A dialer **UI**. The API is complete and tested; the screen is not (T-46).

### Endpoints
`GET /dialer/current` · `POST /dialer/sessions` · `GET /dialer/sessions/{id}` · `POST /dialer/sessions/{id}/next|pause|resume|stop`

---

## Phase 9 — Calling (server side) — 2026-08-10

5 endpoints, 25 new tests. Suite: **316 passing, 0 failing**.

### Built without waiting for ADR-B — deliberately
ADR-B (T-28) is still unconfirmed, and it is about **how a call is placed**: natively on the handset, or through a browser softphone. Everything in this phase sits on the other side of that question. A call record, a gate that decides whether a number may be dialled, an outcome with side effects, a scoped history — all of these are needed under any dialling mechanism, and none of them changes if ADR-B is decided differently. The device-side half is Phases 31/32 and is still waiting.

**T-44 remains the thing to test first.** ADR-B assumes the Android app can record calls locally, and Android 10+ blocks third-party call recording on most modern handsets. Nothing in this phase depends on that assumption.

### One gate, three checks, one place
`CallService::initiate()` refuses a dial when any of these fail, and it does so in the service rather than a controller because the auto dialer (Phase 10) and AI calling (Phase 24) dial through the same code. A gate implemented in a controller would be missing from both.

1. **DNC** (FR-CALL-08, BR-CALL-01). Read through `DncService` from `dnc_entries`, **never** from `leads.is_suppressed` — a test pins this by setting the flag with no entry behind it and asserting the call is still allowed. Suppression is per reason and per channel, so a bounced email does not stop a phone call (BR-DNC-02).
2. **Calling hours** (BR-CALL-04), evaluated in the **lead's** timezone. 11:00 in Kolkata is 06:30 in London, and a telecaller working late here must not ring someone in the middle of their night. The refusal carries `next_opening` — attempts are deferred, never dropped.
3. **A usable number.**

The gate also runs on the "log a call I already made" path. A second entry point that skipped it would be the entire problem.

### `calls.status` became nullable
Under ADR-B the server creates the intent and the device reports the outcome; between those moments a call genuinely has no outcome — it is ringing. The column was NOT NULL, forcing a choice between inventing a twelfth status (FR-CALL-01 permits eleven) or writing a placeholder like `no_response` at dial time.

The placeholder is worse than it looks: a dropped device report would leave a permanent record saying the lead did not answer, when nobody knows whether they did. So `status = null` now means *dialled, outcome not yet reported*, and the eleven remain the only **outcomes** accepted. The down migration fills nulls with `no_response` — the honest reading of "we dialled and never learned the result".

### Outcomes are write-once
A second attempt returns `409`. Call records are evidence: they feed talk time, telecaller pay and any disputed conversion. An outcome that could be rewritten later is one nobody can rely on. A genuine mis-tap is corrected with a note, not by editing history.

`duration_seconds` is forced to **zero on anything that did not connect**. A ringing phone is not a conversation, and counting it would inflate Average Call Duration for whichever agent gets the worst list (GLOSSARY §2.2).

### The side effects are the point
BR-CALL-05 automates exactly the things people forget:
- `wrong_number` / `invalid_number` → suppression (BR-DNC-07). Not a prompt and not the telecaller's decision — a number confirmed wrong that relies on someone ticking a box is a number that gets dialled again tomorrow. The reason decides the channels, so the email address stays usable.
- `call_back_requested` → an open follow-up assigned to **the telecaller who took the call**; the lead asked *them* to ring back. `callback_at` is required, because a callback with no diary entry is a promise nobody keeps.
- Any outcome → `last_contacted_at`, feeding the leakage metric (GLOSSARY §2.9). "We tried" is the fact being recorded.

### In the UI
The lead page gets a Calls tab: outcome history plus a log form, and the button state comes from `/callability` — so a telecaller sees *why* a lead cannot be called, with the time it reopens, instead of a dead button or a surprise refusal.

### Also changed
`applyDataScope()` takes an owner-relation argument. Team scope resolved the owner's team through a hardcoded `assignedUser` relation, which calls do not have — they belong to the telecaller who made them, through `user`. Scoping call history would have thrown at Team scope.

### Added
- `App\Services\Calls\CallService`, `App\Support\CallingHours`, `CallPolicy`, `CallResource`, `StoreCallRequest`, `RecordCallOutcomeRequest`, `LeadPolicy::call()`.
- Migration `allow_calls_without_an_outcome_yet`. Rollback verified.

### Not in this phase
Auto dialer (Phase 10), call recording (Phase 11), the Android client (31/32), and score increments on connect — scoring is BR-SCORE-01 and belongs to the Interest Engine in Phase 20.

### Endpoints
`GET /leads/{id}/callability` · `GET|POST /leads/{id}/calls` · `PATCH /calls/{id}` · `GET /calls`

---

## Phase 8 — Web CRM UI (core) — 2026-08-10

ADR-A confirmed and built. 8 web routes, 7 Blade views, 18 new tests. Suite: **291 passing, 0 failing**.

### One application, two credential styles
Browser requests authenticate by **session cookie** via Sanctum's stateful middleware; the Flutter app will keep using bearer tokens against the same endpoints, and Sanctum decides per request from the Origin. Same-origin means a token in the browser buys nothing and costs everything — any XSS could read it — so the session cookie is both the simpler and the safer option.

`AuthService::loginWeb()` shares the credential gate, the work session and the audit entry with the token path. That mattered more than it sounds: the original `login()` had the checks inline, so a browser login written independently could have shipped without the disabled-account check or the failure audit that detects password spraying. The gate is now extracted and shared, and a test asserts the browser path opens a work session (FR-ATT-01) — otherwise telecaller reporting would quietly under-count everyone who works from the web app.

### Blade renders shells; the API does the work
Pages carry navigation and the reference data a screen needs to draw its controls. Every row and every write goes through `/api/v1/*`. A Blade page that queried leads directly would be a second implementation of scoping and filtering, and the second path is always the one that gets a rule wrong. It also means the browser exercises the same endpoints the Flutter app will.

The lead detail page reads its status options from `/transitions` rather than the full status list, so the UI can only offer moves that will actually succeed — the matrix is never discovered one `422` at a time. The reopen reason field appears only when the selected move is a reopen.

### CDN assets, deliberately
Bootstrap 5 and jQuery load from a CDN rather than being built with Vite. The shared-hosting target has no Node (DEPLOYMENT §3A), so a deploy stays "upload plus composer" — adding a build step buys nothing here. The cost is CSP looseness, already tracked as **T-39**. Vite stays wired up if the assets ever need self-hosting.

### Two fixes the web layer forced
- **`ApiException` now implements `HttpExceptionInterface`.** Services are shared between the API and the Blade pages, but the exception handler only wraps `api/*` in the envelope — so a permission failure on a web page rendered as a **500** instead of a 403. The API path is untouched: the handler matches `ApiException` before it ever reaches the generic HTTP branch.
- **`redirectGuestsTo`** points Laravel's `auth` middleware at `web.login`; it looks for a route literally named `login`, and every route here is namespaced `web.*`.

### Role-aware, but not secured by it
`@permission('leads.import')` hides navigation and buttons a user cannot use. That is usability only — the route middleware and policies are what refuse the action, and a test asserts a telecaller opening `/imports` directly gets a 403, not a page. A hidden link is still a reachable URL.

The dashboard applies the caller's data scope before counting: a telecaller's "total" is their own book, not the company's. A dashboard counting outside scope would leak the size of the database (SEC-AUTHZ-03).

### What is NOT built yet
The API covers these; the screens do not exist:
- Lead **create/edit** forms — leads can be viewed and moved through the pipeline, but new ones still arrive by import or API.
- Assignment UI (`/leads/{id}/assign` exists), user administration, password change, DNC management.
- Any reporting beyond direct counts. Real metrics are Phase 26/27, where every figure has one agreed formula.

Deliberately scoped that way rather than half-building six screens. Also note the dashboard is not the Phase 26 reporting product and does not approximate any GLOSSARY Part 2 formula.

### Added
- `routes/web.php`; controllers `Web\LoginController`, `Web\DashboardController`, `Web\PageController`.
- Views: `layouts/app`, `auth/login`, `dashboard`, `leads/index`, `leads/show`, `products/index`, `imports/index`.
- `AuthService::loginWeb()` / `logoutWeb()` / shared `verifyCredentials()`; `@permission` Blade directive.
- `statefulApi()` middleware; session regeneration on login and invalidation on logout (SEC-AUTH-06).

### Running it
`php artisan serve`, then `/login`. Create the first account with `php artisan crm:create-user` — the dev database has no users yet.

---

## Phase 7 — Lead Status + Product Interest — 2026-08-10

7 endpoints, 42 new tests. Suite: **273 passing, 0 failing**. Unblocked by the T-15 sign-off on the BR-STAT-02 matrix, which is now recorded as confirmed rather than proposed.

### Status moves through one door
`status` is not part of `PATCH /leads/{id}` and never will be. Every change has to run the transition matrix, check authority, write append-only history and — for `Not Interested` — write suppression. A status reachable from a generic update body would skip all four, which would make the matrix decoration. `LeadStatusService` is the only code that writes `leads.status`.

An illegal move returns `422` **with the legal moves attached**, so a client corrects itself instead of guessing. `/leads/{id}/transitions` returns the moves *this caller* can make, so a UI renders only buttons that will work.

### Converted is refused, deliberately
The matrix permits Proposal/Negotiation/Decision Pending → `Converted`, but the service refuses it: conversion requires a sale with a non-failed payment (BR-STAT-05, BR-PAY-05) and sales arrive in Phase 22. `Converted` is terminal and drives revenue reporting and telecaller pay, so a lead marked converted today with nothing behind it could never be corrected — the matrix offers no way back out. It is also hidden from `/transitions`, because advertising a button that always fails is worse than not showing it. `Proposal` is *not* blocked the same way despite nominally requiring an Opportunity: it is reversible, and blocking it would stall ordinary telecaller work.

### A real bug the tests caught ⚠️
Product-interest propagation calls the status service with a **null actor** (a system change). The reopen guard skipped authority checks when there was no actor to check — "nothing internal reopens leads today" — which stopped being true the moment propagation existed. The result: recording interest in a product against a `Lost` lead **silently reopened it**, with no manager, no reason, and a system-authored history row.

Fixed in both places: propagation refuses to touch a closed lead at all, and the guard now rejects *any* automatic reopen outright. The second fix is the one that matters — it closes the hole for every future caller, not just this one.

### BR-STAT-04 was self-contradictory
The rule said the lead status *is* the furthest-advanced state across its products. That cannot coexist with a transition matrix and authority rules governing a status people set directly, and it would leave every product-less lead — every imported list — with no status at all.

Resolved and recorded in BUSINESS_RULES: **the lead status is authoritative and set directly; product interest pulls it forward, never back.** A less-advanced product does not drag the lead down, a closed lead is not reopened, and nothing auto-converts. Automatic advances are recorded as system changes with a null actor — crediting a person for one would corrupt attribution, which drives pay (GLOSSARY §2.6).

Ranking: New → Contacted → Follow-up / Callback *(equal)* → Interested → Proposal → Negotiation → Decision Pending → Converted. `Lost` and `Not Interested` rank -1: **off the ladder, not behind New**. Ranking them as merely "less advanced" is what let a single interested product reopen a closed lead.

### Product interest is genuinely independent
A lead can be negotiating the News Portal, interested in the Epaper and explicitly uninterested in News Posting at once (BR-PROD-01/02), and touching any one leaves the others exactly as they were. Interest runs the **same** matrix as lead status — a product cannot jump New → Negotiation any more than a lead can, and rules a telecaller learns in one place should hold in the other.

**Declining one product does not suppress the lead** (BR-PROD-03). It is a normal sales outcome; treating it as do-not-contact would end the whole relationship over a single "no thanks".

### DncService, built early
`Not Interested` must write suppression (BR-DNC-07). The alternative was writing `dnc_entries` straight from `LeadStatusService`, which is exactly the per-module suppression ADR-E forbids — so a minimal `DncService` now exists with `suppress()`, `canContact()` and flag-sync. This is a partial action on the **T-29** sequencing proposal. Phase 19 still owns removal flows, policy configuration, the admin UI, reporting and the full per-channel matrix.

`suppress()` is idempotent, and `canContact()` reads `dnc_entries`, never `leads.is_suppressed` — the flag is a list-filter cache that follows the entries and is never written independently (BR-DNC-01).

Two rules the suite pins down because they are easy to get backwards: **reopening from `Not Interested` does not clear suppression** (BR-DNC-06 — somebody who said "do not contact me" has not changed their mind because an internal status moved), and **`Lost` does not suppress at all** ("we didn't win it" is not "they told us no", and suppressing would block legitimate re-marketing to price losses).

### Added
- `App\Services\Leads\LeadStatusService`, `App\Services\Leads\LeadProductService`, `App\Services\Dnc\DncService`.
- `App\Enums\StatusSource` — what caused a change, distinct from `Channel`, kept for attribution.
- `LeadStatus::pipelineRank()` and `isClosed()`; `LeadPolicy::changeStatus/reopen/manageProducts`.
- No migrations: `lead_status_history` and `lead_products` were specified in Phase 2 and needed no changes.

### Endpoints
`PATCH /leads/{id}/status` · `GET /leads/{id}/transitions` · `GET /leads/{id}/status-history` · `GET|POST /leads/{id}/products` · `PATCH|DELETE /leads/{id}/products/{leadProductId}`

---

## Phase 6 — Leads (complete) — CSV import — 2026-08-10

FR-LEAD-07 closed. 4 endpoints, 2 tables, 31 new tests. Suite: **231 passing, 0 failing**.

### The shape of it
Upload returns **`202`, never `200`**. The request stores the file, resolves the header and counts the rows; everything else runs on the `imports` queue as **one job per row** (NFR-06, ARCH §4). A 50,000-row file must not depend on a browser staying open, and one malformed number must not roll back the 4,000 leads already written — which is exactly what the acceptance criterion "never partially corrupts on failure" asks for.

Rows go through `LeadService` like every other entry path, so E.164 normalisation, duplicate detection and timeline writing are identical whether a lead arrives by hand, by import, or later by webhook. That is the reason the service exists, and the import is the first thing to prove it.

### Duplicate is not an error
Row outcomes are `imported` / `duplicate` / `invalid` / `failed`, and the distinction is deliberate. A duplicate row is not a mistake by whoever prepared the file — the lead already exists and already has an owner (BR-DUP-02, BR-ASSIGN-05), and the report links straight to it. Merging the two would hide the only number an operator actually needs: how many rows were genuinely malformed.

### Header auto-detection
`Full Name`, `Mobile No.`, `Email Address`, `Company Name`, `Town` all map without configuration; matching is case- and punctuation-insensitive. An explicit `column_map` overrides it for the file that calls its phone column `Contact 2`. Files that cannot work at all — no phone column, no data rows, over the row limit — are rejected **synchronously** with a `422` that returns the detected header, so a client can render a mapping screen instead of making the user guess.

The reader is dependency-free and streams row by row: on Hostinger shared hosting the PHP memory limit is not ours to raise (DEPLOYMENT §3A). It handles the three things real exports do that break naive readers — a UTF-8 BOM in front of the first header, semicolon/tab delimiters, and trailing blank lines. A short row is padded rather than dropped; a missing trailing column is common in hand-edited files and is not worth losing a lead over.

### What a file is not allowed to do
`status`, `temperature`, `score`, `assigned_to` and `is_suppressed` are **not importable**. A file that could set `status = converted` would walk straight past the transition matrix and every rule attached to it (SEC-IN-06, BR-STAT-02). `auto_assign` requires `leads.assign` on top of `leads.import`, or a telecaller could hand themselves several thousand leads in one upload (BR-ASSIGN-01).

### Two failure modes designed against
- **Double-writing on retry.** `UNIQUE(lead_import_id, row_number)` is the idempotency key: a row job that created its lead but died before acknowledging is a no-op on redelivery, not a second lead.
- **An import stuck at `processing` forever.** Counters advance with SQL-level increments (concurrent workers cannot lose a count), the import finalises on a conditional update so exactly one worker wins, and a row job that exhausts its retries still records itself in `failed()` — otherwise the run would never reach its total and nobody could tell a slow import from a dead one.

### PII handling
The uploaded file is a plain-text list of hundreds of people's names and numbers. It is stored on the **private** disk under a generated name (the original filename is kept as data, never used as a path), and purged after 30 days by `leads:purge-import-files`. The report survives the purge; the raw row snapshots go with the file (SEC-FILE-01/02, SEC-PII-05). Rejected rows keep their source data because that is what the operator needs to fix the file — imported rows do not, since they are already in `leads`.

An import report is readable only by its uploader, or by a user at `All` scope. Team scope is deliberately not enough: the rejected rows are somebody else's uploaded PII (SEC-AUTHZ-04).

### One deviation, recorded ⚠️
**SEC-FILE-01 asks for mime-type validation on uploads; the import validates extension and size only.** Exported CSVs arrive as `text/csv`, `text/plain`, `application/vnd.ms-excel` or `application/octet-stream` depending on browser and OS, so a mime allowlist either rejects valid files or is loose enough to prove nothing. The control's actual purpose — stopping uploaded content from being served or executed — is met structurally instead: a generated filename on a private disk outside the web root, read only with `fgetcsv`, never served. Flagging it rather than quietly claiming the control.

### Known limitation
**CSV/TSV only.** `.xlsx` is a ZIP of XML and needs a real spreadsheet library, which is not yet a dependency — PhpSpreadsheet is memory-hungry and shared hosting is the current target, so it is a decision rather than an oversight. Tracked as **T-45**; the reader is isolated behind `App\Support\CsvReader` so adding a format touches one class.

### Added
- Tables `lead_imports`, `lead_import_rows` (DATABASE_SCHEMA §2.15–2.16). Rollback verified.
- `App\Support\CsvReader`, `App\Support\LeadColumnMap`, `App\Services\Leads\LeadImportService`.
- Jobs `ProcessLeadImport`, `ImportLeadRow` (queue: `imports`). Enums `ImportStatus`, `ImportRowStatus`.
- `LeadImportPolicy`, `PurgeLeadImportFilesCommand` + a daily schedule entry — **which requires the scheduler cron from DEPLOYMENT §6 to exist, or the retention job silently never runs**.
- `config/crm.php` → `imports` block (disk, size/row limits, chunk size, retention).

### Endpoints
`POST /leads/import` · `GET /leads/imports` · `GET /leads/imports/{id}` · `GET /leads/imports/{id}/rows`

---

## Phase 6 — Leads (core) — 2026-08-10

The heart of the CRM. 13 endpoints, 63 new tests.

### Phone normalisation is the foundation
`App\Support\PhoneNumber` collapses every input format to E.164 — `9876543210`, `09876543210`, `919876543210`, `+91 98765-43210`, `(+91) 98765.43210` all become `+919876543210`. This is the lead identity key, so a test asserts the *property* that matters: every variant of one number produces one identical string. Without that, duplicate detection fails silently and the same person gets called by three telecallers.

Invalid input returns `null` rather than throwing, so an import can mark a row bad and carry on instead of aborting the batch.

### Duplicate detection (BR-DUP-01/02)
Enforced at three levels: a unique DB index, a service-layer check, and request validation. The `409` response carries the existing lead's id, name, archived state, and **current owner** — the caller needs to know who already has the relationship, not just that they were refused (BR-ASSIGN-05).

### Assignment (BR-ASSIGN-01..06)
Manual, round-robin and load-balanced strategies, selected by config. Two clarifications fell out of implementation:
- **Closed leads don't count toward load.** Converted/Lost/Not Interested are finished work; counting them would starve a productive agent of new leads.
- **"Can work leads" means `leads.update`, not `leads.view`.** Almost every role can view — Accounts and Viewer included — so a view check would park leads with read-only users.

When nobody is eligible the lead stays unassigned with a timeline entry, rather than being forced onto an overloaded agent: an unassigned lead in a queue is recoverable, one buried in an overloaded list is not.

### Design flaw caught by the test suite ⚠️
Unassigned leads were **invisible at Team scope**, which deadlocked assignment: a manager could not assign a lead they were not permitted to see, so a newly created unowned lead was unreachable by everyone.

Fixed by treating unassigned leads as a **shared pool** visible at Team and All scope — but not at Own scope, since the pool is not a telecaller's to work until assigned. Recorded as **BR-ASSIGN-06**. The fix had to be applied in two places that must agree: the query scope (what appears in a list) and the policy (what can be fetched by id).

### Two authorisation layers, both required
The permission middleware asks "may this user touch leads?"; `LeadPolicy` asks "may they touch *this* lead?". A test asserts a telecaller gets `403` when requesting a colleague's lead by id — with only the middleware, that request succeeds, since the telecaller legitimately holds `leads.view`. Data scope is also applied **before** client filters, so `?filter[assigned_to]=<someone else>` returns nothing rather than their leads.

### Also fixed
- `AuthorizesRequests` restored to the base controller — Laravel 11+ ships it bare, and `$this->authorize()` is mandatory here.
- Permission checks now use `loadMissing`, so they work from services and queued jobs where the model was not eager-loaded (`preventLazyLoading` would otherwise throw).
- `preventSilentlyDiscardingAttributes` earned its place: it caught the `phone` key being passed to `Lead::create()` where the column is `phone_e164`. Without it, the value would have been silently dropped.

### Endpoints
`GET|POST /leads` · `GET|PATCH|DELETE /leads/{id}` · `POST /leads/{id}/restore` · `GET|POST /leads/{id}/notes` · `GET /leads/{id}/timeline` · `POST /leads/{id}/assign|unassign|auto-assign` · `GET /leads/{id}/assignments` · `GET /leads/assignees`

### Tests — 200 passed, 0 failed (720 assertions), +63 this phase

### Still outstanding in Phase 6
**FR-LEAD-07 — CSV/Excel import.** Deliberately not rushed: it needs upload validation, a queued per-row job, and a per-row result report (imported / duplicate / invalid). Next piece of work before Phase 7.

---

## Phase 5 — Products — 2026-08-10

Small phase. CRUD for the seven products, permission-gated, using the Phase 3 list conventions.

### Endpoints
`GET /products` · `GET /products/{id}` · `POST /products` · `PATCH /products/{id}` · `DELETE /products/{id}` · `POST /products/{id}/restore`

Read requires `products.view` (broad — product names appear throughout the CRM); writes require `products.manage` (Admin+).

### Key decision: delete archives, never destroys
`lead_products` holds a RESTRICT foreign key, so erasing a product would either fail outright or orphan interest history and silently corrupt product-performance reporting. `DELETE` soft-deletes **and** sets `is_active = false`, so anything filtering on active status — dropdowns, campaign targeting — excludes it too. A test asserts that a product with lead interest keeps that interest after archiving.

A duplicate `code` returns a clean `409` with `existing_product_id`, and says when the conflict is with an **archived** product — otherwise the caller is told something "already exists" that they cannot find anywhere in the UI.

### Tooling fix
PHP files written via PowerShell `Set-Content` carried a **UTF-8 BOM**, which had been silently present in migrations since Phase 2 (harmless there, but it emitted stray bytes into `artisan` output). It broke outright when a bulk edit touched test classes: a BOM before `<?php` makes PHP report "Namespace declaration statement has to be the very first statement". Stripped from all 19 affected files; noted so it does not recur.

### Tests — 137 passed, 0 failed (596 assertions), +20 this phase

### Database changes
None — the `products` table was created in Phase 2.

---

## Phase 4 — Authentication + Roles — 2026-08-10

Built on the proposed 6-role model (T-08). Roles and permissions are **seeded data, not code**, so the model can still change without a migration.

### RBAC
- **6 roles** (ROLE-01..06) and **41 permissions** across 11 modules, seeded idempotently. Re-running the seeder adds a phase's new permissions to the right roles and **never un-assigns a role from a person** — it must be safe to run on production.
- **Permission ≠ scope.** `App\Enums\Permission` answers "may this user list leads?"; `App\Enums\DataScope` answers "*which* leads?". Conflating the two is how a telecaller ends up reading the whole database through a legitimate endpoint.
- Data scoping enforced server-side by query constraint: Telecaller → own, Manager → team, Admin/Accounts/Viewer → all. Never derived from a client-supplied filter, which would let a caller widen their own scope.
- A user with **no roles** gets no permissions and the narrowest scope — an unconfigured account sees nothing, not everything.
- Super Admin bypasses permission checks; **Admin does not**. Admin is bound by its granted set, so a seeder gap surfaces as a 403 instead of silently granting access. Admin also cannot manage roles or provider credentials (SEC-AUTHZ-05).

### Authentication
- Sanctum login/logout/me/change-password, `logout-all` for lost devices.
- **Logout revokes only the presenting token** (SEC-AUTH-04) — signing out on a phone must not sign the user out at their desk.
- Invalid credentials return an **identical message** whether or not the account exists. A different message is a user-enumeration oracle.
- Changing a password invalidates every other session, since a change usually means the old password may be compromised.
- Login throttled per IP **and** per submitted email, so rotating IPs cannot brute-force one account and one IP cannot spray many (SEC-AUTH-03).

### Attendance and audit
- Login **opens a work session** (FR-ATT-01) inside the same service and transaction as token issue. Keeping them together means a future login path (SSO, mobile) cannot skip attendance and quietly corrupt telecaller reports. Repeated logins reuse the open session rather than stacking — otherwise web + Android would double-count logged-in time.
- Audited: login, failed login, blocked login, logout, logout-all, password change/failure (SEC-AUD-02). A test asserts the submitted password never reaches the audit log.
- **Sensitive permissions are audited when used, not only when refused** — `leads.export`, `recordings.listen`, `dnc.remove`, `payments.refund` and others. Bulk export of a lead database is the highest-value insider action in a CRM, so legitimate use is recorded too. Ordinary permissions are not audited, to keep the signal readable.

### First admin
`php artisan crm:create-user --role=super_admin` rather than a seeded admin. A seeded admin means a known default password in version control on every install — the most common way small deployments get breached.

### Endpoints
`POST /auth/login` · `GET /auth/me` · `POST /auth/logout` · `POST /auth/logout-all` · `POST /auth/change-password`

### Tests — 117 passed, 0 failed (531 assertions), +31 this phase
Per-role permission-denial matrix, scope isolation across three roles, work-session behaviour, audit assertions, throttling. Verified live over HTTP as well as in the suite.

### Database changes
`teams`, `roles`, `permissions`, `permission_role`, `role_user`; FK added for `users.team_id`.

---

## Phase 3 — Laravel API Foundation — 2026-08-10

The contract layer both clients depend on. No business endpoints yet — this phase makes sure that when they arrive, they cannot drift.

### Response envelope
- **`App\Support\ApiResponse`** is the only place a response body is constructed (NFR-03). Success, created, accepted, no-content, paginated and error all route through it.
- Empty payloads serialise as `{}`, not `[]` — a client typed against an object breaks on an array.
- Pagination metadata always sits at `data.meta`, so every list response parses identically.

### Error handling
- **`App\Enums\ErrorCode`** implements the API_DOCUMENTATION §6 catalogue: 21 stable, machine-readable codes. Each code **owns its HTTP status**, so `dnc.suppressed` can never be returned with a 500.
- Exception handler in `bootstrap/app.php` maps validation, auth, authorization, model-missing, throttling and unhandled errors into the envelope. Web routes are untouched.
- **Internals never reach the client** (NFR-08, SEC-OPS-03): production returns a generic message while the real detail goes to logs, correlated by request. Covered by a test that asserts a SQL fragment cannot appear in a response body.
- Domain exceptions (`ApiException`, `DncSuppressedException`, `InvalidStatusTransitionException`) carry context to the client — an invalid status transition returns *which transitions are allowed*, so the UI can offer only legal options instead of guessing.

### Bug caught by the test suite ⚠️
The exception handler rewrote **`HttpResponseException`** as a blank `500`. That class wraps an already-built response, carries an empty message, and does **not** implement `HttpExceptionInterface` — so it fell through to the default branch and the real response was discarded.

Surfaced via rate limiting (a throttle limiter with a custom response callback throws one), but the blast radius was wider: any `abort()` with a response, and some form-request paths, would have returned a blank 500 instead of their intended response. Fixed by passing these through untouched.

### Middleware
- `ForceJsonResponse` — API routes are always JSON. Without it, a request missing `Accept: application/json` gets HTML error pages or a redirect to a login route; the Flutter app has no login page to redirect to.
- `AssignCorrelationId` — `X-Correlation-ID` on every response, pushed into log context, inbound values preserved so a client or load balancer can originate the trace (ARCHITECTURE §11).

### Routing, rate limiting, list conventions
- Versioned routing: all project endpoints under **`/api/v1`** (NFR-02). `GET /api/v1/health` implemented — unauthenticated, no business data, `503` when a dependency is down.
- Four named rate limiters (NFR-07). Auth is limited **per IP *and* per submitted email**, so rotating IPs cannot brute-force one account and one IP cannot spray many (SEC-AUTH-03). Webhook limits are deliberately generous — throttling a provider's valid retry loses delivery receipts and inbound leads (SEC-WH-05).
- **`App\Support\QueryOptions`** implements the documented list conventions: `filter[field][operator]`, `sort=-field`, `include`, `per_page` (capped, not rejected). Unknown fields return **422 rather than being ignored** — a silently dropped filter returns more rows than requested, which on a scoped lead list is data exposure, not a cosmetic bug.

### Configuration
- **`config/crm.php`** holds every tunable business rule (calling hours, frequency caps, assignment method and cap, AI confidence, discount threshold, idle threshold, retention, rate limits). This is the only layer that calls `env()` — after `config:cache` runs in production, `env()` returns null everywhere else, a bug that appears only after deployment.
- Models now use `preventLazyLoading` and `preventSilentlyDiscardingAttributes` outside production. The second matters most: without it, an update targeting a guarded field (`status`, `score`, `is_suppressed`) is silently dropped and looks successful — exactly the fields protected by SEC-IN-06.

### Tests — 86 passed, 0 failed (400 assertions), +28 this phase
Envelope contract, health/versioning/correlation, query conventions, rate limiting. Verified against a live server as well as the suite.

---

## Phase 2 — Database — 2026-08-10

First code phase. Laravel installed, schema built and verified against a real MySQL instance.

### Environment
- Installed Composer 2.10.2 (SHA-256 verified, kept local at `tools/composer.phar` — no system PATH changes) and **MySQL 8.4.9**. Node 25.8, PHP 8.2.30 and Git were already present.
- MySQL runs as a **user-level process**, not a Windows service — the session had no administrator rights. The elevated one-liner to register it as a service is in DEPLOYMENT.md §1.
- Created `marketing_crm` and `marketing_crm_test` databases plus a least-privilege `crm_user` (SEC-OPS-04). Strict `sql_mode` and slow-query logging enabled so truncation bugs and missing indexes surface in development rather than production.

### Application
- **Laravel 12.65.0** scaffolded in `backend/`, Sanctum 4.3.3 installed, `/api` routing enabled.
- Git repository initialised. `.gitignore` written **before** the first `git add`, and verified: only `.env.example` is tracked, never `.env`. Recordings, imports and logs are excluded as PII (SEC-CFG-02).
- `.env` carries every business-rule threshold as configuration (calling hours, frequency caps, AI confidence, discount threshold, idle threshold), so tuning them never requires a deployment (BR-DNC-04).

### Schema — 28 migrations, rollback verified
`users` · `products` · `lead_sources` · `tags` · `leads` · `lead_products` · `lead_tag` · `lead_notes` · `lead_status_history` · `lead_assignments` · `lead_activities` · `dnc_entries` · `calls` · `call_recordings` · `follow_ups` · `templates` · `campaigns` · `messages` · `campaign_recipients` · `provider_webhook_logs` · `user_work_sessions` · `user_activity_pings` · `notifications` · `customers` · `customer_leads` · `audit_logs` (+ Laravel's cache/jobs/sessions/tokens).

Sales, opportunities and payments are deliberately **deferred to Phases 22–23**, where their rules are finalised — building them now would invite rework while BR-SALE/BR-PAY details are still proposed.

### Bug caught by the test suite ⚠️
`tenant_id` was specified as **nullable** in Phase 1 (ADR-C). Implementation testing proved that unsafe: in SQL `NULL != NULL`, so every composite unique index containing it was **silently inert** — `leads(tenant_id, phone_e164)`, `products(tenant_id, code)`, `tags`, `templates`, `lead_sources`. Duplicate leads would have been accepted in production while the schema appeared to forbid them, defeating BR-DUP-01/02 and the entire point of enforcing duplicate detection at the database level.

Fixed to **`NOT NULL DEFAULT 0`** across all 15 affected tables (`0` = default tenant; real IDs start at 1 in Phase 36). ADR-C amended in ARCHITECTURE.md, with a regression test that fails if a future migration makes the column nullable again.

### Code
- **6 enums** carrying the business rules: `LeadStatus` (11 states + the full transition matrix), `CallStatus` (11 outcomes + auto-suppression/follow-up triggers), `DncReason` (reason × channel matrix), `Channel`, `LeadTemperature` (derivation with recency as a gate), `PaymentStatus`.
- **21 models** with relationships, casts, and scopes. Business-critical fields (`status`, `score`, `is_suppressed`, `assigned_to`) are excluded from mass assignment (SEC-IN-06).
- Factories for Lead/Product/DncEntry with meaningful states; idempotent seeders for the 7 real products and 8 lead sources — safe to re-run against production, and verified by running twice.

### Tests — 58 passed, 0 failed (298 assertions)
Suite runs against **MySQL, not SQLite in-memory**: the tests assert FK `RESTRICT` and composite-unique behaviour SQLite does not reproduce. Full breakdown in TESTING.md §7.

### Deployment target
Hosting confirmed as **Hostinger shared hosting**. DEPLOYMENT.md §3A documents what that constrains: no Redis, no Supervisor, cron-driven queue workers instead of daemons, and — the honest limitation — bulk campaigns that a VPS clears in minutes will take hours. Queue/cache/session drivers set to `database` so the same code runs on either platform; moving to a VPS is a `.env` change, not a rewrite. **No business logic, API, or schema decision changed because of this.**

---

## Phase 1 — revision 1.2 — 2026-08-10

Gap-closing pass. Six concrete holes were found by reviewing the docs against the phases that will consume them — each would have caused rework rather than merely reading thin.

### Added — two new documents
- **GLOSSARY.md** — entity definitions and **metric formulas**. The brief named metrics ("conversion rate", "talk time", "idle time") without defining them; two people would have computed different dashboard numbers. Every metric now has exactly one formula. Notable resolutions: Average Call Duration divides by *connected* calls (not total attempts, which would punish agents facing unreachable numbers); Revenue (collected) and Booked Value are separate and never summed; the headline conversion rate is cohort-based.
- **DEPLOYMENT.md** — stack versions, environments, full env-var list, Supervisor worker groups, deploy sequence, rollback, backups, monitoring, and a pre-production checklist. Flags `php artisan queue:restart` as mandatory on every deploy — without it, workers keep running old code while the web tier serves new code.

### Added — schema tables that nothing supported
- `user_work_sessions` + `user_activity_pings` — FR-RPT-01 required active/idle/office-hours time, but no table could produce it. **This was a Phase 2 schema gap**, not a Phase 26 one.
- `customers` + `customer_leads` — the lead→customer relationship was never defined. Resolved as BR-CUST-01: separate, permanently linked records; the lead survives conversion so its history and source attribution stay intact.
- `notifications` — follow-up reminders were required to "fire" with no delivery mechanism defined.
- `lead_assignments` — promoted from a name in a list to a specified table; it is the attribution source for telecaller reporting.

### Added — rules that were missing
- **BR-ASSIGN-01..05** — how leads actually get assigned (manual / round-robin / load-balanced / campaign rule), eligibility caps, and the rule that repeat enquiries route to the existing owner so two agents never call the same person.
- **BR-NOTIF-01..04** — internal notifications, explicitly **never** DNC-filtered (suppression protects leads, not staff).
- **BR-CUST-01..04** — customer creation and deduplication.
- **FR-LEAD-10/11, FR-NOTIF-01..03, FR-ATT-01..04, FR-FUP-05, FR-RPT-06** — matching requirements with acceptance criteria.

### Flagged — needs a business decision, not a technical one
- **Attribution on reassignment** (GLOSSARY §2.6): when a lead changes owner, who gets conversion and revenue credit? This directly affects telecaller pay and cannot be inferred from the brief.

---

## Phase 1 — revision 1.1 — 2026-08-10

Documentation hardening pass. All ten documents rewritten with version headers, stable IDs, and cross-links so requirements, rules, schema, tests, and status all reference each other by ID rather than by prose.

### Added — traceability
- **Requirement IDs** in PROJECT_REQUIREMENTS (`FR-*`, `NFR-*`, `ROLE-*`, `OUT-*`) with acceptance criteria on every requirement.
- **Rule IDs** in BUSINESS_RULES (`BR-*`), **control IDs** in SECURITY (`SEC-*`), **ADR IDs** in ARCHITECTURE (`ADR-A..E`).
- Tests now required to cite the IDs they prove (TESTING §3); MODULE_STATUS tracks requirements-covered per phase.

### Added — specification detail that was missing
- **Roles**: 6-role model proposed (Super Admin, Admin, Manager, Telecaller, Accounts, Viewer) with data scope — the brief required RBAC but named no roles.
- **Lead status transition matrix** (BR-STAT-02): full 11×11 grid, `Converted` terminal, Manager-only reopens.
- **DNC reason × channel matrix** (BR-DNC-02): suppression is per-reason-per-channel, so a wrong phone number no longer blocks a valid email.
- **Lead scoring model and temperature bands** (BR-SCORE-01, BR-TEMP-02) with recency as a gate, not a bonus.
- **Payment transition matrix** (BR-PAY-02); conversion requires a real payment (BR-PAY-05).
- **Queue topology** (ARCH §4): 8 named queues, campaign fan-out to per-recipient jobs, job batching for pause/resume/stop.
- **Provider resilience policy** (ARCH §5): timeouts, backoff with jitter, circuit breaking, rate limits, credential redaction.
- **Webhook pipeline** (ARCH §6): verify → persist raw → dedupe → ACK → process async, with idempotency on provider event ID.
- **Core table specifications** (DATABASE_SCHEMA §2): `leads`, `lead_products`, `lead_status_history`, `dnc_entries`, `calls`, `call_recordings`, `messages`, `campaigns`, `provider_webhook_logs` — columns, indexes, and the unique constraints that make duplicate leads and double-sends structurally impossible.
- **API conventions** (API_DOCUMENTATION): pagination/filter/sort params, HTTP status usage, a stable error-code catalogue, idempotency header, `202` semantics for async work.
- **Security expansion** (SECURITY): PII handling for recordings/transcripts, IDOR and insider-exfiltration threats, webhook forgery/replay, audit event list, threat table.
- **Test strategy** (TESTING): mandatory critical-rule suites — the DNC matrix alone requires one test per outbound channel — plus fixtures/doubles policy and CI gates.

### Added — new requirements found while reviewing
- FR-META-03 webhook idempotency · FR-RPT-05 report performance budget · BR-CAMP-04 frequency capping · BR-CALL-04 calling hours · BR-DUP-04 merge unions suppression.

### Noted — sequencing concern
- The roadmap places the DNC Engine (Phase 19) *after* the channels (13–17) that must call it. Recommended building `DncService` early with Phases 6/7 and keeping Phase 19 for policy config, admin UI, and the full test matrix. **Not implemented — awaiting decision.**

### Open
- 25 items now tracked in TODO.md, grouped by when an answer is needed. ADR-B (calling architecture) is the highest-impact open decision; the role model and BR-STAT-02 matrix block Phases 4 and 7 respectively.

---

## Phase 1 — 2026-08-10

- Analyzed the master project brief; no hard architectural contradictions found.
- Recorded 4 open architecture decisions with proposed defaults: Web CRM delivery model, human calling mechanism, multi-tenancy timing, unnamed communication vendors.
- Created `/docs` with all ten required documents.
- No application code, database, or Laravel installation yet — that begins Phase 2/3.
