# Business Rules

| | |
|---|---|
| **Version** | 1.1 |
| **Last updated** | 2026-08-10 (Phase 1) |
| **Status** | Matrices below are *proposed* — they encode intent not stated in the brief and need sign-off before Phase 7 |
| **Related** | [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [TESTING.md](TESTING.md) |

This file is the canonical statement of business rules — not code comments. Each rule has a stable ID (`BR-*`) cited by tests. A rule that lives here is implemented in exactly one service and never re-implemented per module.

---

## 1. DNC / Suppression *(critical — Section 13)*

### BR-DNC-01 — Single gate
`App\Services\Dnc\DncService::canContact(lead, channel, context)` is the **only** authority on contactability. Every outbound path calls it: individual send, bulk campaign job, scheduled campaign, human call trigger, auto dialer next-lead, AI calling. No channel module, campaign module, or dialer may re-implement or bypass it. ([ADR-E](ARCHITECTURE.md#adr-e--centralized-dnc-gate))

### BR-DNC-02 — Suppression reasons and channel scope *(proposed)*

Suppression is **reason × channel**, not a single global flag — a wrong phone number should not block a valid email address.

| Reason | Call | AI Call | SMS | WhatsApp | RCS | Voice | Email | Notes |
|--------|:----:|:-------:|:---:|:--------:|:---:|:-----:|:-----:|-------|
| **Do Not Contact** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | Absolute. Blocks everything, all contexts. |
| **Not Interested** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | Blocks all outbound by default; scope is config-driven (BR-DNC-04). |
| **Opted Out** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | Recorded **per channel**; opting out of SMS blocks SMS. A global opt-out blocks all. |
| **Wrong Number** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | Phone is wrong, email may still be valid. |
| **Invalid Number** | ✗ | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | Same as above; distinct reason for reporting. |
| **Bounced Email** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ | Hard bounce suppresses email only. |

✗ = suppressed, ✓ = still contactable.

### BR-DNC-03 — Suppression is checked at dispatch time, not audience-build time
A lead suppressed *after* a campaign audience was built must still be blocked when its send job runs. The gate is called inside the per-recipient job, not only during audience selection.

### BR-DNC-04 — Policy is configuration, not code *(honoured in part, by decision — T-65, 2026-08-12)*
The reason × channel matrix above is stored as configuration/data. Changing whether "Not Interested" blocks manual human calls must not require a code change in any channel module.

> **Decision:** the matrix is split by what a reason *means*, not by convenience.
>
> | Reason | Editable at runtime? | Why |
> |---|:--:|---|
> | `Do Not Contact`, `Opted Out` | **No** | The person's own explicit instruction. Absolute, in code |
> | `Not Interested`, `Wrong Number`, `Invalid Number`, `Bounced Email` | **Yes** | Our inference from an outcome — exactly what an operator should tune without a deploy |
>
> The rule as literally written would make **"stop contacting me" a toggle**: one settings write,
> with no code review anywhere in the path, could restart the dialer on somebody who opted out.
> The tunable half satisfies what BR-DNC-04 is *for* — policy changes without a deploy, including
> the rule's own worked example of "Not Interested" and manual calls.
>
> `config/crm.php` holds the defaults, the `settings` table overrides them, and
> `App\Services\Dnc\SuppressionMatrix` resolves the two. Absolute reasons are not offered by the
> settings API at all, and the allowlist refuses their keys even if named directly.
>
> **Malformed overrides widen, never narrow.** An unknown channel discards the whole override and
> falls back to the built-in list, logging loudly — falling back to the part that parsed would
> silently unblock whichever channel was mistyped.

### BR-DNC-05 — Skips are logged, never silent
Every suppressed contact attempt writes a skip record with lead, channel, reason, and campaign context. "Nothing happened" is never an acceptable outcome (FR-CAMP-03).

### BR-DNC-06 — Suppression changes are audited
Adding or removing suppression records actor, reason, timestamp, and source (manual, webhook, call outcome). Removal requires Manager+ (see BR-STAT-05).

### BR-DNC-07 — Automatic suppression triggers
These outcomes automatically write suppression, without a separate manual step:
- Lead status → `Not Interested`
- Call status → `Wrong Number` or `Invalid Number`
- Inbound opt-out keyword (e.g. STOP) on SMS/WhatsApp → channel opt-out *(not built — needs an inbound path)*
- Hard bounce webhook from email provider → email suppression *(built 2026-08-12 — T-54)*

> **On "hard":** only a bounce the provider confirms is permanent suppresses. An unqualified
> `bounce` is recorded and left contactable — a full mailbox is temporary, and the cost of
> wrongly suppressing (a real customer never emailed again, liftable only by Manager+) is far
> higher than the cost of one more send to a dead address.
>
> An **unsubscribe or spam complaint** also suppresses, scoped to email. `OptedOut` is otherwise
> absolute, and inferring "stop calling me" from a newsletter unsubscribe claims more than the
> click said.

---

## 2. Lead Status

### BR-STAT-01 — Allowed statuses
New, Contacted, Interested, Follow-up, Callback, Proposal, Negotiation, Decision Pending, Converted, Lost, Not Interested. Any other value is rejected with `422`.

### BR-STAT-02 — Transition matrix *(signed off 2026-08-10 — T-15)*

Rows = current status, columns = target. ✓ = allowed, — = rejected with `422`.

| From \ To | New | Contacted | Interested | Follow-up | Callback | Proposal | Negotiation | Decision Pending | Converted | Lost | Not Interested |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| **New** | — | ✓ | ✓ | ✓ | ✓ | — | — | — | — | ✓ | ✓ |
| **Contacted** | — | — | ✓ | ✓ | ✓ | ✓ | — | ✓ | — | ✓ | ✓ |
| **Interested** | — | ✓ | — | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| **Follow-up** | — | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| **Callback** | — | ✓ | ✓ | ✓ | — | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| **Proposal** | — | — | ✓ | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Negotiation** | — | — | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ |
| **Decision Pending** | — | — | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ | ✓ |
| **Converted** | — | — | — | — | — | — | — | — | — | — | — |
| **Lost** | — | ⚑ | ⚑ | — | — | — | — | — | — | — | ⚑ |
| **Not Interested** | — | ⚑ | ⚑ | — | — | — | — | — | — | — | — |

⚑ = **reopen**, allowed to Manager+ only, and requires a reason. Reopening from `Not Interested` does **not** automatically clear suppression — that is a separate, separately-audited action (BR-DNC-06).

**Key constraints:**
- **New** is entry-only; nothing returns to New.
- **Converted is terminal.** Additional business with the same customer creates a *new opportunity*, never a status revert (BR-SALE-02).
- Skipping forward is permitted where it reflects reality (e.g. New → Interested on a first connected call); skipping *backward* into the pipeline is not.
- **Proposal** requires an existing Opportunity (BR-SALE-01); **Converted** requires at least one Sale with a non-failed payment (BR-PAY-05).

### BR-STAT-03 — Every change is logged
`lead_status_history` records from-status, to-status, actor, timestamp, source channel, and reason where required. Never overwritten, never deleted (FR-STAT-03).

### BR-STAT-04 — Lead status vs. product status
The lead-level status reflects the **furthest-advanced** state across the lead's products. Per-product interest is tracked independently (BR-PROD-01) — a lead may be `Negotiation` overall while one product sits at `Not Interested`.

**Clarified at Phase 7.** As originally written this rule said the lead status *is* derived from its products, which cannot both be true and leave BR-STAT-02's matrix and BR-STAT-05's authority rules meaning anything — and would leave every lead with no products (an imported list, for instance) with no status at all. The reading in force:

> **The lead-level status is authoritative and set directly. Product interest can pull it forward, never push it back.**

- When a product moves further along the pipeline than the lead, the lead advances to match, recorded as a **system** change with a null actor — nobody chose it, so nobody is credited for it (GLOSSARY §2.6).
- A less-advanced product never pulls the lead back.
- A **closed** lead (`Converted`, `Lost`, `Not Interested`) is never reopened by a product edit. Reopening is a supervisory act requiring a manager and a written reason (BR-STAT-02); a side effect of recording interest is not that.
- Nothing auto-converts: `Converted` requires a sale (BR-PAY-05) and is terminal, so an automatic one could not be undone.

Ranking used for "furthest advanced": New → Contacted → Follow-up / Callback *(equal rank)* → Interested → Proposal → Negotiation → Decision Pending → Converted. `Lost` and `Not Interested` are **off the ladder**, not behind `New`.

### BR-STAT-05 — Authority
Telecallers may set any status except reopen (⚑) transitions and `Converted`. `Converted` requires a linked Sale. Reopens require Manager+.

---

## 3. Product Interest

### BR-PROD-01 — Independent per-product state
Each lead–product pair carries its own interest status and temperature. Changing product A never mutates product B (FR-STAT-05).

### BR-PROD-02 — Multi-product leads
A lead may hold any number of products simultaneously with different states (e.g. News Portal = Interested, Epaper = Warm, News Posting = Hot).

### BR-PROD-03 — Product-level suppression
Marking one product `Not Interested` suppresses *that product's* campaigns for the lead, but does not trigger lead-level DNC. Lead-level DNC requires lead-level `Not Interested` or explicit Do Not Contact.

---

## 4. Lead Temperature & Scoring

### BR-TEMP-01 — Temperature is derived, never freely typed
Hot / Warm / Cold / Dormant is computed by the Interest Engine from score + recency. Manual override, if enabled, is time-boxed and audited.

### BR-SCORE-01 — Score model *(proposed — needs sign-off)*
Lead score is 0–100, recomputed on every signal. Transparent and explainable — a telecaller must be able to see *why* a lead is Hot.

| Signal | Points |
|--------|:------:|
| Connected call | +10 |
| Explicit interest stated (any channel) | +25 |
| AI call interest detected (weighted by AI confidence) | +15 × confidence |
| Inbound reply (WhatsApp/SMS/RCS) | +15 |
| Email opened / link clicked | +3 / +8 |
| Follow-up completed on time | +5 |
| Proposal sent | +20 |
| Negotiation entered | +25 |
| Callback requested | +10 |
| No answer / not connected | −2 (max −10 cumulative) |
| Follow-up missed | −5 |
| Not interested (product) | −20 |
| Decay | −5 per 7 days with no engagement |

### BR-TEMP-02 — Temperature bands *(proposed)*

| Temperature | Condition |
|-------------|-----------|
| **Hot** | score ≥ 70 **and** engagement within 7 days |
| **Warm** | score 40–69 **and** engagement within 30 days |
| **Cold** | score < 40, or no engagement for 30–90 days |
| **Dormant** | no engagement for > 90 days, or suppressed |

Recency is a gate, not a bonus: a high-scoring lead with no contact in 60 days is not Hot.

---

## 5. Interested Lead Engine

### BR-INT-01 — Single engine, eight sources
Human call, AI call, WhatsApp, Email, SMS, RCS, Voice, and manual CRM action all route into `App\Services\InterestEngine`. No channel handler implements its own interest logic.

### BR-INT-02 — Atomic effect set
On an interest signal, all seven effects commit in **one transaction** — partial application is impossible (FR-INT-02):
1. Update lead status 2. Update product interest 3. Apply label 4. Recalculate score
5. Add/update Interested Leads entry 6. Recalculate temperature 7. Create follow-up if configured

### BR-INT-03 — Evidence is retained
Every interest signal stores its source, channel, actor (human or AI), and evidence reference (call ID, message ID, transcript excerpt). AI-detected interest additionally stores the confidence score.

### BR-INT-04 — AI interest requires a confidence threshold
AI-detected interest below the configured confidence threshold is recorded as a *signal* but does not by itself move lead status. Threshold is configurable *(default proposed: 0.75)*.

---

## 6. Duplicate Detection

### BR-DUP-01 — Phone is the primary identity key
Phone numbers are normalised to E.164 before comparison and storage. Matching is done on the normalised value, not raw input.

### BR-DUP-02 — Behaviour on duplicate
A create/import matching an existing lead does not insert a second row. It is flagged as a duplicate and either rejected or merged into the existing lead's timeline, per import policy — never silently double-inserted (FR-LEAD-06).

### BR-DUP-03 — Secondary matching *(built 2026-08-12 — T-64)*
Email is a secondary signal: same email + different phone is flagged for review, not auto-merged.

> A shared address writes a row to `lead_duplicate_candidates` and does nothing else. Detection runs
> **after** creation and never blocks it: losing a real enquiry costs more than reviewing two records.
>
> Pairs are stored ordered (`lead_id < duplicate_lead_id`) so one question cannot become two rows,
> and **dismissals are kept** — "these are different people" is an answer, and re-asking it on every
> import is how a review queue becomes noise nobody reads. A `backfill()` sweep covers leads captured
> before detection existed, pairing every combination rather than only consecutive ones.

### BR-DUP-04 — Merge preserves history *(built 2026-08-12 — T-64)*
Merging retains both leads' notes, calls, messages, and status history. Suppression is **union** — if either record is suppressed, the merged lead is suppressed.

> **Union is achieved structurally.** The duplicate's `dnc_entries` are repointed to the survivor and
> the flag is then *recomputed* from the survivor's own rows — so the union is a consequence of the
> data moving, not an `OR` somebody has to remember to write. Two tests assert a merge can never
> un-suppress anybody, in either direction.
>
> **The duplicate is soft-deleted, never destroyed**, and carries `merged_into_id`. Without that
> pointer its phone number would hold the `unique(tenant_id, phone_e164)` index for ever, and a later
> enquiry from that number would resolve to a deleted row and stop.
>
> **A merge never changes the survivor's status.** Transitions are authorised and audited
> (BR-STAT-05); a merge quietly moving a lead to `Converted` would be a status change nobody chose.
> What the duplicate was is recorded on the timeline for a human to act on through the normal path.
>
> Rows with a unique `(x, lead_id)` — products, tags, campaign recipients, customer links, dialer
> queue items — keep the survivor's copy and drop the duplicate's: both assert the same fact.

---

## 6A. Lead Assignment

The brief requires assignment to be auditable but never states *how* leads get assigned. Proposed:

### BR-ASSIGN-01 — Assignment methods *(proposed)*
| Method | When used |
|--------|-----------|
| **Manual** | Admin/Manager assigns explicitly. Always available |
| **Round-robin** | Even distribution across eligible telecallers, in order |
| **Load-balanced** | Next lead goes to the telecaller with the fewest *open* leads — better than round-robin when agents work at different speeds |
| **Campaign rule** | Meta/campaign leads route by a rule on the form or campaign (e.g. product, city) |

*Default proposed: load-balanced for inbound/webhook leads, manual for imports.*

### BR-ASSIGN-02 — Eligibility for auto-assignment
Only active users, with the Telecaller role, currently within business hours, and below their open-lead cap *(proposed default: 150 open leads)*. If nobody is eligible, the lead stays unassigned in a queue and raises a notification — it is never dropped or force-assigned to an unavailable agent.

**Implemented Phase 6.** Two clarifications from implementation:
- **Closed leads do not count toward load.** Converted, Lost and Not Interested leads are finished work; counting them would starve a productive telecaller of new leads.
- **"Can work leads" means `leads.update`, not `leads.view`.** Nearly every role can view leads (Accounts and Viewer included), so checking view would happily park a lead with a read-only user who cannot action it.

### BR-ASSIGN-06 — Unassigned leads are a shared pool *(added Phase 6)*
Leads with no owner are visible at **Team and All scope**, not just All. Excluding them deadlocks the system: a manager cannot assign a lead they are not permitted to see, so a newly created unowned lead would be unreachable by everyone. Telecallers (Own scope) do **not** see the pool — it is not theirs to work until assigned.

### BR-ASSIGN-03 — Full history retained
Every assignment and reassignment writes `lead_assignments`. The current owner on `leads` is a denormalised convenience; the history table is authoritative and is the source for attribution ([GLOSSARY §2.6](GLOSSARY.md#26--attribution--needs-a-business-decision-)).

### BR-ASSIGN-04 — Reassignment preserves work
Reassignment never deletes the previous owner's calls, notes, or follow-ups. Open follow-ups transfer to the new owner *(proposed)* and the new owner is notified.

### BR-ASSIGN-05 — Duplicate leads keep the original owner
A repeat enquiry from an existing lead routes to the telecaller who already owns it, not to a new agent — otherwise two agents call the same person.

---

## 6B. Internal Notifications

Follow-up reminders were required but had no delivery mechanism defined.

### BR-NOTIF-01 — Notifications are internal only
`notifications` targets **CRM users**, not leads. It is unrelated to `messages` and is **never** subject to DNC — suppression protects leads, not staff.

### BR-NOTIF-02 — Triggering events *(proposed)*
Follow-up due / overdue · lead assigned to me · campaign completed or failed · payment overdue · recording upload failed · DNC removal requiring approval · discount approval requested.

### BR-NOTIF-03 — Channels
In-app is always written. Push (Android) and email are additional per user preference. A failed push never suppresses the in-app record — the notification must survive regardless of delivery channel.

### BR-NOTIF-04 — Reminder lead time
Follow-up reminders fire at a configurable lead time before due *(proposed: 15 minutes)*, plus once at due time.

---

## 6C. Customer Creation

See [GLOSSARY Part 1](GLOSSARY.md#part-1--entity-definitions) for the full definitions — **BR-CUST-01..04** live there because they are as much vocabulary as rule:

- **BR-CUST-01** — Lead and Customer are separate, permanently linked records. The lead is *not* converted in place; it survives with status `Converted` and the customer links back via `origin_lead_id`.
- **BR-CUST-02** — Billing identity (billing name, address, tax ID) belongs to the Customer, not the Lead.
- **BR-CUST-03** — Repeat business creates a new Opportunity under the existing Customer.
- **BR-CUST-04** — Customers are deduplicated on phone/email/tax ID before creation.

---

## 7. Campaigns

### BR-CAMP-01 — Eligibility
A lead enters a send queue only if: DNC gate passes for that channel (BR-DNC-01), a valid contact detail for that channel exists, and the lead is not archived. Failures are logged as skipped with reason (BR-DNC-05).

### BR-CAMP-02 — Re-check at dispatch
Eligibility is re-evaluated inside the per-recipient job (BR-DNC-03).

### BR-CAMP-03 — Bulk always queued
No campaign of any size sends inline in an HTTP request (FR-CAMP-05).

### BR-CAMP-04 — Frequency capping *(confirmed and built 2026-08-12)*
A lead may not receive more than *N* campaign messages per channel per rolling 24h, or *M* per week, across all campaigns. **Confirmed at 2/day, 5/week per channel.** Configurable; transactional/service messages exempt.

> **Rolling windows, not calendar ones.** A calendar-day cap lets two campaigns at 23:50 and 00:10
> land twenty minutes apart and both count as "one a day" — exactly the experience the cap exists
> to prevent.
>
> **The transactional exemption is structural**, not a flag: only messages carrying a `campaign_id`
> are counted. A payment receipt cannot consume somebody's marketing allowance, and a marketing
> send cannot hide behind being transactional. **Skipped messages do not count either** — a lead
> who was skipped yesterday did not receive anything, and capping them for it compounds one problem
> into two.
>
> A cap of zero means "no cap", not "block everything": a misconfiguration should not silently stop
> all marketing.

### BR-CAMP-05 — Lifecycle semantics
`Pause` stops new dispatch; jobs already handed to the provider complete and are recorded. `Stop` is terminal — a stopped campaign cannot be resumed, only cloned.

---

## 8. Calling & Auto Dialer

### BR-CALL-01 — DNC before every dial
No dial intent is created for a suppressed lead (FR-CALL-08).

### BR-CALL-02 — Auto dialer skip rules
A lead is skipped (and logged) when: suppressed, no valid phone, already contacted within the cooldown window, an open follow-up is scheduled for a later time, or it is outside permitted calling hours (BR-CALL-04).

### BR-CALL-03 — Single-assignment guarantee
Next-lead selection takes a lock so two telecallers can never be handed the same lead concurrently ([ARCHITECTURE §7](ARCHITECTURE.md#7-redis-usage)).

### BR-CALL-04 — Calling hours *(proposed)*
Outbound calling and voice campaigns run only within configured business hours in the lead's timezone. Attempts outside the window are deferred, not dropped. *Default proposed: 09:00–20:00 local.*

### BR-CALL-05 — Outcome-driven side effects
Call status drives automatic actions: `Wrong Number`/`Invalid Number` → suppression (BR-DNC-07); `Call Back Requested` → follow-up created; `Connected` → score increment.

---

## 9. Follow-ups

### BR-FUP-01 — One active follow-up per lead–product
A lead–product pair has at most one *open* follow-up. Creating another reschedules the existing one rather than duplicating it.

### BR-FUP-02 — Missed detection
A follow-up past its due time without completion is flagged `Missed` by the scheduler, is reportable, and decrements score (BR-SCORE-01).

### BR-FUP-03 — Reschedule preserves history
Rescheduling retains the prior scheduled time — history is append-only (FR-FUP-04).

---

## 10. Sales

### BR-SALE-01 — Opportunity precedes proposal
Reaching `Proposal` requires an Opportunity with at least one product and a value.

### BR-SALE-02 — Converted is terminal; repeat business is a new opportunity
See BR-STAT-02.

### BR-SALE-03 — Discount approval *(proposed)*
Discounts above the configured threshold require Manager+ approval before a quotation is issued. *Default proposed: 15%.* Approval is audited.

### BR-SALE-04 — Lost sales require a reason
A lost sale records a reason code, for reporting by reason.

---

## 11. Payments

### BR-PAY-01 — Status set
Pending, Partial, Paid, Failed, Overdue, Refund.

### BR-PAY-02 — Transition matrix *(proposed)*

| From \ To | Pending | Partial | Paid | Failed | Overdue | Refund |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| **Pending** | — | ✓ | ✓ | ✓ | ✓ | — |
| **Partial** | — | ✓ | ✓ | — | ✓ | ✓ |
| **Paid** | — | — | — | — | — | ✓ |
| **Failed** | ✓ | — | — | — | — | — |
| **Overdue** | — | ✓ | ✓ | ✓ | — | — |
| **Refund** | — | — | — | — | — | — |

`Partial → Partial` is allowed (successive instalments). `Refund` is terminal. `Failed → Pending` supports retry.

### BR-PAY-03 — No orphan payments
Every payment links to Lead + Customer + Product + Sale, enforced at both DB (FK, not null) and service level (FR-PAY-03).

### BR-PAY-04 — Balance is derived
Balance = sale value − sum of non-failed, non-refunded payments. Never manually editable (FR-PAY-05).

### BR-PAY-05 — Conversion requires money
A lead reaches `Converted` only when a linked Sale has at least one payment in `Partial` or `Paid`.

### BR-PAY-06 — Overdue is time-derived
A payment past its due date without full settlement becomes `Overdue` via scheduled job, not manual marking.

---

## 12. Call Recording

### BR-REC-01 — Access control
Recordings are never publicly addressable. Access is via signed, expiring URLs, restricted to the owning telecaller and Manager+ roles. Every access is logged.

### BR-REC-02 — Retention
Retention period is configurable; a scheduled job purges expired recordings and writes an audit entry. Indefinite retention is not the default (FR-REC-05).

### BR-REC-03 — Graceful degradation
Where the OS/device or applicable rules prevent recording, the call still logs normally without a recording. Absence of a recording is never an error state.

---

## 13. Rules Requiring Sign-off

These encode intent the brief did not specify. All are marked *(proposed)* above:

| Rule | Decision needed |
|------|-----------------|
| BR-DNC-02 | Reason × channel suppression matrix |
| BR-STAT-02 | Lead status transition matrix + reopen authority |
| BR-SCORE-01 / BR-TEMP-02 | Score model and temperature bands |
| BR-INT-04 | AI interest confidence threshold (proposed 0.75) |
| BR-CAMP-04 | Frequency caps (proposed 2/day, 5/week per channel) |
| BR-CALL-04 | Calling hours (proposed 09:00–20:00 local) |
| BR-SALE-03 | Discount approval threshold (proposed 15%) |
| BR-ASSIGN-01/02 | Assignment method + open-lead cap (proposed load-balanced, 150) |
| BR-ASSIGN-04 | Do open follow-ups transfer on reassignment? |
| BR-NOTIF-02/04 | Notification triggers and reminder lead time (proposed 15 min) |

Metric formulas and the attribution model need separate sign-off — see [GLOSSARY Part 4](GLOSSARY.md#part-4--open-decisions). All tracked in [TODO.md](TODO.md).
