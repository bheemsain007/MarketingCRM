# Database Schema

| | |
|---|---|
| **Version** | 2.0 |
| **Last updated** | 2026-08-10 (Phase 2) |
| **Status** | **Implemented.** 28 migrations applied against MySQL 8.4; rollback verified. Sales/payment tables remain deferred to Phases 22–23. |
| **Related** | [BUSINESS_RULES.md](BUSINESS_RULES.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) |

---

## 1. Conventions

Applied to every table from Phase 2 onward:

| Convention | Rule |
|------------|------|
| Primary key | `id` — `BIGINT UNSIGNED AUTO_INCREMENT` |
| Timestamps | `created_at`, `updated_at` on every table |
| Soft deletes | `deleted_at` where records are archivable/restorable (leads, campaigns, products, users). **Not** on immutable log tables |
| Audit fields | `created_by`, `updated_by` (FK → `users`) where accountability matters: leads, calls, payments, status changes, DNC entries |
| Tenancy | `tenant_id` on every primary table, **NOT NULL DEFAULT 0** — schema reservation only, no application logic until Phase 36 ([ADR-C](ARCHITECTURE.md#adr-c--multi-tenancy-timing)). **Never nullable:** `NULL != NULL` in SQL, so a nullable `tenant_id` silently disables every composite unique index that includes it |
| Foreign keys | Explicit, named, indexed. `RESTRICT` on delete for financial/audit records; `CASCADE` only for true child rows |
| Money | `DECIMAL(15,2)` — never `FLOAT` |
| Currency | `CHAR(3)` ISO-4217 alongside every money column |
| Enums | Stored as `VARCHAR` + application-level enum class + DB `CHECK`/index, **not** MySQL `ENUM` (avoids ALTER-table pain when values change) |
| Timezone | All datetimes stored UTC; display timezone resolved per user |
| Phone | Stored E.164-normalised in `phone_e164`; original input retained in `phone_raw` (BR-DUP-01) |
| Charset | `utf8mb4` / `utf8mb4_unicode_ci` throughout |
| Log tables | Append-only, no soft deletes, no updates — pruned by retention jobs only |

---

## 2. Core Tables (specified)

### 2.1 `leads`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | bigint null | reserved (ADR-C) |
| `name` | varchar(150) | |
| `company` | varchar(150) null | |
| `phone_e164` | varchar(20) | normalised — identity key |
| `phone_raw` | varchar(30) null | as entered |
| `alt_phone_e164` | varchar(20) null | |
| `email` | varchar(190) null | |
| `city` / `state` / `country` | varchar | |
| `timezone` | varchar(64) null | drives calling-hours rule (BR-CALL-04) |
| `status` | varchar(30) | BR-STAT-01 |
| `temperature` | varchar(10) | derived (BR-TEMP-01) |
| `score` | smallint default 0 | 0–100 (BR-SCORE-01) |
| `priority` | tinyint default 0 | FR-LEAD-05 |
| `lead_source_id` | bigint FK null | |
| `campaign_id` | bigint FK null | originating campaign (FR-LEAD-04) |
| `assigned_to` | bigint FK → users null | current telecaller |
| `assigned_at` | timestamp null | |
| `last_contacted_at` | timestamp null | drives recency/temperature |
| `last_engagement_at` | timestamp null | any inbound signal |
| `is_suppressed` | boolean default false | denormalised fast-filter flag; `dnc_entries` is the source of truth |
| `created_by` / `updated_by` | bigint FK null | |
| timestamps + `deleted_at` | | archive = soft delete |

**Indexes:** unique `(tenant_id, phone_e164)` — enforces BR-DUP-01/02 at the DB level, not just in code · `(status, temperature)` · `(assigned_to, status)` · `(last_contacted_at)` · `(is_suppressed)` · index on `email` · composite `(tenant_id, status, created_at)` for paginated lists.

### 2.2 `lead_products` — per-product interest (BR-PROD-01)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `lead_id` | bigint FK cascade | |
| `product_id` | bigint FK restrict | |
| `interest_status` | varchar(30) | independent of lead status |
| `temperature` | varchar(10) | per-product |
| `score` | smallint default 0 | |
| `notes` | text null | |
| `first_interest_at` / `last_activity_at` | timestamp null | |
| timestamps | | |

**Indexes:** unique `(lead_id, product_id)` · `(product_id, interest_status)` for product-wise interested-lead views (FR-INT-03).

### 2.3 `lead_status_history` — append-only (BR-STAT-03)

`id` · `lead_id` FK · `from_status` varchar(30) null · `to_status` varchar(30) · `changed_by` FK users null · `source_channel` varchar(20) — call/ai_call/whatsapp/email/sms/rcs/voice/manual/system · `reason` varchar(255) null (required for reopens) · `reference_type`/`reference_id` nullable morph → evidence (call, message) · `created_at`.

**Indexes:** `(lead_id, created_at)`. No updates, no deletes.

### 2.4 `dnc_entries` — the suppression source of truth (BR-DNC-02)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `lead_id` | bigint FK null | null = global entry by identifier |
| `phone_e164` | varchar(20) null | supports suppressing a number with no lead |
| `email` | varchar(190) null | |
| `reason` | varchar(30) | do_not_contact / not_interested / opted_out / wrong_number / invalid_number / bounced_email |
| `channel` | varchar(20) null | **null = all channels**; set = channel-specific opt-out |
| `source` | varchar(30) | manual / call_outcome / webhook / import / inbound_keyword |
| `active` | boolean default true | removal deactivates rather than deletes |
| `created_by` / `removed_by` | FK users null | BR-DNC-06 |
| `removed_at` / `removal_reason` | | |
| timestamps | | |

**Indexes:** `(lead_id, channel, active)` — the hot path for `DncService::canContact()` · `(phone_e164, active)` · `(email, active)`.

### 2.5 `calls`

`id` · `tenant_id` null · `lead_id` FK · `user_id` FK (telecaller) · `product_id` FK null · `direction` (outbound/inbound) · `status` varchar(30) (BR-CALL / 11 values) · `started_at` · `ended_at` · `duration_seconds` int · `notes` text null · `follow_up_id` FK null · `campaign_id` FK null · `dial_source` (manual/auto_dialer/ai) · `auto_dialer_session_id` FK null · `external_call_id` varchar null (provider/device ref) · audit + timestamps.

**Indexes:** `(lead_id, started_at)` · `(user_id, started_at)` — powers telecaller reports (FR-RPT-01) · `(status)` · `(campaign_id)`.

### 2.6 `call_recordings`

`id` · `call_id` FK unique · `lead_id` FK · `user_id` FK · `storage_disk` · `storage_path` (private disk, never public) · `file_size_bytes` · `duration_seconds` · `checksum` varchar null (upload integrity/dedupe, FR-REC-02) · `upload_status` (pending/uploading/uploaded/failed) · `uploaded_at` · `expires_at` (retention, BR-REC-02) · `device_captured_at` · timestamps.

**Indexes:** `(upload_status)` · `(expires_at)` for the purge job · unique `(call_id)`.

### 2.7 `messages` — unified outbound/inbound across all channels

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `lead_id` | bigint FK | |
| `channel` | varchar(20) | email/whatsapp/sms/rcs/voice/ai_call |
| `direction` | varchar(10) | outbound/inbound |
| `campaign_id` | bigint FK null | |
| `template_id` | bigint FK null | FR-COMM-02 |
| `provider` | varchar(50) | e.g. mailercloud, bhashsms |
| `provider_message_id` | varchar(190) null | for webhook correlation |
| `idempotency_key` | varchar(190) | **unique** — prevents double-send on job retry ([ARCH §6](ARCHITECTURE.md#6-webhooks--idempotency)) |
| `recipient` | varchar(190) | phone or email actually used |
| `status` | varchar(30) | queued/sent/delivered/read/failed/bounced/skipped |
| `failure_reason` | varchar(255) null | |
| `sent_at` / `delivered_at` / `read_at` | timestamp null | |
| `cost` | decimal(10,4) null | per-message cost where provider reports it |
| timestamps | | |

**Indexes:** unique `(idempotency_key)` · unique `(provider, provider_message_id)` · `(lead_id, created_at)` · `(campaign_id, status)` — powers campaign reports · `(channel, status)`.

### 2.8 `campaigns` / `campaign_recipients`

**`campaigns`**: `id` · `tenant_id` null · `name` · `channel` · `template_id` FK · `status` (draft/scheduled/running/paused/stopped/completed) · `audience_filters` json · `scheduled_at` · `started_at` / `completed_at` · `batch_id` varchar null (Laravel job batch, FR-CAMP-02) · counters `total_targeted`/`sent`/`delivered`/`failed`/`skipped` · audit + timestamps + soft delete.

**`campaign_recipients`**: `id` · `campaign_id` FK · `lead_id` FK · `status` (pending/queued/sent/delivered/failed/**skipped**) · `skip_reason` varchar null (BR-DNC-05) · `message_id` FK null · timestamps. Unique `(campaign_id, lead_id)`.

### 2.9 `provider_webhook_logs` — raw inbound, append-only

`id` · `provider` · `event_type` · `provider_event_id` varchar(190) · `signature_valid` boolean · `payload` json · `processed_at` null · `processing_error` null · `created_at`.

**Index:** unique `(provider, provider_event_id)` — this constraint *is* the idempotency guarantee for FR-META-03; replays hit a duplicate-key and are skipped.

### 2.10 `user_work_sessions` — attendance & productivity (FR-RPT-01)

Required by "active time / idle time / office-hours activity", which nothing else in the schema could support.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `user_id` | bigint FK | |
| `started_at` | timestamp | login / shift start |
| `ended_at` | timestamp null | null = session open |
| `end_reason` | varchar(20) null | logout / timeout / forced |
| `source` | varchar(10) | web / android |
| `active_seconds` | int default 0 | rolled up from activity pings |
| `idle_seconds` | int default 0 | derived (GLOSSARY §2.3) |
| `break_seconds` | int default 0 | explicitly marked breaks |
| `ip_address` / `user_agent` / `device_id` | varchar null | also serves audit |
| timestamps | | |

**Indexes:** `(user_id, started_at)` · `(ended_at)` to find stale open sessions.

### 2.11 `user_activity_pings` — raw activity signal, append-only

`id` · `user_id` · `work_session_id` FK · `action_type` (call/note/status_change/message/follow_up/page_view) · `reference_type`/`reference_id` null · `occurred_at`.

Active time is computed from these: any gap longer than the configured idle threshold *(proposed 5 min)* counts as idle. Pruned by retention job — high volume, low long-term value.

**Index:** `(work_session_id, occurred_at)`.

### 2.12 `customers` — post-sale identity (BR-CUST-01)

Created at first Sale, never before. The Lead record is **not** replaced.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `tenant_id` | bigint null | reserved |
| `origin_lead_id` | bigint FK null | the lead that produced the first sale |
| `name` / `company` | varchar | |
| `billing_name` | varchar null | may differ from contact name (BR-CUST-02) |
| `phone_e164` / `email` | varchar | |
| `billing_address` | text null | |
| `tax_id` | varchar(30) null | GSTIN / equivalent |
| `status` | varchar(20) | active / inactive |
| audit + timestamps + `deleted_at` | | |

**Indexes:** `(phone_e164)`, `(email)`, `(tax_id)` for dedup (BR-CUST-04) · `(origin_lead_id)`.

A `customer_leads` pivot links a customer to every lead that contributed, since one customer may arrive via multiple leads.

### 2.13 `notifications` — reminder & alert delivery (FR-FUP-01)

Follow-up reminders had no delivery mechanism defined; this is it.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `user_id` | bigint FK | recipient |
| `type` | varchar(50) | follow_up_due / lead_assigned / campaign_completed / payment_overdue / recording_failed |
| `title` / `body` | varchar / text | |
| `channel` | varchar(20) | in_app / push / email / sms |
| `reference_type` / `reference_id` | morph null | the lead, follow-up, campaign… |
| `status` | varchar(20) | pending / sent / failed |
| `sent_at` / `read_at` | timestamp null | |
| `scheduled_for` | timestamp null | future reminders |
| timestamps | | |

**Indexes:** `(user_id, read_at)` — unread badge · `(status, scheduled_for)` — dispatch job.

**Note:** these are *internal* notifications to CRM users. They are unrelated to outbound lead communication (`messages`) and are **never** subject to DNC — DNC protects leads, not staff.

### 2.14 `lead_assignments` — assignment history (FR-LEAD-08, BR-ASSIGN-*)

`id` · `lead_id` FK · `assigned_to` FK users · `assigned_by` FK users null (null = system) · `assignment_method` (manual/round_robin/load_balanced/campaign_rule) · `assigned_at` · `unassigned_at` null · `reason` varchar null.

The current owner is denormalised onto `leads.assigned_to` for query speed; this table is the history and the attribution source (GLOSSARY §2.6).

**Indexes:** `(lead_id, assigned_at)` · `(assigned_to, assigned_at)`.

### 2.15 `lead_imports` — bulk upload runs (FR-LEAD-07)

`id` · `tenant_id` · `original_filename` · `disk` · `stored_path` **null** · `file_size` · `status` (pending/processing/completed/completed_with_errors/failed) · `total_rows` · `processed_rows` · `imported_rows` · `duplicate_rows` · `invalid_rows` · `column_map` json · `options` json null · `batch_id` null · `failure_reason` text null · `uploaded_by` FK users null · `started_at` null · `finished_at` null · timestamps.

The five counters are a **denormalised summary** of `lead_import_rows`, which remains the source of truth. They exist because a progress poll every two seconds must not `COUNT()` over 50,000 rows. They are written with SQL-level increments, never read-modify-write: with several workers on the `imports` queue, two rows finishing at the same instant would otherwise lose a count and the import would never reach its total.

`stored_path` is **nullable on purpose**. The uploaded file is bulk PII and is purged after its retention window; the import record and its per-row outcomes are kept as an audit trail. The two have different lifetimes (SEC-PII-05).

`completed` and `completed_with_errors` are separate states rather than one "done" plus a counter — a half-rejected import must not look successful in a list.

**Indexes:** `(tenant_id, uploaded_by, created_at)` · `status` · `batch_id`.

### 2.16 `lead_import_rows` — the per-row report (FR-LEAD-07)

`id` · `lead_import_id` FK cascade · `row_number` (1-based over **data** rows) · `status` (imported/duplicate/invalid/failed) · `lead_id` FK leads null · `message` varchar(500) null · `data` json null · timestamps.

**`UNIQUE(lead_import_id, row_number)` is the idempotency key.** Row jobs are retryable, and a job that created its lead but died before acknowledging would otherwise write a second lead and a second result on redelivery. With the constraint in place, a redelivered row is a no-op.

`lead_id` means two things by design: for an `imported` row it is the lead created, for a `duplicate` it is the **existing** lead that already held the number — which is the one the operator actually wants to open (BR-DUP-02, BR-ASSIGN-05).

`data` holds the raw source row and is populated **only for rejected rows**: imported rows are already in `leads`, so a second copy of their PII would serve no purpose. It is cleared along with the uploaded file at purge time.

**Indexes:** `(lead_import_id, status)` · unique `(lead_import_id, row_number)`.

---

## 3. Remaining Modules (structure agreed, columns in Phase 2)

| Module | Tables |
|--------|--------|
| Core/Auth | `users`, `roles`, `permissions`, `role_user`, `permission_role`, `personal_access_tokens`, `teams` (Manager scoping) |
| Products | `products` |
| Leads (support) | `lead_sources`, `tags`, `lead_tag`, `lead_notes`, `lead_activities` — `lead_imports` and `lead_import_rows` are now specified in §2.15–2.16 |
| Calling | `auto_dialer_sessions`, `auto_dialer_queue_items` |
| Communication | `templates`, `message_events` (per-status webhook events) |
| Interest Engine | `interest_signals`, `lead_scores` (score change audit) |
| Follow-ups | `follow_ups` |
| Sales | `opportunities`, `opportunity_products`, `quotations`, `quotation_items`, `proposals`, `sales`, `lost_sales` |
| Payments | `customer_leads` (pivot), `payments`, `payment_links`, `invoices` |
| AI Calling | `ai_calls`, `ai_call_transcripts`, `ai_call_summaries` |
| Meta Capture | `meta_lead_forms`, `meta_form_field_mappings` |
| Audit | `audit_logs`, `activity_logs` |
| Reporting | `report_daily_aggregates` (pre-computed, FR-RPT-05) |

---

## 4. Key Relationships

```
users ──< leads (assigned_to)          users ──< user_work_sessions ──< user_activity_pings
  │                                    users ──< notifications
  └──< lead_assignments >── leads

leads ──< lead_products >── products
leads ──< lead_status_history
leads ──< calls ──1 call_recordings
leads ──< messages >── campaigns
leads ──< dnc_entries
leads ──< follow_ups
leads ──< opportunities ──< sales ──< payments
                              │
customers ────────────────────┘        customers ──< customer_leads >── leads
   └── origin_lead_id ──> leads        (lead record survives conversion — BR-CUST-01)

campaigns ──< campaign_recipients >── leads
```

## 5. Design Notes

- **`is_suppressed` on `leads` is a denormalised cache**, maintained by `DncService` on every suppression change. It exists so list filtering stays fast; **`dnc_entries` remains the authority** and the gate always reads the real table. Both must never be checked independently by feature code.
- **`messages` is one table across all channels**, not one per channel. Channel-specific provider payloads live in `message_events`/webhook logs. This keeps the lead timeline (FR-LEAD-09) a single query rather than a six-way union.
- **`idempotency_key` unique constraint** is what makes double-sends structurally impossible under job retry, rather than relying on application checks.
- **Enums as varchar** — the status sets in BUSINESS_RULES.md are expected to be tuned; MySQL `ENUM` changes require table alterations on large tables.
- **Reporting reads pre-aggregates**, never scanning `calls`/`messages` live at dashboard load.

## 6. Open Items

- Column-level specs for §3 modules — Phase 2.
- Whether lead custom fields are needed (JSON column vs. EAV) — not requested; recommend deferring until asked.
- Partitioning/archival strategy for `messages` and `calls` at high volume — revisit before Phase 29 (production).
