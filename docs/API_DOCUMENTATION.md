# API Documentation

| | |
|---|---|
| **Version** | 2.0 |
| **Last updated** | 2026-08-10 (Phase 3) |
| **Status** | **Foundation implemented and tested.** Envelope, error catalogue, rate limiting, list conventions and `/health` are live. Business endpoints are added by the phase that builds them — this file never describes an endpoint that does not exist. |
| **Related** | [ARCHITECTURE.md](ARCHITECTURE.md) · [SECURITY.md](SECURITY.md) · [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) |

---

## 1. Base & Versioning

- Base path: `/api/v1/`
- Breaking changes require `/api/v2/`; additive changes (new optional field, new endpoint) ship within v1.
- A field is never removed or repurposed within a version. Deprecations are announced via the `Deprecation` response header before removal in the next version.

## 2. Response Envelope

**Every** response — success and error, including framework-level errors from the exception handler — uses this shape (NFR-03):

```json
{
  "success": true,
  "message": "Lead created successfully.",
  "data": {},
  "errors": []
}
```

| Field | Type | Notes |
|-------|------|-------|
| `success` | bool | Mirrors HTTP status class |
| `message` | string | Human-readable, safe to display; never contains stack traces or SQL |
| `data` | object\|null | The payload. Lists return `{ "items": [...], "meta": {...} }` |
| `errors` | array | Empty on success. Field errors: `[{"field":"email","code":"validation.email","message":"..."}]` |

Enforced by a single `ApiResponse` helper so no endpoint can drift.

## 3. Authentication

