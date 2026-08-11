# Security

| | |
|---|---|
| **Version** | 1.1 |
| **Last updated** | 2026-08-10 (Phase 1) |
| **Status** | Policy defined; **no controls implemented yet** — implementation begins Phase 3/4 |
| **Related** | [ARCHITECTURE.md](ARCHITECTURE.md) · [API_DOCUMENTATION.md](API_DOCUMENTATION.md) · [BUSINESS_RULES.md](BUSINESS_RULES.md) |

Controls carry stable IDs (`SEC-*`) so tests and reviews can cite them.

---

## 1. Principles

1. **The API is the only door.** No client — browser or mobile — reaches MySQL directly (NFR-01).
2. **Server-side enforcement only.** A hidden UI control is not access control; every rule is enforced at the API/service boundary.
3. **Secrets never enter the repository.** Not in code, config defaults, migrations, seeders, tests, or logs.
4. **Assume the client is hostile.** Validate everything; trust no client-supplied ID, role, price, or status.

---

## 2. Authentication

| ID | Control |
|----|---------|
| SEC-AUTH-01 | Laravel Sanctum: session cookies (Web CRM, same-origin) and personal access tokens (Flutter) |
| SEC-AUTH-02 | Passwords hashed with bcrypt/argon2 via Laravel's hasher; never stored or logged reversibly |
| SEC-AUTH-03 | Login throttling — 5 attempts/min per IP **and** per account; lockout is logged |
| SEC-AUTH-04 | Tokens are revocable per device; logout revokes the presenting token, not all sessions, unless "log out everywhere" is used |
| SEC-AUTH-05 | Token expiry + refresh policy for the mobile app *(duration to confirm — proposed 30 days idle)* |
| SEC-AUTH-06 | Password reset tokens are single-use and short-lived |
| SEC-AUTH-07 | 2FA for Admin/Super Admin *(proposed — confirm; recommended given the data sensitivity)* |

## 3. Authorization

| ID | Control |
|----|---------|
| SEC-AUTHZ-01 | RBAC per [PROJECT_REQUIREMENTS §2](PROJECT_REQUIREMENTS.md) (roles pending confirmation) |
| SEC-AUTHZ-02 | Laravel Policies/Gates checked in controllers or services — never only in Blade |
| SEC-AUTHZ-03 | **Data scoping**: Telecallers see only their assigned leads; Managers only their team. Enforced by query scopes, not by client-supplied filters |
| SEC-AUTHZ-04 | IDOR protection: every `/{id}` route re-verifies ownership/scope. A valid ID from another scope returns `403`/`404`, never data |
| SEC-AUTHZ-05 | Privilege escalation guard: role/permission assignment is Super Admin only; a user can never grant themselves a role |
| SEC-AUTHZ-06 | Sensitive actions require elevated roles: DNC removal, discount approval above threshold, recording access, credential config, data export |

## 4. Input & Output Safety

| ID | Control |
|----|---------|
| SEC-IN-01 | FormRequest validation on every write endpoint; explicit allowlists — never unguarded `$request->all()` mass assignment |
| SEC-IN-02 | `$fillable` (not `$guarded = []`) on every model |
| SEC-IN-03 | SQL injection: Eloquent/query builder with bindings only; no string-concatenated SQL. Raw expressions require review and parameter binding |
| SEC-IN-04 | XSS: Blade auto-escaping; `{!! !!}` forbidden on user-derived content. API Resources shape all output |
| SEC-IN-05 | CSRF enabled for session-authenticated Web CRM routes; token-authenticated and webhook routes exempt by design |
| SEC-IN-06 | Mass-assignment of business-critical fields (`status`, `score`, `is_suppressed`, `assigned_to`, prices) is blocked — these change only through their services |
| SEC-IN-07 | CSV import: formula injection prevention on export (`=`, `+`, `-`, `@` prefixes neutralised) and strict row validation on import |

## 5. File & Recording Security

| ID | Control |
|----|---------|
| SEC-FILE-01 | Uploads validated by MIME type, extension allowlist, and size cap; never trust the client-supplied filename |
| SEC-FILE-02 | Stored on a **private disk outside the public web root** — no guessable public URLs |
| SEC-FILE-03 | Call recordings served only via short-lived signed URLs to authorised roles (BR-REC-01) |
| SEC-FILE-04 | Every recording access is logged (who, which recording, when) |
| SEC-FILE-05 | Retention enforced by scheduled purge; deletions audited (BR-REC-02) |
| SEC-FILE-06 | Uploaded files are never executed or included; storage path is non-executable |

## 6. Webhooks

