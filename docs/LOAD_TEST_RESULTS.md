# Load Test Results

| | |
|---|---|
| **Version** | 1.1 |
| **Last updated** | 2026-09-05 (corrected — see §6: the "MariaDB is fragile" finding in v1.0 was wrong) |
| **Status** | T-43 answered with real, measured numbers from an isolated, error-free run |
| **Related** | [TODO.md](TODO.md) (T-43) · [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) (FR-RPT-05) · [ARCHITECTURE.md](ARCHITECTURE.md) (§4, §12) · [DEPLOYMENT.md](DEPLOYMENT.md) (§3A) · [TESTING.md](TESTING.md) |

This is a load/performance report, not a correctness one — it lives separately from TESTING.md on purpose. TESTING.md's suite proves the system does the right thing; this proves (or disproves) that it does it fast enough at volume. Nothing here touches `tests/Feature`.

---

## 1. What this answers

T-43 asked two questions DEPLOYMENT §3A and PROJECT_REQUIREMENTS FR-RPT-05 had both flagged and left open:

1. **Campaign fan-out throughput.** `DispatchCampaign.php` and `CampaignService.php` both cite "a 50,000-lead campaign" as their reference scale. DEPLOYMENT §3A already predicted, without measuring, that "a 50,000-recipient campaign that a VPS clears in minutes will take **hours** here [on shared hosting]." This test measures it instead of predicting it.
2. **Dashboard response time under data growth.** FR-RPT-05 sets a budget — "dashboard responds within an agreed budget *(target: < 2s, confirm)*" — and says aggregation exists so reports "must not degrade under data growth." This test seeds multi-month volume and measures both the pre-aggregated path and the live-query fallback.

## 2. Tool choice: Artisan + Guzzle, not k6

k6 was considered first. It was rejected, and the reasoning is worth recording so a future re-run doesn't silently re-litigate it:

- This machine has no admin rights to install a system package.
- The remaining option — downloading a standalone k6 binary from GitHub and running it — means executing an unfamiliar third-party executable. That is exactly the class of action this project's own supply-chain caution avoids elsewhere. It was not attempted.
- Guzzle (`guzzlehttp/guzzle`) is already a first-party Composer dependency, is async-capable, and needed no new runtime. Driving the real `database` queue driver and firing real HTTP requests at a real `php artisan serve` instance is not a "lesser" substitute for k6 here — it exercises the exact same application code path a real worker and a real browser would.

**Decision: a reusable Artisan command (`crm:load-test`), documented and re-runnable, using Guzzle for HTTP and Laravel's own queue internals for the worker.** See §7 for exactly how to re-run it.

## 3. The harness

| Piece | Path | Purpose |
|---|---|---|
| Isolated environment | `backend/.env.loadtest` | Points at a disposable `marketing_crm_loadtest` database. Deliberately keeps `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database` — the same drivers DEPLOYMENT §3A says shared hosting actually runs, not the Redis topology ARCHITECTURE §4 describes as the design target. |
| DB create/drop | `tools/loadtest/db.php` | A standalone PDO script (not Artisan) — the database has to exist *before* Laravel can boot against it. Refuses any name not ending in `_loadtest`. |
| The scenarios | `backend/app/Console/Commands/LoadTestCommand.php` | `php artisan crm:load-test {campaign\|dashboard\|all}`. Refuses to run (`guardAgainstRealDatabase()`) unless the connected database name ends in `_loadtest`. **Also takes a MySQL advisory lock (`GET_LOCK`) for the duration of the run and refuses to start if another instance already holds it** — see §6 for why this exists. |
| Reusable entry point | `tools/loadtest/run.ps1` | Creates the disposable database, migrates it, runs the command, drops the database again (`-Keep` to skip the drop). This is what to run again after future changes. |

Everything the harness creates is disposable and was dropped after every run recorded here.

## 4. Scenario A — campaign fan-out throughput

### 4.1 What was measured

`DispatchCampaign` fans a started campaign out into one `SendCampaignMessage` job per recipient; each of those, if not skipped, dispatches one more `SendMessage` job that hands the message to a channel driver (`LogDriver`, since no provider is keyed — no real network call, which is correct for a load test). Three stages were timed separately:

1. **Audience build** — `CampaignService::start()` materialising `campaign_recipients` rows.
2. **Fan-out** — processing exactly the one `DispatchCampaign` job, which turns `total_targeted` rows into that many queued `SendCampaignMessage` jobs.
3. **Drain** — processing every `SendCampaignMessage` job and the `SendMessage` job each one dispatches in turn, until the `messages` queue is empty.

### 4.2 Scale tested vs. the 50,000-lead target

