# Glossary & Metric Definitions

| | |
|---|---|
| **Version** | 1.0 |
| **Last updated** | 2026-08-10 (Phase 1) |
| **Status** | Entity definitions agreed; **metric formulas proposed — need business sign-off before Phase 26** |
| **Related** | [BUSINESS_RULES.md](BUSINESS_RULES.md) · [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) · [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) |

Why this document exists: the brief names metrics like "conversion rate", "talk time", and "idle time" without defining them. Two people reading the same dashboard would compute different numbers. Every metric below has **one** formula, and reports implement that formula and nothing else.

---

## Part 1 — Entity Definitions

### Lead
A person or business that *might* buy. Created from manual entry, CSV import, or a Meta Lead Ad. Identified by normalised phone (`phone_e164`). Carries status, temperature, score, and per-product interest.

### Customer
A person or business that **has bought at least once**. A Customer is created at the moment of the first Sale, not before.

**BR-CUST-01 — Lead and Customer are separate records, permanently linked.**
The Lead is *not* converted-in-place into a Customer. The Lead record survives with status `Converted`; a `customers` row is created and linked back via `origin_lead_id`. Rationale: the lead's history (calls, campaigns, source attribution) must stay intact for reporting, and one customer may legitimately arrive from more than one lead.

**BR-CUST-02 — Customer holds billing identity, Lead holds marketing identity.**
Name, phone, email may be copied at creation, but billing name, address, GSTIN/tax ID, and payment history belong to the Customer and are edited there. Changing a lead's phone does not silently change invoicing data.

**BR-CUST-03 — One Customer, many Sales.**
Repeat business creates a new Opportunity → Sale under the *existing* Customer. It never reverts the original lead's `Converted` status (BR-STAT-02).

**BR-CUST-04 — Customer deduplication.**
Before creating a Customer, match on phone/email/GSTIN. A match links the new Sale to the existing Customer instead of creating a duplicate.

### Opportunity
A specific, quantified selling attempt: one Lead/Customer + one or more Products + a value. A lead may have several over time.

### Sale
A won Opportunity. Creates or links a Customer, and is the parent of Payments.

### Payment
Money actually requested or received against a Sale. Never exists without Lead + Customer + Product + Sale (BR-PAY-03).

### Contact attempt vs. Connected call
An **attempt** is any dial. A **connected call** is an attempt with call status `Connected`. Most metric errors come from confusing the two — the formulas below always say which.

### Engagement
Any inbound or two-way signal from the lead: connected call, reply on any channel, email open/click, form submission. One-way outbound sends are **not** engagement. Drives recency in BR-TEMP-02.

### Touch
A single outbound contact on any channel. Counted for frequency capping (BR-CAMP-04).

---

## Part 2 — Metric Definitions

### 2.1 Universal rules

| Rule | Definition |
|------|-----------|
| **Period** | All metrics are period-scoped. An event belongs to a period by its **event timestamp**, not the lead's creation date, unless the metric says otherwise |
| **Timezone** | Aggregated in the **organisation's** timezone (dashboards must be internally consistent), even though rows are stored UTC |
| **Denominator zero** | Rate metrics with a zero denominator display `—`, never `0%` (they are different facts) |
| **Excluded records** | Archived and test leads are excluded from every metric |
| **Attribution** | See §2.6 — this is a real business decision, not a technical detail |

### 2.2 Calling metrics

| Metric | Formula | Notes |
|--------|---------|-------|
| **Call Attempts** | count of `calls` rows in period | Every dial, regardless of outcome |
| **Connected Calls** | attempts where `status = Connected` | |
| **Connect Rate** | Connected Calls ÷ Call Attempts | Dialing efficiency |
| **Contact Rate** | distinct leads with ≥1 connected call ÷ distinct leads attempted | *Lead*-level, not call-level — different from Connect Rate |
| **Talk Time** | Σ `duration_seconds` of **connected** calls | Excludes ringing/failed attempts. This is the number telecallers are measured on |
| **Average Call Duration** | Talk Time ÷ **Connected Calls** | Divided by *connected*, never by total attempts — otherwise it silently punishes agents facing unreachable numbers |
| **Calls per Hour** | Call Attempts ÷ Logged-in Hours | Activity intensity |
| **Unique Leads Touched** | distinct `lead_id` in `calls` | |