| ID | Control |
|----|---------|
| SEC-WH-01 | Signature/token verification **before** parsing or persisting business data — invalid → `401`, logged |
| SEC-WH-02 | Meta: `hub.verify_token` on subscription + `X-Hub-Signature-256` HMAC on delivery |
| SEC-WH-03 | Replay protection via unique `(provider, provider_event_id)` (FR-META-03) |
| SEC-WH-04 | Raw payloads stored for audit/replay, subject to retention and PII rules |
| SEC-WH-05 | Webhook endpoints are unauthenticated by necessity — therefore they do no privileged work inline; they only enqueue |

## 7. Secrets & Configuration

| ID | Control |
|----|---------|
| SEC-CFG-01 | All provider credentials (Mailercloud, WhatsApp, BhashSMS, RCS, Voice, Vaaad, Meta) resolve through **`config/*` defaults with an audited, encrypted `settings` override** — read via `SettingsService`, and via `config()` never `env()` outside config files. See §7A |
| SEC-CFG-02 | `.env` git-ignored; `.env.example` holds keys with **empty** values only |
| SEC-CFG-03 | `APP_DEBUG=false` in production; debug pages never exposed |
| SEC-CFG-04 | Credential rotation is possible without code changes |
| SEC-CFG-05 | Secrets are redacted in logs, exception reports, and HTTP client traces |
| SEC-CFG-06 | Stored credentials are encrypted at rest with `APP_KEY`, and an unreadable row degrades to the config default rather than taking the application down |
| SEC-CFG-07 | Decrypted credentials are never written to the cache store; they are resolved per request and held in memory only |

## 7A. Where credentials actually live *(amended 2026-08-12 — T-52)*

SEC-CFG-01 originally said `.env` → `config/*` and nothing else. That is no longer the whole
picture, and the document was behind the code.

**Two layers, in this order:**

1. **`settings` table** — an override. `value` is encrypted at rest, `is_secret` marks credentials,
   every write is audited with actor and key (SEC-AUD-02), and **the value is never written to the
   audit log**, not even for non-secrets.
2. **`config/*`, fed by `.env`** — the defaults, exactly as before.

An empty `settings` table behaves precisely as the application did before the table existed, and a
stored row explicitly cleared to null falls through to the config default rather than returning
null — so clearing a field in the UI restores shipped behaviour instead of breaking it.

**Why an override layer at all.** SEC-CFG-04 requires credential rotation without a code change,
and SEC-AUD-02 requires credential changes to be audited. `.env`-only satisfies neither on the
deployment target: editing `.env` on Hostinger shared hosting wants SSH (T-12), and a file edit
leaves no audit trail naming who rotated what.

### The trade this makes

**Credentials are now in the database, so they are in every database backup.** They are encrypted
with `APP_KEY`, which lives in `.env` — so a leaked dump alone does not yield plaintext. That
protection is only real if **backups and `.env` are not stored together**; put them in the same
bucket and the encryption buys nothing.

> **This was not true when first written (corrected 2026-08-12).** The settings service cached
> *decrypted* overrides with `Cache::rememberForever`, and the deployment target has no Redis, so
> `CACHE_STORE=database` (DEPLOYMENT §3A) wrote every provider credential into the `cache` table in
> plaintext — in the same database, and therefore in the same backups. The encryption on
> `settings.value` was being undone by a derived copy sitting beside it.
>
> Fixed under SEC-CFG-07: **non-secret** settings are still cached across requests; **secrets** are
> read from their encrypted rows and decrypted into memory for the life of one request only. The
> service is bound `scoped()` so a request shares one instance. A test asserts no cache row
> contains a credential, running against the `database` store rather than the array store the rest
> of the suite uses — on the array driver the bug is invisible.

### When APP_KEY and the data disagree

Restoring a production database into staging, or rotating `APP_KEY`, leaves rows this key cannot
decrypt. Handled explicitly (SEC-CFG-06):

- **Reads skip the unreadable row** and fall back to the config default, logging a warning naming
  the key and never the value. Overrides load in a single pass, so an unguarded failure would take
  *every* key down — both webhooks, both message drivers, and the settings screen itself.
- **Falling back fails closed.** An unset credential means the message driver resolves to the log
  driver and a webhook with no secret refuses every request. Degrading never opens anything.
- **Writes replace the row rather than updating it.** Eloquent computes what changed by comparing
  against the stored value, which decrypts it — so overwriting a bad credential would otherwise
  throw, and overwriting it is exactly the recovery an operator reaches for. A recovery path must
  never depend on the thing that is broken.