**Tested at 5,000 synthetic leads — one tenth of the stated 50,000-lead reference scale**, chosen so the harness could be re-run several times (including the isolation test in §6) inside one working session. Every lead was a fresh, unsuppressed, valid-phone SMS recipient (the cheapest per-recipient case — no skips, no retries), so the measured rate is closer to a best case than a worst case at real volume.

### 4.3 Real numbers — final, isolated, error-free run (5,000 leads, in-process worker, `database` queue driver, concurrency lock held)

| Stage | Result | Rate |
|---|---|---|
| Leads seeded | 5,000 rows in 0.473s | 10,564.7 rows/s |
| Audience build (`campaign_recipients`) | 5,000 rows in 0.448s | 11,158.9 leads/s |
| Fan-out (`DispatchCampaign` → 5,000 `SendCampaignMessage` jobs) | 21.803s | 229.3 jobs/s |
| Drain (5,000 `SendCampaignMessage` + their `SendMessage` jobs) | 159.156s | **31.4 recipients/s** |
| Outcome | 5,000 sent, 0 skipped, 0 failed, 0 rows in `failed_jobs`, **0 transient DB errors** | — |

Cross-checked with a real `queue:work` OS subprocess (not the in-process worker) against a separately seeded, exclusively-held 3,000-lead run: 3,000 sent, 0 skipped, 0 failed, drain in 118s → **25.4 recipients/s** — same order of magnitude, zero errors, confirming the in-process number is not an artifact of skipping subprocess overhead. Both numbers are reported because they bound the honest range: **~25–31 recipients/s** on this driver, this machine, single worker.

Raw JSON: `backend/storage/app/loadtest-results.json` (regenerated on every run — not checked in).

### 4.4 Extrapolation to 50,000, shown

Audience build and fan-out are pure inserts and scale linearly with no reason to doubt it at this range:

- Audience build: 50,000 ÷ 11,158.9/s ≈ **4.5s**
- Fan-out: 50,000 ÷ 229.3/s ≈ **218s ≈ 3.6 min**
- Drain (the dominant cost, using the more conservative 25.4/s subprocess figure): 50,000 ÷ 25.4/s ≈ **1,969s ≈ 32.8 minutes**
- Drain (using the in-process figure, 31.4/s): 50,000 ÷ 31.4/s ≈ **1,592s ≈ 26.5 minutes**

**Total, single worker, continuous run: roughly 27–34 minutes** for a 50,000-lead campaign to fully complete on this database queue configuration, depending on which measured rate is used as the basis.

### 4.5 Honest caveats on that number

1. **This is a floor, not a promise.** It assumes throughput stays constant as the backlog grows tenfold. It may not: Laravel's own `jobs` table migration indexes only `queue`, **not** `reserved_at`/`available_at`. The database queue driver's `pop()` filters on reservation state without a covering index for it, so pop latency could rise as pending rows in `messages` grow from ~5,000 (tested) to ~100,000 (fan-out + follow-on sends at 50,000 leads). **Recommend validating at 50,000 directly before treating ~30 minutes as a committed number**, and consider adding a composite index on `(queue, reserved_at)` regardless.
2. **Production restarts the worker every minute; this test did not.** DEPLOYMENT §3A's actual cron entry is `queue:work --stop-when-empty --max-time=50`, run once a minute — the worker exits and a fresh PHP process boots on every tick. This test ran one continuous process for the whole drain. Real production wall-clock time will be *higher* than this number by however much per-tick PHP bootstrap and cron-scheduling latency adds, not lower. **Staffing 3–4 worker processes on the `messages` queue (DEPLOYMENT §4) would bring this well under 15 minutes at the measured per-worker rate** — worth a follow-up multi-worker run before relying on either number as a final answer, since row-lock contention on a shared `jobs` table does not guarantee linear scaling with worker count.
3. **One worker, on one machine, was measured.** This says nothing about how multiple concurrent workers would perform against contention on the same `jobs` table — a real question for the multi-worker follow-up in point 2.

### 4.6 Does FR-CAMP-05 hold?

**FR-CAMP-05's literal acceptance criterion — "a campaign of any size completes without HTTP timeout" — holds without qualification.** The HTTP request that starts a campaign only ever dispatches `DispatchCampaign` to the queue, so nothing about a 50,000-lead audience touches the request/response cycle at all.

**Whether a ~30-minute (floor) single-worker drain is an acceptable business outcome for a 50,000-lead campaign is a separate question this report cannot answer — see §8.**

## 5. Scenario B — dashboard response time (FR-RPT-05)

### 5.1 What was measured

Seeded 181 days (~6 months) of history — the oldest 180 days fully in the past, plus today — then ran `crm:aggregate-daily-reports` to populate `report_daily_aggregates` for every past day. Then fired 30 real HTTP requests each at:

- `GET /api/v1/reports/summary` for a **fully-past** period (two months back) — should read `report_daily_aggregates`.
- `GET /api/v1/reports/summary` for the **current month to date** (touches today) — must fall back to the live query path.
- `GET /dashboard` (the web Blade page) — always computes "this month to date," so it always exercises the live path.

Both used a real session (web) and a real Sanctum bearer token (API) against a real `php artisan serve` instance — not an in-process test client.

### 5.2 Seed volume

| Table | Rows |
|---|---|
| Days seeded | 181 |
| Leads | 10,860 |
| Calls | 16,215 |
| Messages | 16,210 |
| Sales | 362 |
| Payments | 362 |
| Seed time | 13.165s |
| Aggregation time (`crm:aggregate-daily-reports`) | 24.693s |

Realistic multi-month funnel shape (~70% contacted, ~25% of those interested, ~12% of those converted), not a flat/uniform distribution.

### 5.3 Real numbers — final, isolated run (30 requests per endpoint, 0 errors on all three)

| Endpoint | Expected path | Min | Avg | P95 | Max |
|---|---|---|---|---|---|
| `GET /api/v1/reports/summary` — fully-past period | pre-aggregated (`report_daily_aggregates`) | 263.4 ms | **296.4 ms** | 328.9 ms | 331.4 ms |
| `GET /api/v1/reports/summary` — touches today | live query | 352.8 ms | **407.3 ms** | 474.4 ms | 489.1 ms |
| `GET /dashboard` — web (always current month) | live query | 290.9 ms | 327.6 ms | 369.7 ms | 389.1 ms |

### 5.4 The FR-RPT-05 comparison — does pre-aggregation actually matter?

**Yes, measurably: the live path averaged 407.3 ms against the pre-aggregated path's 296.4 ms — a ~111 ms, ~37% gap, consistent across the run (min-to-min and P95-to-P95 both show the same shape).** That is the entire point of FR-RPT-05, and it is real, not a documentation claim.

**Both numbers are comfortably inside the <2s budget** — the live path's worst observed request (489.1 ms) is roughly a quarter of the budget, at ~6 months of realistic multi-month volume.

### 5.5 Honest caveats on the dashboard numbers

1. **`php artisan serve` is a development server** — single-threaded, no PHP-FPM process pool. Every number above almost certainly carries a "dev-server tax" that a real Apache/PHP-FPM deployment (the actual Hostinger target) would not pay. **These numbers are a ceiling for what shared hosting will see, not a floor.**
2. **The "touches today" comparison used a short live range (this month to date, a handful of days).** The live path's cost scales with rows *in the requested range*, not total table size — a good property, but it means this test's ~111 ms gap is for a *short* live range. A longer current-touching range (e.g. "last 12 months to date") would scan far more history and the gap against the flat, day-count-bounded aggregate path would very likely be larger. **Worth a follow-up run if a report ever lets a user pick a long, current-touching range** — this run did not cover that case.
3. **10,860 leads / ~16,000 calls / ~16,000 messages is a real but modest volume** — six months at 60 leads/day. A mature multi-year CRM could hold an order of magnitude more history. The aggregate path's cost is bounded by the number of **days** requested, not rows, so it should stay flat as historical volume grows — asserted from reading `periodAggregate()`'s design, not independently re-measured at 10x this volume here.

## 6. A correction: the "MariaDB is fragile" finding in the first version of this report was wrong

The first pass at this harness spawned `queue:work` as a real second OS process for the drain stage, saw intermittent `Base table or view not found` / `Unknown database` errors on tables that answered the query immediately before and after, and concluded — reasonably, given T-48's documented history of a corrupted `mysql.db` privilege table on this same MariaDB instance — that this machine's database was fragile under sustained write load. That version of `LoadTestCommand` was rewritten to drive the queue worker in-process specifically to work around it, and the first version of this document reported that as an environment finding.

**That diagnosis was wrong, and it was caught by directly testing it rather than trusting the plausible story.** Two independent load-test runs were in flight against the *same* disposable `marketing_crm_loadtest` database at the same time — one from a manually-invoked run, one from a separately-running harness session — each truncating and reseeding tables the other was mid-query against. That is a completely sufficient, ordinary explanation for "a table that existed a moment before and after briefly didn't": one process dropped or truncated it while the other was reading it. It has nothing to do with MariaDB's health.

**This was confirmed, not just hypothesised**, by reproducing the campaign scenario twice under controlled conditions:

- A real `queue:work` **subprocess**, run in true isolation (confirmed via `information_schema.processlist` that nothing else held a connection to the database), drained 3,000 recipients with **zero errors of any kind**.
- The in-process worker, run under the newly-added concurrency lock (§3), drained 5,000 recipients with **zero transient errors**, the same as every prior "clean" run — the lock does not change the underlying finding, it just makes the failure mode structurally impossible instead of merely absent by luck.