### 2.3 Productivity / time metrics

The brief requires "active time", "idle time", and "office hours activity". These need explicit definitions and a table to support them (`user_work_sessions`, DATABASE_SCHEMA §2.10).

| Metric | Formula | Notes |
|--------|---------|-------|
| **Logged-in Time** | Σ duration of work sessions in period | Session opens at login, closes at logout/timeout |
| **Active Time** | Σ session time in which the user performed ≥1 tracked action within the idle threshold | Tracked actions: call, note, status change, message send, follow-up action |
| **Idle Time** | Logged-in Time − Active Time − Break Time | Not simply "not on a call" — a telecaller writing notes is working |
| **Break Time** | Σ explicitly marked breaks | Excluded from Idle so breaks don't read as slacking |
| **Occupancy** | Talk Time ÷ Logged-in Time | The "how much of the shift was on the phone" number |
| **Office-hours Activity** | Active Time falling inside configured business hours ÷ total business-hours duration | |
| **Idle threshold** | *Proposed: 5 minutes* without a tracked action ⇒ idle | Configurable |

### 2.4 Pipeline & conversion metrics

**"Conversion rate" is ambiguous** — the brief uses it without a denominator. Three distinct rates are defined; dashboards must label which one they show.

| Metric | Formula |
|--------|---------|
| **Interest Rate** | leads reaching `Interested` ÷ leads **contacted** in period |
| **Lead → Sale Conversion Rate** *(the headline number)* | leads reaching `Converted` ÷ leads **assigned** in period |
| **Opportunity Win Rate** | Sales won ÷ opportunities closed (won + lost) |
| **Proposal → Sale Rate** | Sales won ÷ proposals sent |
| **Average Sales Cycle** | mean(days from lead creation → `Converted`) |
| **Pipeline Value** | Σ value of open opportunities (not Converted/Lost) |
| **Loss Rate by Reason** | lost sales grouped by reason ÷ total lost |

**Cohort rule:** the headline conversion rate is **cohort-based** — of leads *assigned* in the period, how many eventually converted. It is not "conversions this month ÷ leads this month", which mixes two unrelated populations and produces nonsense during growth or slow months.

### 2.5 Revenue metrics

**Booked ≠ collected.** These are separate numbers and must never be summed together.

| Metric | Formula |
|--------|---------|
| **Booked Value (Sales Value)** | Σ value of Sales won in period |
| **Revenue (Collected)** | Σ payments with status `Paid` or `Partial`, by **payment date** |
| **Outstanding** | Σ (sale value − collected) for sales not fully paid |
| **Overdue Amount** | Σ outstanding past due date |
| **Refunded** | Σ payments with status `Refund` |
| **Net Revenue** | Revenue (Collected) − Refunded |
| **Average Deal Size** | Booked Value ÷ Sales won |
| **Revenue per Telecaller** | Net Revenue attributed per §2.6 |

### 2.6 Attribution — needs a business decision ⚠️

When a lead is reassigned between telecallers, **who gets credit** for the conversion and revenue?

| Option | Description |
|--------|-------------|
| **A — Last owner** *(proposed default)* | The telecaller who owned the lead when it converted. Simple, matches most incentive schemes |
| **B — First interest creator** | The telecaller who first moved it to `Interested`. Rewards prospecting |
| **C — Split** | Shared between owners, weighted by touches or time owned. Fairest, most complex |