### Decision (2026-08-12): keep the override layer, with an operational requirement

Credentials stay in the database. The alternative, `.env`-only, costs the audit trail SEC-AUD-02
requires and rotation without SSH, and buys less than it looks: `.env` lands in file backups the
same way the database lands in database backups.

**The requirement that makes it sound is operational, not architectural:**

1. **Database backups and `.env` are never stored in the same place.** Same bucket, same archive,
   same laptop — and `APP_KEY` sits beside the ciphertext it opens, which is no encryption at all.
2. **`APP_KEY` is backed up separately, and kept.** Losing it does not lose the CRM, but it does
   lose every stored credential — they degrade to config defaults (SEC-CFG-06) and must be re-entered.
3. **Rotating `APP_KEY` means re-entering provider credentials.** Existing rows become unreadable
   by design. Plan it as a maintenance task, not a config tweak.

Revisit if the deployment target changes: on infrastructure with a real secrets manager, that is a
better home than either `.env` or the database.

## 8. Data Protection & PII

This system stores substantial personal data: names, phone numbers, emails, **call recordings**, and **AI call transcripts**.

| ID | Control |
|----|---------|
| SEC-PII-01 | TLS enforced in transit; HSTS enabled |
| SEC-PII-02 | Encryption at rest for recordings and transcripts *(mechanism to confirm — disk-level vs. application-level)* |
| SEC-PII-03 | Log redaction: phone numbers, emails, message bodies, and transcript content are not written to application logs in full |
| SEC-PII-04 | Data export (CSV) restricted to Manager+ and **audited** — bulk export of a lead database is the highest-value insider-threat action |
| SEC-PII-05 | Retention policy per data class (recordings, transcripts, webhook payloads, logs); purge jobs are scheduled and audited |
| SEC-PII-06 | Consent/opt-out state is authoritative and honoured across every channel (BR-DNC-01) |
| SEC-PII-07 | Recording consent/notification requirements are respected per applicable rules and device capability (BR-REC-03) |

## 9. Auditing

| ID | Control |
|----|---------|
| SEC-AUD-01 | `audit_logs` is append-only and immutable — no updates or deletes from application code |
| SEC-AUD-02 | Audited events at minimum: authentication (success/failure/lockout), role & permission changes, lead status changes, DNC add/remove, payment status changes, recording access, data export, provider credential changes |
| SEC-AUD-03 | Each entry records actor, action, target, before/after where applicable, IP, user agent, timestamp |
| SEC-AUD-04 | Audit logs are distinct from user-facing activity timeline — different purpose, different retention |

## 10. Operational

| ID | Control |
|----|---------|
| SEC-OPS-01 | Dependencies patched; `composer audit` in CI |
| SEC-OPS-02 | Security headers: HSTS, `X-Content-Type-Options`, `X-Frame-Options`/frame-ancestors, Referrer-Policy, CSP *(CSP scope to confirm — jQuery/Bootstrap inline usage may need care)* |
| SEC-OPS-03 | Generic error responses; stack traces and SQL never returned to clients (NFR-08) |
| SEC-OPS-04 | DB user holds least privilege — no `DROP`/`GRANT` in application credentials |
| SEC-OPS-05 | Backups encrypted, restore tested before production (Phase 29) |
| SEC-OPS-06 | Rate limiting per [API §7](API_DOCUMENTATION.md#7-rate-limiting) |

---

## 11. Threats Explicitly Considered

| Threat | Mitigation |
|--------|-----------|
| Telecaller exfiltrates the lead database | SEC-AUTHZ-03 scoping, SEC-PII-04 audited export restricted to Manager+ |
| Telecaller accesses another agent's leads by ID | SEC-AUTHZ-04 IDOR checks |
| Forged provider webhook injects fake leads or marks payments paid | SEC-WH-01/02 signature verification |
| Webhook replay duplicates leads/charges | SEC-WH-03 idempotency |
| Recording URL shared outside the org | SEC-FILE-03 short-lived signed URLs + SEC-FILE-04 access logging |
| Credentials leaked via logs or repo | SEC-CFG-02/05 |
| Contacting a suppressed lead (compliance exposure) | BR-DNC-01 single gate + per-channel tests |
| Mass-assignment sets `status`/`score`/`assigned_to` | SEC-IN-06 |

## 12. Pending Decisions

2FA for admins (SEC-AUTH-07) · token lifetime (SEC-AUTH-05) · encryption-at-rest mechanism (SEC-PII-02) · retention periods per data class (SEC-PII-05) · CSP strictness (SEC-OPS-02). Tracked in [TODO.md](TODO.md).