**Fix applied**: `LoadTestCommand::handle()` now takes a MySQL advisory lock (`GET_LOCK('crm-load-test:<database>', 0)`) for the duration of the run and refuses to start if another instance already holds it, naming the exact mistake in the refusal message. The in-process worker (`drainQueueInProcess()`) was kept — not because the subprocess approach is broken (it isn't, per the isolated test above), but because it avoids managing a second process's lifecycle from within this command, and it measures the identical `Illuminate\Queue\Worker` code path either way. Both classes' docblocks now record the corrected diagnosis so it cannot be silently rediscovered.

**What this means for trusting the numbers in §4 and §5**: they are real, and they were re-measured cleanly under the lock after this correction (the numbers in this document are those clean re-runs, not the contaminated ones from the first pass). **What it does NOT mean**: anything about this machine's MariaDB instance being unusually fragile. That claim is retracted. T-48's documented `mysql.db` corruption is a real, separate, already-repaired issue (see the project's own commit history) and should not be conflated with this — a mistake this document's first version made.

**The general lesson, worth keeping**: a `*_loadtest`-suffixed database name prevents pointing this harness at a *real* database, but it does nothing to prevent two *legitimate* invocations of the harness from colliding with each other. Any tool that creates and tears down shared disposable state needs its own concurrency guard, not just a naming convention — the guard in §3 is that fix, and it is the more valuable outcome of this whole section.

## 7. Re-running this

```powershell
# Both scenarios, defaults
tools/loadtest/run.ps1

# Campaign only, larger audience
tools/loadtest/run.ps1 -Scenario campaign -Leads 20000

# Dashboard only, a year of history, keep the database afterward to inspect it
tools/loadtest/run.ps1 -Scenario dashboard -Days 365 -Keep
```

See `tools/loadtest/run.ps1`'s own comment header for every parameter, and `LoadTestCommand`'s class docblock for what each scenario does and why. The command refuses to run against anything other than a database named `*_loadtest` (`guardAgainstRealDatabase()`), and refuses to run if another instance already holds the load-test lock (§6) — neither should be bypassed with `--force` or by killing a concurrently-running instance.

**Cleanup**: `run.ps1` creates and drops `marketing_crm_loadtest` automatically on every invocation unless `-Keep` is passed. Nothing from any run recorded here was left in any shared database.

## 8. Conclusions — does the stated design hold?

| Question | Answer |
|---|---|
| Does a 50,000-lead campaign complete without an HTTP timeout (FR-CAMP-05's literal text)? | **Yes, unconditionally** — the design (fan-out entirely via the queue) makes this true regardless of audience size. |
| Does a 50,000-lead campaign complete *quickly* on the current shared-hosting configuration? | **Not especially — roughly 27–34 minutes, extrapolated from a real, error-free 5,000-lead measurement, and that is a floor (§4.5).** DEPLOYMENT §3A predicted "hours" without measuring; the measured number is better than that fear but is not fast with a single worker. |
| Does the dashboard respond within the <2s budget (FR-RPT-05)? | **Yes, comfortably — worst observed request 489.1 ms, about a quarter of budget, at six months of realistic volume.** |
| Does pre-aggregation actually help (the entire point of FR-RPT-05)? | **Yes, measurably — ~37% faster on average for a fully-past period vs. a live one.** |
| Is the database queue driver adequate for this application's stated scale? | **Workable with more than one worker.** It meets the letter of FR-CAMP-05 with any worker count. A single worker is slow at 50,000 recipients; staffing 3–4 workers on `messages` (already DEPLOYMENT §4's recommendation) should bring that under 15 minutes, pending a direct multi-worker measurement. |
| Is this machine's MariaDB unusually fragile? | **No — retracted from the first version of this report.** The apparent fragility was two concurrent load-test runs sharing one disposable database, not the database engine. Fixed structurally with a concurrency lock (§6). |
| Does this confirm T-30 ("VPS migration budget: decide before Phase 18")? | **Partially.** Phase 18 (Campaign Engine) already shipped and this is the first real-volume measurement against it. The recommendation is not "must move to Redis before launch" — a team running occasional campaigns under a few thousand recipients will not notice ~30 recipients/s. It is: **decide the acceptable campaign completion time now, with a real number in hand, and staff multiple workers on the `messages` queue if that number needs to come down** — a Redis migration is one lever among several, not the only one. |

**T-30 and T-43 together now have what neither had before: a real number instead of a documented worry.** Whether that number is acceptable is a business decision, not a technical one — this report's job was to stop it from being a guess.