| Client | Mechanism |
|--------|-----------|
| Web CRM | Laravel Sanctum, session cookie, same-origin, CSRF-protected ([ADR-A](ARCHITECTURE.md#adr-a--web-crm-delivery-model)) |
| Flutter Android | Sanctum personal access token, `Authorization: Bearer <token>` |

Unauthenticated → `401`. Authenticated but not permitted → `403` (never `404` for authorization failures, except where hiding existence is deliberate).

## 4. Standard Query Parameters

Applies to all list endpoints (FR-LEAD-02):

| Param | Example | Notes |
|-------|---------|-------|
| `page` | `?page=2` | 1-based |
| `per_page` | `?per_page=50` | default 25, max 100 |
| `sort` | `?sort=-created_at` | `-` prefix = descending; multi: `sort=priority,-created_at` |
| `q` | `?q=sharma` | free-text search over the resource's searchable fields |
| `filter[...]` | `?filter[status]=Interested&filter[temperature]=Hot` | field filters, AND-combined |
| `filter[...][in]` | `?filter[status][in]=New,Contacted` | multi-value |
| `filter[created_at][between]` | `?filter[created_at][between]=2026-01-01,2026-03-31` | date ranges |
| `include` | `?include=products,assignedUser` | eager-loaded relations (allowlisted per endpoint) |

Unknown filter/sort fields return `422` rather than being silently ignored — a silently dropped filter is a data-leak risk on a lead list.

### Pagination meta

```json
{
  "data": {
    "items": [],
    "meta": { "current_page": 1, "per_page": 25, "total": 1340, "last_page": 54 }
  }
}
```

## 5. HTTP Status Codes

| Code | Used for |
|------|----------|
| 200 | Successful read/update |
| 201 | Resource created (includes `Location` header) |
| 202 | Accepted for async processing (bulk campaign start, CSV import) |
| 204 | Successful delete with no body |
| 400 | Malformed request (unparseable body) |
| 401 | Missing/invalid authentication |
| 403 | Authenticated but not permitted (RBAC, or DNC-blocked action) |
| 404 | Resource not found |
| 409 | Conflict (duplicate lead, invalid state transition on a resource) |
| 422 | Validation failure, invalid enum value, disallowed status transition |
| 429 | Rate limit exceeded (includes `Retry-After`) |
| 500 | Unhandled server error — generic message only, details logged server-side |
| 503 | Provider circuit breaker open / dependency unavailable |

## 6. Error Code Catalogue

Machine-readable `code` values in `errors[]`, stable across versions so clients can branch on them:

| Code | HTTP | Meaning |
|------|------|---------|
| `auth.unauthenticated` | 401 | No/invalid credentials |
| `auth.token_expired` | 401 | Token expired, re-authenticate |
| `auth.forbidden` | 403 | Role/permission denies this action |
| `validation.failed` | 422 | One or more field errors in `errors[]` |
| `lead.duplicate` | 409 | Phone matches an existing lead (BR-DUP-02) |
| `lead.invalid_status_transition` | 422 | Blocked by the transition matrix (BR-STAT-02) |
| `lead.archived` | 409 | Action not allowed on an archived lead |
| `dnc.suppressed` | 403 | Contact blocked by the DNC gate (BR-DNC-01) — includes reason and channel in `data` |
| `campaign.invalid_state` | 409 | e.g. resume on a stopped campaign (BR-CAMP-05) |
| `campaign.frequency_capped` | 429 | Frequency cap hit (BR-CAMP-04) |
| `call.outside_calling_hours` | 403 | Blocked by calling-hours rule (BR-CALL-04) |
| `payment.invalid_transition` | 422 | Blocked by payment matrix (BR-PAY-02) |
| `payment.orphan_not_allowed` | 422 | Missing lead/customer/product/sale link (BR-PAY-03) |
| `provider.unavailable` | 503 | Circuit breaker open |
| `provider.rejected` | 502 | Provider rejected the payload permanently |
| `rate_limit.exceeded` | 429 | Throttled |
| `server.error` | 500 | Unhandled |

## 7. Rate Limiting

| Scope | Proposed limit |
|-------|----------------|
| Authentication endpoints | 5/min per IP + per account |
| Standard authenticated endpoints | 120/min per user |
| Bulk triggers (campaign start, import) | 10/min per user |
| Webhook receivers | Provider-appropriate, generous; never blocks a valid provider retry |

Responses include `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After` on `429`. *(Limits proposed — confirm before Phase 3.)*

## 8. Idempotency

Write endpoints that trigger outbound actions (sends, campaign starts, payments) accept an `Idempotency-Key` header. A repeated key returns the original result rather than acting twice — matching the internal idempotency guarantee ([ARCH §6](ARCHITECTURE.md#6-webhooks--idempotency)).

## 9. Long-Running Operations

Bulk actions return `202 Accepted` with a job/batch reference rather than blocking (NFR-06):

```json
{ "success": true, "message": "Campaign queued.",
  "data": { "campaign_id": 42, "batch_id": "9f3c...", "status_url": "/api/v1/campaigns/42/status" },
  "errors": [] }
```

Progress is polled via the returned status URL.

## 10. Webhook Endpoints (inbound)

Receivers follow the shared pipeline — verify signature → persist raw → dedupe → ACK 200 → process async. They ACK before processing, so a `200` means *received*, not *processed*.

| Path | Source |
|------|--------|
| `/api/v1/webhooks/meta` | Facebook/Instagram Lead Ads — **implemented**. `GET` answers the subscription challenge, `POST` receives leadgen deliveries |
| `/api/v1/webhooks/whatsapp` | WhatsApp Business API |
| `/api/v1/webhooks/mailercloud` | Mailercloud delivery/open/click/bounce — **implemented**, and a hard bounce or unsubscribe now suppresses (path is provider-named, not `/email`) |
| `/api/v1/webhooks/sms` | BhashSMS delivery reports — **not implemented**: sending is live, but their DLR callback contract is undocumented (T-53), so SMS status stops at `sent` |
| `/api/v1/webhooks/rcs` | RCS provider *(TBD)* |
| `/api/v1/webhooks/voice` | Voice provider *(TBD)* |
| `/api/v1/webhooks/ai-calling` | Vaaad call status/transcript/summary |

Webhook routes are exempt from session CSRF but **require provider signature verification** (SEC control).

## 10A. Implemented Endpoints

### `GET /api/v1/health`

Liveness/readiness probe for monitoring. **Unauthenticated by design** — an uptime monitor cannot hold credentials, which is also why it returns no business data, version, or environment detail.

Returns `503` when a dependency is down, so a monitor records an outage rather than a success containing bad news.

```json
{
  "success": true,
  "message": "Service healthy.",
  "data": {
    "status": "ok",
    "checks": { "database": true },
    "time": "2026-08-10T04:20:53+00:00"
  },
  "errors": []
}
```

### Authentication (Phase 4)

| Method | Path | Auth | Permission | Notes |
|--------|------|------|------------|-------|
| POST | `/api/v1/auth/login` | — | — | `throttle:api-auth` (5/min per IP **and** per email) |
| GET | `/api/v1/auth/me` | Bearer | — | Returns user, roles, permissions, `data_scope` |
| POST | `/api/v1/auth/logout` | Bearer | — | Revokes **only** the presenting token |
| POST | `/api/v1/auth/logout-all` | Bearer | — | Revokes every token |
| POST | `/api/v1/auth/change-password` | Bearer | — | Signs out other devices |

**Login** — `{ "email": "...", "password": "...", "source": "web|android" }`

```json
{
  "success": true,
  "message": "Signed in successfully.",
  "data": {
    "token": "1|Vo1FH1oh…",
    "token_type": "Bearer",
    "work_session_id": 1,
    "user": { "id": 1, "email": "...", "roles": [...], "permissions": [...], "data_scope": "all" }
  },
  "errors": []
}
```

Login also **opens a work session** (FR-ATT-01) — attendance tracking cannot be skipped by a future login path.

`permissions` and `data_scope` are returned so the client can hide controls it cannot use. That is a **usability aid only** — the server re-checks on every request (SEC-AUTHZ-02).

**Failure modes:** invalid credentials → `401 auth.unauthenticated` with an *identical* message whether or not the account exists (no user-enumeration oracle). Disabled account → `403`.

### Products (Phase 5)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/products` | `products.view` | Paginated; `?with_archived=1` includes archived |
| GET | `/api/v1/products/{id}` | `products.view` | |
| POST | `/api/v1/products` | `products.manage` | `code` normalised to uppercase |
| PATCH | `/api/v1/products/{id}` | `products.manage` | |
| DELETE | `/api/v1/products/{id}` | `products.manage` | **Archives** (soft delete + deactivate), never destroys |
| POST | `/api/v1/products/{id}/restore` | `products.manage` | |

Filters: `is_active`, `delivery_type`, `code`. Sorts: `sort_order`, `name`, `code`, `base_price`, `created_at`. Default order is `sort_order, name` — the business's own listing order.

**Delete archives rather than destroys.** `lead_products` holds a RESTRICT foreign key, so erasing a product would either fail or orphan interest history and corrupt product-performance reporting. Archiving also sets `is_active = false`, so anything filtering on active status (dropdowns, campaign targeting) excludes it too.

A duplicate `code` returns `409 resource.conflict` with `data.existing_product_id`, and states when the conflicting product is **archived** — otherwise the caller sees "already exists" for something they cannot find in the UI.

### Leads (Phase 6)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/leads` | `leads.view` | Scoped; `?q=` search, `?with_archived=1` |
| POST | `/api/v1/leads` | `leads.create` | Accepts `product_ids`, `tag_ids`, `note` |
| GET | `/api/v1/leads/{id}` | `leads.view` | + policy check |
| PATCH | `/api/v1/leads/{id}` | `leads.update` | + policy check |
| DELETE | `/api/v1/leads/{id}` | `leads.archive` | Archives (soft delete) |
| POST | `/api/v1/leads/{id}/restore` | `leads.archive` | |
| GET/POST | `/api/v1/leads/{id}/notes` | `leads.view` / `leads.update` | Append-only |
| GET | `/api/v1/leads/{id}/timeline` | `leads.view` | Unified activity feed |
| POST | `/api/v1/leads/{id}/assign` | `leads.assign` | |
| POST | `/api/v1/leads/{id}/unassign` | `leads.assign` | |
| POST | `/api/v1/leads/{id}/auto-assign` | `leads.assign` | Runs configured strategy |
| GET | `/api/v1/leads/{id}/assignments` | `leads.view` | Assignment history |
| GET | `/api/v1/leads/assignees` | `leads.assign` | Eligible telecallers + open-lead counts |
| POST | `/api/v1/leads/import` | `leads.import` | **`202`**; `multipart/form-data`; bulk rate limiter |
| GET | `/api/v1/leads/imports` | `leads.import` | Own uploads; all uploads at `All` scope |
| GET | `/api/v1/leads/imports/{id}` | `leads.import` | Progress + counts — poll this |
| GET | `/api/v1/leads/imports/{id}/rows` | `leads.import` | Per-row report; `?filter[status]=invalid` |

Filters: `status`, `temperature`, `priority`, `assigned_to`, `lead_source_id`, `campaign_id`, `city`, `state`, `is_suppressed`, `created_at`, `last_contacted_at`.
Sorts: `created_at`, `updated_at`, `name`, `priority`, `score`, `last_contacted_at`, `last_engagement_at`.
Includes: `source`, `assignedUser`, `tags`, `leadProducts.product`.

**Phone numbers are normalised to E.164 on the way in.** `9876543210`, `09876543210`, `+91 98765-43210` all store as `+919876543210`. Responses carry both `phone` (E.164, for storage/matching) and `phone_formatted` (display).

**Duplicates return `409 lead.duplicate`** with the existing lead's id, name, whether it is archived, and **who currently owns it** — so the caller knows who to speak to rather than just being refused (BR-ASSIGN-05).

**Two authorisation layers on every record route.** The permission middleware asks "may this user touch leads?"; `LeadPolicy` asks "may they touch *this* lead?". Without the second, a telecaller reads a colleague's lead by changing the id — the classic IDOR.

**Data scope is applied before client filters**, so a caller can narrow their view but never widen it. `?filter[assigned_to]=<someone else>` returns an empty set for a telecaller, not the other agent's leads.

**The unassigned pool is `?filter[assigned_to][null]=true`** (and `=false` for owned leads). Use the `null` operator, not an empty value — `filter[assigned_to]=` is `WHERE assigned_to = ''`, which silently matches nothing.

**Protected fields**: `status`, `temperature`, `score`, `is_suppressed` and `assigned_to` are *not* accepted by `PATCH /leads/{id}`. They change only through their own services and endpoints (SEC-IN-06).

**Create-only fields**: `product_ids`, `tag_ids` and `note` are accepted by `POST /leads` only. On an existing lead they have their own endpoints — per-product interest carries its own state, and notes are append-only.

**Omitting a field and sending it empty are different on `PATCH`.** Omit it to leave the value alone; send `null` to clear it. Clearable: `company`, `alt_phone`, `email`, `city`, `state`, `timezone`, `lead_source_id`. `name` and `phone` reject an empty value rather than accepting it — `country` and `priority` are `NOT NULL` with database defaults and cannot be nulled at all.

#### Bulk import (FR-LEAD-07)

`POST /api/v1/leads/import` takes a `multipart/form-data` body:

| Field | Type | Notes |
|-------|------|-------|
| `file` | file | **Required.** `.csv`, `.txt` or `.tsv`, max 10 MB / 50,000 rows (`config/crm.php`) |
| `column_map[<field>]` | string | Optional. Header label to use for a lead field, overriding auto-detection |
| `lead_source_id`, `campaign_id` | int | Applied to every lead in the file |
| `tag_ids[]`, `product_ids[]` | int[] | Applied to every lead in the file |
| `auto_assign` | bool | Requires `leads.assign` as well — see below |

Importable fields: `name`, `phone`, `alt_phone`, `email`, `company`, `city`, `state`, `country`, `timezone`, `priority`, `note`. **`status`, `temperature`, `score`, `assigned_to` and `is_suppressed` are not importable** — a file that could set `status = converted` would walk straight past the transition matrix (SEC-IN-06, BR-STAT-02).

**The response is `202`, not `200`.** The request has accepted the file, not finished the work: the file is stored, the row count is known, and everything else runs on the `imports` queue (NFR-06). Poll `GET /leads/imports/{id}` for `status`, `progress` and `totals`.

**Headers are auto-detected**, so the ordinary upload needs no configuration — `Full Name`, `Mobile No.`, `Email Address`, `Company Name`, `Town` all map without help. Detection is case- and punctuation-insensitive. `column_map` overrides it for the file that calls its phone column `Contact 2`.

**Shape problems fail synchronously with `422`; row problems never do.** No phone column, an empty file, or one over the row limit is rejected while the operator is still looking at the upload dialogue, and the file is deleted rather than left on disk. A `422` for a missing column returns `data.detected_header` and `data.detected_map` so a client can render a mapping screen instead of making the user guess.

**Every row goes through the same `LeadService` as manual entry**, so E.164 normalisation, duplicate detection and timeline writing are identical no matter how a lead arrives (BR-DUP-01/02).

**Row outcomes are `imported` / `duplicate` / `invalid` / `failed`.** A duplicate is *not* an error: the lead exists and already has an owner, and the report links to it (BR-ASSIGN-05). A bad row is reported and skipped — it never fails the run, and it never rolls back rows already written.

**`auto_assign` requires `leads.assign`, not just `leads.import`.** Otherwise a telecaller could hand themselves several thousand leads in a single upload (BR-ASSIGN-01).

**An import report is readable only by its uploader**, or by a user with `All` data scope. Its rejected rows contain the names and numbers of everyone the file failed to create, which is the same class of data as the lead list itself (SEC-AUTHZ-04). Uploaded files are purged after 30 days by `leads:purge-import-files`; the report survives the purge, the raw row data does not (SEC-PII-05).

`.xlsx` is **not** accepted — it needs a spreadsheet library that is not yet a dependency (T-45). Save as CSV.

### Lead Status & Product Interest (Phase 7)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| PATCH | `/api/v1/leads/{id}/status` | `leads.update` | + `leads.reopen` when the lead is closed |
| GET | `/api/v1/leads/{id}/transitions` | `leads.view` | Legal moves **for this caller** |
| GET | `/api/v1/leads/{id}/status-history` | `leads.view` | Append-only audit (BR-STAT-03) |
| GET | `/api/v1/leads/{id}/products` | `leads.view` | Per-product interest |
| POST | `/api/v1/leads/{id}/products` | `leads.update` | Record interest in a product |
| PATCH | `/api/v1/leads/{id}/products/{leadProductId}` | `leads.update` | Interest status and/or quoted value |
| DELETE | `/api/v1/leads/{id}/products/{leadProductId}` | `leads.update` | Removes the interest row |

**Status is not part of `PATCH /leads/{id}`.** It moves only through its own endpoint, because every change must run the transition matrix, check authority, write append-only history and — for `Not Interested` — write suppression. A status reachable from the generic update body would skip all four. Sending `status` in a general edit is silently dropped, not rejected: the legitimate part of the edit still applies (SEC-IN-06).

`PATCH /leads/{id}/status` body: `status` (required, one of the 11), `reason` (required for reopens), `source` (`manual` default; `call`, `ai_call`, `whatsapp`, `email`, `sms`, `rcs`, `voice`, `import`, `system` — recorded for attribution).

**An illegal transition returns `422 lead.invalid_status_transition` with the legal moves attached** in `data.allowed`, plus `data.is_terminal`. A client can correct itself rather than guessing. Setting the status a lead already has is also a `422` — the matrix's diagonal is empty.

**`Converted` cannot be set directly.** It requires a sale with a non-failed payment (BR-STAT-05, BR-PAY-05), which arrives in Phase 22. Until then it returns `422` with `data.requires = "sale_with_payment"`, and it never appears in `/transitions`. This is stricter than the matrix on purpose: `Converted` is terminal and drives revenue reporting and telecaller pay, so a wrong one cannot be walked back.

**Reopens (from `Lost` or `Not Interested`) need `leads.reopen` *and* a written reason.** Without the permission a telecaller could recycle their own dead leads to flatter their numbers; without the reason, the audit records that it happened but not why — and "why" is the whole question when a reopened lead later converts. **Reopening does not clear suppression** (BR-DNC-06): un-suppressing is a separate, separately-audited action.

**`Not Interested` writes suppression automatically** across all channels (BR-DNC-07). `Lost` does not — "we didn't win it" is not "they told us no".

**Product interest is independent** (BR-PROD-01/02): a lead can be negotiating one product and uninterested in another, and changing either leaves the other untouched. It runs the **same** transition matrix as the lead status. Declining one product does **not** suppress the lead (BR-PROD-03). The lead status follows its furthest-advanced product forwards only, as a system-recorded change — see the BR-STAT-04 clarification in BUSINESS_RULES.

Product routes are **scope-bound**: `/leads/5/products/9` where interest 9 belongs to another lead is a `404`, not somebody else's record.

### Calling (Phase 9)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/leads/{id}/callability` | `calls.view` | May this lead be dialled right now, and why not |
| GET | `/api/v1/leads/{id}/calls` | `calls.view` | Call history for one lead |
| POST | `/api/v1/leads/{id}/calls` | `calls.create` | **`202`** dial intent, or **`201`** if `status` is sent |
| PATCH | `/api/v1/calls/{id}` | `calls.create` | Report the outcome |
| GET | `/api/v1/calls` | `calls.view` | Cross-lead history, scoped to the caller |

**The Web CRM orchestrates; the device dials** (ADR-B). `POST /leads/{id}/calls` with no `status` creates the record and the dial intent and returns **`202`** — the call has no outcome yet, and `status` is `null` until the handset reports back. Sending a `status` logs a call that already happened and returns `201`.

`status = null` is a real state, not a missing value: *dialled, outcome not yet reported*. FR-CALL-01 is untouched — the eleven statuses remain the only **outcomes** accepted.

**Three gates run before every dial**, in `CallService` so the auto dialer (Phase 10) and AI calling (Phase 24) cannot bypass them:

1. **DNC** (FR-CALL-08, BR-CALL-01) — `403 dnc.suppressed`. Read from `dnc_entries` via `DncService`, never from `leads.is_suppressed`, which is a list-filter cache that can lag. Suppression is per reason and per channel, so a bounced email does not block a call.
2. **Calling hours** (BR-CALL-04) — `403 call.outside_calling_hours`, evaluated **in the lead's timezone**, not the office's. The refusal carries `data.next_opening`: attempts are deferred, never dropped.
3. **A usable number** — `422`.

**Outcomes are write-once.** A second `PATCH` returns `409`. Call records are evidence — they feed talk time, telecaller pay and any disputed conversion — so an outcome that could be rewritten later is one nobody can rely on. Corrections go in a note.

**`duration_seconds` is zero on anything that did not connect.** A ringing phone is not a conversation, and counting it would inflate Average Call Duration for whoever gets the worst list (GLOSSARY §2.2).

**Outcomes drive side effects automatically** (BR-CALL-05), because these are the ones most easily forgotten:
- `wrong_number` / `invalid_number` → suppression (BR-DNC-07), blocking phone channels but leaving email contactable.
- `call_back_requested` → an open follow-up assigned to **the same telecaller** — the lead asked *them* to ring back. `callback_at` is required, so a callback is never a promise with no diary entry.
- Any outcome → `last_contacted_at` updated, feeding the leakage metric (GLOSSARY §2.9).

`GET /leads/{id}/callability` returns `{callable, reason, message, next_opening}` so a UI can grey out the call button **with the reason attached**, rather than letting a telecaller discover the refusal by being refused.

### Auto dialer (Phase 10)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/dialer/current` | `dialer.use` | The caller's open run — survives a page reload |
| POST | `/api/v1/dialer/sessions` | `dialer.use` | Start a run; optional queue filters |
| GET | `/api/v1/dialer/sessions/{id}` | `dialer.use` | Session + full queue with skip reasons |
| POST | `/api/v1/dialer/sessions/{id}/next` | `dialer.use` | **The whole module** |
| POST | `/api/v1/dialer/sessions/{id}/pause` · `/resume` · `/stop` | `dialer.use` | |

**`next` returns the lead, its dial intent, and the leads passed over on the way there with a reason for each** (FR-CALL-07). A telecaller sees that eleven numbers were skipped for cooldown rather than wondering why the queue emptied so fast. When the run is exhausted, `data.lead` is `null` and the skip list still comes back — a run ending *because* everything left was suppressed is exactly when the reasons matter.

**Session state lives in the database** (FR-CALL-06). Pause halts dialling without losing queue position, and `GET /dialer/current` restores a run after a page reload, a closed laptop or a shift change.

**Skip reasons** — `suppressed`, `no_phone`, `cooldown`, `follow_up_scheduled`, `outside_calling_hours`, `claimed_elsewhere`, `archived`. Each carries `is_temporary`, separating what may become dialable later from what never will. Compliance skips (`suppressed`) and scheduling skips (`cooldown`) are deliberately distinct: reporting them together would hide how much of a list is legally unreachable.

**Skip rules** (BR-CALL-02) are asked of `CallService`, not reimplemented. The dialer must not be able to disagree with the manual-call gate about whether a lead may be rung — the disagreement would only ever be discovered by ringing somebody on the do-not-contact list. Claiming a lead then runs the **full** gate again; the pre-check exists to report the skip nicely, the gate is what protects the lead.

**No two telecallers are handed the same lead** (BR-CALL-03), enforced by a row lock at claim time rather than a Redis mutex — the shared-hosting target has no Redis (DEPLOYMENT §3A). Claims older than `crm.dialer.claim_ttl_minutes` (15) are released, or one closed laptop would take a lead out of circulation permanently.

**Queue building**: data scope first, then `Converted` / `Lost` / `Not Interested` excluded, ordered by priority then longest-waiting, with **untouched leads first** (GLOSSARY §2.9). Capped at `crm.dialer.max_queue_size` (200) — a run is a shift's work, not the whole database; a queue built once and worked for days is stale by the second hour.

One open session per telecaller. A second `POST /dialer/sessions` returns `409` with the existing session id.

### User administration (T-51)

| Method | Path | Permission |
|--------|------|-----------|
| GET | `/api/v1/users` · `/users/{id}` | `users.view` |
| POST | `/api/v1/users` | `users.manage` |
| PATCH | `/api/v1/users/{id}` | `users.manage` |
| POST | `/api/v1/users/{id}/disable` · `/enable` | `users.manage` |
| PUT | `/api/v1/users/{id}/roles` | **`roles.manage`** — Super Admin only |

**Two permissions, and the split is the point** (SEC-AUTHZ-05). `users.manage` creates and edits people; `roles.manage` decides what they may do. Admin holds the first, only Super Admin the second — so an Admin can onboard a telecaller and cannot promote one.

**`PATCH /users/{id}` ignores a `roles` key.** Roles move only through `PUT /users/{id}/roles`, or an Admin who can rename somebody could also promote them.

**Nobody may change their own roles** — a `403` even for a Super Admin. Compromising one account must not be the same as compromising every permission.

**New users start with no role.** They can sign in and do nothing until a Super Admin assigns one; the response message says so.

**Lockout guards**: you cannot disable your own account, and the last active Super Admin cannot be disabled (`422`).

**Disabling revokes every token and closes the open work session**, so a disabled user cannot keep working from an app that never re-authenticates, and stops accruing attendance time.

Filters: `?q=` (name or email), `?role=`, `filter[is_active]`, `filter[team_id]`.

### Business reports (Phase 27)

| Method | Path | Permission |
|--------|------|-----------|
| GET | `/api/v1/reports/summary` | `reports.business` |
| GET | `/api/v1/reports/revenue` | `reports.business` |
| GET | `/api/v1/reports/pipeline` | `reports.business` |
| GET | `/api/v1/reports/products` | `reports.business` |
| GET | `/api/v1/reports/sources` | `reports.business` |

All accept `?from=YYYY-MM-DD&to=YYYY-MM-DD` (FR-RPT-04). **The default is this month, not all time** — an unbounded aggregate over a growing table is the query that takes the dashboard down.

**Every rate is an object, never a bare number** (FR-RPT-06):

```json
"lead_to_sale": {
  "value": 25.0, "numerator": 1, "denominator": 4,
  "of": "leads assigned this period (cohort)"
}
```

`value` is **`null` when the denominator is zero** — "nothing happened" and "things happened and none succeeded" are different facts.

**The response states its own timezone.** Rows are UTC; aggregation is in the organisation's timezone, and both endpoints of the window come back so two people comparing dashboards can confirm they used the same one.

**Formulas follow GLOSSARY Part 2 exactly.** The ones most often got wrong: average call duration divides by *connected* calls not attempts; talk time excludes calls that never connected; booked and collected revenue are separate and never summed; collected counts by *payment* date; the headline conversion rate is *cohort-based* — of leads assigned in the period, how many eventually converted.

**Not data-scoped**, by design — this is the organisation-wide view, so it is gated on `reports.business` (Manager+) rather than filtered to the caller's own leads.

**Absent**: telecaller performance (FR-RPT-01, needs T-24), campaign performance (needs Phase 18), Chart.js dashboards (FR-RPT-03, T-60).

### Interest engine (Phase 20)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| POST | `/api/v1/leads/{id}/interest` | `leads.update` | Records a signal, applies all seven effects |
| GET | `/api/v1/leads/{id}/score` | `leads.view` | The score **with its working** |
| GET | `/api/v1/interested-leads` | `leads.view` | All interested / hot / warm / product-wise |

**Body**: `type` (required, one of 13), plus optional `product_id`, `channel`, `excerpt`, `confidence`.

**Note the path**: `/interested-leads`, not `/leads/interested` — `GET /leads/{lead}` is registered earlier and would bind "interested" as an id.

**One signal, seven effects, one transaction** (BR-INT-02): signal stored, status promoted, product interest recorded, label applied, score recalculated, temperature recalculated, follow-up scheduled. Partial application is impossible.

**Status is only promoted from `new`, `contacted`, `follow_up` or `callback`.** The matrix permits `negotiation → interested` for a *human*; an automated signal must not walk a deal backwards.

**Score is derived from `interest_signals`, never accumulated** — which is what makes `/score` able to explain itself, and what makes re-weighting `config('crm.scoring')` apply to history rather than only to new leads. It is clamped 0–100; `raw` is returned unclamped so a lead far above the ceiling is distinguishable from one exactly at it.

**AI signals below `crm.ai_interest_confidence_threshold`** (0.75) are recorded with `acted_on: false` and change nothing (BR-INT-04). They are excluded from the interested view. Points for AI signals are multiplied by confidence.

**Repeat negatives are capped** — ten unanswered calls stop at −10, and the breakdown flags the line as `capped`.

**`score` and `temperature` are derived and unreachable by `PATCH /leads/{id}`** (BR-TEMP-01). Recency *gates* temperature: enough points but sixty days of silence is not Hot, and a suppressed lead is Dormant.

**Views**: `?temperature=hot|warm|cold|dormant` and `?product_id=` filter the interested list; default sort is highest score first.

`crm:decay-lead-scores` runs daily — decay is the absence of events, so nothing else triggers it.

### Payments (Phase 23 — offline half)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/payments` | `payments.view` | Scoped through the lead |
| GET | `/api/v1/sales/{id}/payments` | `payments.view` | Includes the derived balance |
| POST | `/api/v1/sales/{id}/payments` | `payments.manage` | |
| PATCH | `/api/v1/payments/{id}` | `payments.manage` (+ `payments.refund` for a refund) | |

**Lead, customer and sale are taken from the sale, not the request.** A body naming its own `customer_id` is ignored — a request that could choose the account is one that can attach money to the wrong one (BR-PAY-03).

**`product_id` is required when the sale covers several products.** A single-product sale fills it in. This keeps revenue-by-product exact rather than apportioned (FR-PAY-04, T-58).

**Status is derived on create, not declared.** Settling the remainder is `paid`; covering part of it is `partial`; a future `due_on` is `pending`.

**Balance is computed, never stored** (BR-PAY-04) — sale amount minus non-failed, non-refunded payments. Returned with every payment response so whoever took the money sees what is still owed.

**Transitions follow the BR-PAY-02 matrix.** Notable edges: `partial → partial` is the instalment case; `paid → refund` only; `refund` is terminal; there is **no `partial → failed`** — money that arrived cannot later fail. An illegal move is `422 payment.invalid_transition` and comes back with the legal ones.

**A refund needs a reason and `payments.refund`.** **`overdue` cannot be set by hand** — it belongs to `crm:mark-overdue-payments`, or a collections report could be backdated (BR-PAY-06).

**This closes the `Converted` gate.** A lead converts only when a sale has a `partial` or `paid` payment (BR-PAY-05); until then the status endpoint returns `data.requires = "payment"`.

**Not implemented**: payment links and gateway collection (FR-PAY-02) — needs the gateway named (T-34, T-59).

### Sales (Phase 22)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/opportunities` | `sales.view` | Pipeline, scoped through the lead |
| GET | `/api/v1/leads/{id}/opportunities` | `sales.view` | |
| POST | `/api/v1/leads/{id}/opportunities` | `sales.manage` | Optional `products[]` |
| POST | `/api/v1/opportunities/{id}/products` | `sales.manage` | Add / update a line |
| DELETE | `/api/v1/opportunities/{id}/products/{productId}` | `sales.manage` | |
| POST | `/api/v1/opportunities/{id}/lost` | `sales.manage` | `reason` required |
| POST | `/api/v1/opportunities/{id}/sale` | `sales.manage` | Creates the Customer |
| GET · POST | `/api/v1/opportunities/{id}/quotations` | `sales.view` · `sales.manage` | |
| POST | `/api/v1/quotations/{id}/approve` · `/reject` | `discounts.approve` | Manager+ |
| POST | `/api/v1/quotations/{id}/issue` | `sales.manage` | |

**Opportunity `value` is never settable.** It is the sum of the product lines; a `value` in the request body is ignored. Each line's `unit_price` is snapshotted when added, so a later price change does not reprice an open deal.

**`lost` requires a reason from a closed list** — `price`, `competitor`, `no_budget`, `no_requirement`, `timing`, `no_response`, `unreachable`, `other`. `other` additionally requires `notes` (FR-SALE-05). A closed opportunity cannot be reopened; repeat business is a new one (BR-CUST-03).

**Discounts above `crm.discount_approval_threshold`** (default 15%) draft as `pending_approval` and cannot be issued until approved. **The approver may not be the person who raised the quotation** — a `403` even for a user holding `discounts.approve`. Approval and rejection are both written to `audit_logs` with the quotation number and discount.

**Quotation items are copied, not referenced.** An issued quotation is immutable.

**`POST /opportunities/{id}/sale` is the only thing that creates a Customer** (BR-CUST-01). The lead is not converted in place — it survives, and the customer links back via `origin_lead_id`. Customers are deduplicated on phone then email (BR-CUST-04). `sold_by` is fixed at the moment of sale so reassignment does not move the credit.

**This is what unblocks `Converted`.** `PATCH /leads/{id}/status` to `converted` returns `422 lead.invalid_status_transition` with `data.requires = "sale"` until a sale exists. ⚠️ The payment half of BR-PAY-05 is **not yet enforced** — see T-57.

### Follow-ups and notifications (Phase 21)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/follow-ups` | `follow_ups.view` | Mine, soonest first |
| GET | `/api/v1/leads/{id}/follow-ups` | `follow_ups.view` | Full history incl. reschedules |
| POST | `/api/v1/leads/{id}/follow-ups` | `follow_ups.manage` | Future-dated only |
| POST | `/api/v1/follow-ups/{id}/complete` | `follow_ups.manage` | Allowed on missed ones |
| POST | `/api/v1/follow-ups/{id}/reschedule` | `follow_ups.manage` | Returns the **replacement** |
| POST | `/api/v1/follow-ups/{id}/cancel` | `follow_ups.manage` | |
| GET | `/api/v1/notifications` | *(own records)* | `meta.unread_count` included |
| GET | `/api/v1/notifications/unread-count` | *(own records)* | Cheap poll for a badge |
| POST | `/api/v1/notifications/{id}/read` | *(own records)* | |
| POST | `/api/v1/notifications/read-all` | *(own records)* | |

**Scheduling twice reschedules.** A lead–product pair has at most one *open* follow-up (BR-FUP-01); a second `POST` closes the first and returns a new one rather than creating a duplicate.

**Reschedule returns a different id.** The original is closed and retained with `rescheduled_from_id` pointing back at it (BR-FUP-03) — editing the time in place would erase the record that a commitment moved.

**There is no endpoint to mark a follow-up missed.** `Missed` is set only by `crm:process-follow-ups`, which must be on the scheduler (DEPLOYMENT §6). A missed follow-up can still be completed — it is late, not void.

**Follow-up routes gate on the lead, not the assignee.** A follow-up is reachable exactly when its lead is, so out-of-scope is a `403` from `LeadPolicy`.

**Notification routes carry no permission** — they are the caller's own records, bound to the authenticated user (BR-NOTIF-01). Another user's notification is a **`404`**, not a `403`.

**Notifications are never DNC-filtered.** Suppression protects leads; these target staff.

### Messaging (Phases 13 + 15 — Email and SMS live; other channels are additional drivers)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/messages` | `leads.view` | Cross-lead history, scoped |
| GET | `/api/v1/leads/{id}/messages` | `leads.view` | One lead's history |
| POST | `/api/v1/leads/{id}/messages` | `messages.send` | `202` — always queued |
| POST | `/api/v1/webhooks/mailercloud` | *(shared secret)* | Delivery status; hard bounce and unsubscribe suppress (BR-DNC-07) |

Filters: `channel`, `status`, `direction`, `campaign_id` (and `lead_id` on the cross-lead route). Sorts: `created_at`, `sent_at`. Includes: `template`, `user`.

**Send body**: `channel` (required), then either `body` (+ `subject`, email only) or `template_id`. Sending a `subject` on a non-email channel is a `422` rather than a silent discard.

**Live channels**: `email` (Mailercloud), `sms` (BhashSMS). Everything else resolves to the log driver until its phase lands.

**Always `202`, never `200`.** Nothing sends inline in an HTTP request whatever its size (FR-COMM-01) — a provider timeout must not become the user's timeout.

**A suppressed lead returns `403 dnc.suppressed` *and* records a `skipped` message.** The error stops the caller assuming it went out; the skipped row is the audit trail (BR-DNC-05). Suppression is re-checked inside the job at dispatch time, so a lead who opts out while the message is queued is still not sent to (BR-DNC-03).

**`provider: "log"` means nothing was delivered.** A channel with no credentials falls back to a log driver so the whole path works before keys arrive; the response message says so explicitly. This is visible in the message row rather than silent.

**"Sent" is not "delivered".** `sent` means the provider accepted it. Delivery, opens and bounces arrive later on the webhook. An unrecognised webhook event is recorded on `provider_webhook_logs` and does **not** touch the message (FR-COMM-03).

**Provider feedback suppresses automatically (BR-DNC-07).** A **hard bounce** writes a `bounced_email` suppression, which blocks email and nothing else — a dead mailbox says nothing about the phone number. An **unsubscribe or spam complaint** writes an `opted_out` suppression **scoped to email**: `opted_out` is otherwise an absolute reason, and reading "never call me again" into a newsletter unsubscribe infers more than the click said. Both go through `DncService`, so the entries are audited and idempotent under provider retries.

**A bounce is only suppressed when it is confirmed hard.** `hard_bounce`, or `bounce`/`bounced` carrying a `bounce_type`/`type` of hard or permanent. An unqualified bounce is recorded and the address stays contactable — a full mailbox is temporary, and lifting a suppression needs Manager+ (BR-DNC-06), so the failure that costs less is the one to prefer. The message's `failure_reason` says which of the two happened.

**Message status vocabulary**: `queued` · `sent` · `delivered` · `read` · `replied` · `failed` · `bounced` · `skipped`. `bounced` is distinct from `failed` — the first means the provider took it and the address rejected it, the second means we could not hand it over.

### Settings and provider credentials

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/settings` | `settings.manage` | Grouped; secrets never returned |
| PATCH | `/api/v1/settings` | `settings.manage` + per-key | `{"settings": {"key": value}}` |

**An override layer, not a replacement for config.** `SettingsService::get()` reads the stored row and falls back to `config()`, so `.env` → `config/*` remains the source of defaults (SEC-CFG-01) while rotation stays possible without shell access (SEC-CFG-04). An empty `settings` table behaves exactly as the application did before the table existed.

**The registry is an allowlist.** `App\Support\SettingsRegistry` defines every settable key; anything else is a `422`. Without it this endpoint would be an arbitrary-config-write primitive (SEC-IN-06).

**Two permission classes through one endpoint.** Operational thresholds need `settings.manage`. Anything in a provider group needs `credentials.manage` — Super Admin only, and *not* held by Admin (SEC-AUTHZ-06). This is keyed off the **group**, not off whether the field is secret: an endpoint, a `key_id` and a gateway selector are all credential configuration even though none is a secret. Checked per key, and a payload mixing both classes is refused whole rather than partly applied.

**Secrets are never returned** — only `is_configured`, a non-reversible `hint` (last four characters, and only when the value is ≥ 12 long), and who changed it last (SEC-CFG-05). Values are encrypted at rest **unconditionally**, secret or not.

**`null` clears an override** so the config default applies again. An omitted key is left alone — which is what lets the screen submit only touched fields, and lets a blank secret input mean "keep the stored value".

**Every change is audited** as `setting_changed` or `credential_changed` with the key and never the value (SEC-AUD-02).

### Suppression / DNC (Phase 19 admin surface, brought forward)

| Method | Path | Permission | Notes |
|--------|------|-----------|-------|
| GET | `/api/v1/dnc` | `dnc.view` | Scoped through the lead |
| POST | `/api/v1/dnc` | `dnc.create` | Manual suppression; idempotent |
| DELETE | `/api/v1/dnc/{id}` | `dnc.remove` | Deactivates; **reason required** |

Filters: `reason`, `channel`, `source`, `active`, `lead_id`. Sorts: `created_at`, `removed_at`, `reason`. Includes: `lead`, `createdBy`, `removedBy`. Free-text `?q=` matches lead name, phone or email.

**`DELETE` does not delete.** The row is deactivated with `removed_by`, `removed_at` and `removal_reason` set, because the record of who lifted a suppression and why is the whole point of the audit trail (BR-DNC-06). A second removal on the same entry is a `422`, not a silent no-op — it would otherwise overwrite the first remover's identity.

**The removal reason is required** (5–255 chars). Actor and timestamp come from the server; the reason exists only if the caller supplies it.

**`channel: null` is not "all channels".** It means every channel the *reason* blocks (BR-DNC-02), which is why responses carry a resolved `blocked_channels` array — a `Wrong Number` entry blocks the six phone channels and leaves `email` contactable.

**Scope**: entries are visible through their lead, so a telecaller sees their own book only. Entries with no lead (an inbound STOP from an unknown number) are visible only at `All` scope.

**`dnc.remove` is in `Permission::isAudited()`**, so every removal writes an `audit_logs` row from the middleware before the controller runs (SEC-AUD-02, FR-DNC-04).

### Permission gate

Routes declare required permissions:

```php
Route::get(...)->middleware('permission:leads.view');
Route::post(...)->middleware('permission:leads.export,leads.import');   // any-of
```

This is a **coarse first gate**. Record-level checks (does this telecaller own *this* lead?) live in policies and query scopes — a route-level check alone leaves IDOR open (SEC-AUTHZ-04).

### Foundation behaviour now active on every route

| Behaviour | Implementation |
|-----------|----------------|
| Response envelope | `App\Support\ApiResponse` — the only place a response body is built |
| Error rendering | Exception handler in `bootstrap/app.php` maps every exception type into the envelope |
| Error codes | `App\Enums\ErrorCode` — each code owns its HTTP status, so a code can never be returned with an inconsistent one |
| Correlation ID | `X-Correlation-ID` on every response; an inbound value is preserved so a client can originate the trace |
| JSON enforcement | API routes always treated as JSON — no HTML error pages or login redirects reach an API client |
| Rate limiting | Named limiters `api-auth`, `api-standard`, `api-bulk`, `api-webhook` |
| List conventions | `App\Support\QueryOptions` — filter/sort/include with per-endpoint allowlists |

**Unknown filter, sort, or include fields return `422`** rather than being ignored. A silently dropped filter returns *more* rows than the caller asked for — on a scoped endpoint (a telecaller listing "my leads") that is a data-exposure bug, not a cosmetic one.

## 11. Planned Endpoint Groups

Documented in full — method, path, params, request/response, required permission — by the phase that implements each:

| Group | Phase |
|-------|-------|
| `/auth`, `/users`, `/roles` | 4 |
| `/products` | 5 |
| `/leads` (+ `/{id}/products`, `/notes`, `/activities`, `/import`) | 6–7 |
| `/calls`, `/auto-dialer`, `/call-recordings` | 9–11 |
| `/webhooks/meta` | 12 |
| `/communication/{email,whatsapp,sms,rcs,voice}`, `/templates` | 13–17 |
| `/campaigns` | 18 |
| `/dnc` | 19 |
| `/interested-leads` | 20 |
| `/follow-ups` | 21 |
| `/opportunities`, `/quotations`, `/sales` | 22 |
| `/payments` | 23 |
| `/ai-calls` | 24–25 |
| `/reports/{dashboard,telecaller,campaigns}` | 26–27 |

## 12. Documentation Tooling

*(Proposed)* OpenAPI spec generated from the codebase and kept in `docs/openapi.yaml`, with contract tests asserting responses match the spec — so documentation drift becomes a test failure rather than a discovery. Decide before Phase 3.