Same question applies to campaign-sourced leads: does the **campaign** or the **telecaller** own the conversion credit? *(Proposed: both are reported, in separate views — campaign attribution for marketing ROI, telecaller attribution for incentives.)*

**This directly affects telecaller pay.** It needs an explicit decision before Phase 26.

### 2.7 Communication / campaign metrics

| Metric | Formula | Notes |
|--------|---------|-------|
| **Sent** | messages with status `sent` or later | |
| **Delivery Rate** | delivered ÷ sent | |
| **Open Rate** (email) | unique opens ÷ delivered | Unique, not total — one person opening 5× is one open |
| **Click Rate** (email) | unique clicks ÷ delivered | |
| **Click-to-Open Rate** | unique clicks ÷ unique opens | Content quality, independent of deliverability |
| **Reply Rate** | leads replying ÷ delivered | |
| **Bounce Rate** | bounced ÷ sent | |
| **Skip Rate** | skipped ÷ targeted | High skip rate signals a data-quality or over-suppression problem |
| **Cost per Message** | Σ `cost` ÷ sent | Where the provider reports cost |
| **Cost per Interested Lead** | campaign cost ÷ leads reaching `Interested` from it | |
| **Cost per Conversion (CAC)** | campaign cost ÷ conversions attributed | |
| **Campaign ROI** | (Revenue attributed − campaign cost) ÷ campaign cost | |

### 2.8 AI calling metrics

| Metric | Formula |
|--------|---------|
| **AI Calls Placed / Connected** | count; connected subset |
| **AI Connect Rate** | AI connected ÷ AI placed |
| **AI Interest Detection Rate** | AI calls flagged interested (≥ confidence threshold, BR-INT-04) ÷ AI connected |
| **AI → Human Handoff Rate** | AI-interested leads routed to a telecaller ÷ AI-interested |
| **AI Accuracy** | AI-detected interest later confirmed by a human ÷ AI-detected interest |
| **Cost per AI-Interested Lead** | AI calling cost ÷ AI-interested leads |

**AI Accuracy is the metric that matters most** — it tells you whether to trust the AI's interest detection at all, and whether the confidence threshold is set correctly.

### 2.9 Lead quality metrics

| Metric | Formula |
|--------|---------|
| **Leads by Source / Campaign** | count grouped |
| **Source Quality** | conversion rate per source — the number that decides where to spend |
| **Duplicate Rate** | duplicates detected ÷ leads submitted |
| **Invalid Contact Rate** | leads marked Wrong/Invalid Number ÷ leads attempted |
| **Suppression Rate** | active DNC entries ÷ total leads |
| **Untouched Leads** | assigned leads with zero contact attempts — the leakage metric |
| **Ageing** | leads open > N days without engagement, bucketed |

---

## Part 3 — Reporting Implementation Rules

1. **One formula, one place.** Each metric is implemented once in `App\Services\Reports`; no controller or Blade view recomputes a metric inline.
2. **Pre-aggregated.** Daily aggregates (`report_daily_aggregates`) are built by a scheduled job; dashboards read aggregates, not raw `calls`/`messages` (FR-RPT-05).
3. **Tested against fixtures.** Every formula has a unit test with a hand-computed expected value (FR-RPT-01).
4. **Labelled in the UI.** Any rate metric shows its denominator on hover — "Conversion Rate (of leads assigned this period)". Ambiguity in the UI recreates the exact problem this document solves.

## Part 4 — Open Decisions

| # | Decision | Proposed default | Needed by |
|---|----------|------------------|-----------|
| 1 | Attribution model on reassignment (§2.6) | A — last owner | Phase 26 |
| 2 | Campaign vs. telecaller conversion credit | Report both separately | Phase 26 |
| 3 | Idle threshold (§2.3) | 5 minutes | Phase 4 |
| 4 | Business hours definition | 09:00–20:00 org-local | Phase 9 |
| 5 | Headline conversion rate: cohort vs. period | Cohort | Phase 26 |
