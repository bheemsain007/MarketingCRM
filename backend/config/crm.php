<?php

/**
 * CRM business-rule configuration.
 *
 * Every tunable business rule lives here rather than in code, so changing a
 * threshold is a config change and not a deployment (BR-DNC-04).
 *
 * This is the ONLY layer allowed to call env(). Once config is cached in
 * production, env() returns null everywhere else - reading env() from a service
 * or controller is a bug that only appears after `config:cache` runs.
 *
 * Values marked PROPOSED are pending stakeholder sign-off - see docs/TODO.md.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Organisation
    |--------------------------------------------------------------------------
    | Rows are stored UTC; reports aggregate in the org timezone so dashboards
    | are internally consistent (GLOSSARY §2.1).
    */
    'timezone' => env('ORG_TIMEZONE', 'Asia/Kolkata'),

    /*
    |--------------------------------------------------------------------------
    | DNC matrix (BR-DNC-02/04, T-65)
    |--------------------------------------------------------------------------
    | Which channels each suppression reason blocks, as a comma-separated list
    | of Channel values. These are the DEFAULTS; the settings table may override
    | them at runtime, which is what BR-DNC-04 asks for.
    |
    | Only reasons DncReason::isConfigurable() allows appear here. `Do Not
    | Contact` and `Opted Out` are a person's explicit instruction, so they stay
    | absolute in code where no settings write can narrow them - see
    | App\Services\Dnc\SuppressionMatrix for the reasoning.
    |
    | An empty, missing or unparseable value falls back to the built-in list.
    | Suppression never narrows by accident.
    */
    'dnc' => [
        'matrix' => [
            // Our inference from one call, not the lead's own instruction -
            // which is exactly why it is tunable (BR-DNC-04's own example).
            'not_interested' => 'call,ai_call,sms,whatsapp,rcs,voice,email',

            // The phone is bad; the email address may be perfectly good.
            'wrong_number' => 'call,ai_call,sms,whatsapp,rcs,voice',
            'invalid_number' => 'call,ai_call,sms,whatsapp,rcs,voice',

            // A hard bounce says nothing about the phone number.
            'bounced_email' => 'email',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication (SEC-AUTH-*, T-10)
    |--------------------------------------------------------------------------
    | Personal access token lifetime for the Flutter app.
    |
    | Read here rather than in the service on purpose. `AuthService` called
    | `env('TOKEN_EXPIRY_DAYS')` directly, which works in development and
    | silently falls back to the hardcoded default once `config:cache` runs in
    | production - so an operator who set 90 days would have got 30 and no
    | indication why. Exactly the failure this file's header warns about.
    */
    'auth' => [
        'token_expiry_days' => (int) env('TOKEN_EXPIRY_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Calling (BR-CALL-04) - PROPOSED
    |--------------------------------------------------------------------------
    | Outbound calling and voice campaigns run only inside this window, in the
    | LEAD's timezone. Attempts outside it are deferred, never dropped.
    */
    'calling_hours' => [
        'start' => env('CALLING_HOURS_START', '09:00'),
        'end' => env('CALLING_HOURS_END', '20:00'),
    ],

    // Do not re-contact a lead within this many hours (auto-dialer skip rule).
    'contact_cooldown_hours' => (int) env('LEAD_CONTACT_COOLDOWN_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Auto dialer (FR-CALL-06/07, BR-CALL-02/03)
    |--------------------------------------------------------------------------
    */
    'dialer' => [
        // A run is a shift's worth of work, not the whole database. A queue
        // built once and worked for days would be stale by the second hour.
        'max_queue_size' => (int) env('DIALER_MAX_QUEUE_SIZE', 200),

        // How long a claimed lead stays claimed before another telecaller may
        // take it. Without this, one closed laptop locks a lead forever
        // (BR-CALL-03).
        'claim_ttl_minutes' => (int) env('DIALER_CLAIM_TTL_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaign frequency caps (BR-CAMP-04) - PROPOSED
    |--------------------------------------------------------------------------
    | Maximum campaign messages per lead per channel. Transactional/service
    | messages are exempt.
    */
    'campaign_caps' => [
        'per_day' => (int) env('CAMPAIGN_CAP_PER_DAY', 2),
        'per_week' => (int) env('CAMPAIGN_CAP_PER_WEEK', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead assignment (BR-ASSIGN-01/02) - PROPOSED
    |--------------------------------------------------------------------------
    */
    'assignment' => [
        // manual | round_robin | load_balanced | campaign_rule
        'method' => env('LEAD_ASSIGNMENT_METHOD', 'load_balanced'),
        // A telecaller at or above this many open leads is skipped by
        // auto-assignment. Unassignable leads queue and raise a notification -
        // they are never force-assigned to an overloaded agent.
        'open_lead_cap' => (int) env('TELECALLER_OPEN_LEAD_CAP', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead import (FR-LEAD-07)
    |--------------------------------------------------------------------------
    | The uploaded file lands on a PRIVATE disk - it is a bulk PII payload and
    | must never be reachable over HTTP (SEC-FILE-02), and it is deleted once
    | the retention window passes (SEC-PII-05).
    |
    | max_rows is a guard against a mis-selected file, not a licence limit. The
    | file is read row by row and never held in memory in full, so the ceiling
    | protects the queue and the database, not PHP's memory limit.
    */
    'imports' => [
        'disk' => env('LEAD_IMPORT_DISK', 'local'),
        'directory' => 'imports/leads',
        'max_file_kb' => (int) env('LEAD_IMPORT_MAX_FILE_KB', 10240),   // 10 MB
        'max_rows' => (int) env('LEAD_IMPORT_MAX_ROWS', 50000),
        // Row jobs are queued in chunks so the parent job never builds one
        // enormous payload on shared hosting (ARCH §4, `imports` queue).
        'chunk_size' => (int) env('LEAD_IMPORT_CHUNK_SIZE', 500),
        // Uploaded files are PII at rest. Kept only long enough to investigate
        // a bad import, then purged (SEC-PII-05).
        'file_retention_days' => (int) env('LEAD_IMPORT_FILE_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Follow-ups (BR-NOTIF-04) - PROPOSED
    |--------------------------------------------------------------------------
    */
    'follow_up' => [
        'reminder_lead_minutes' => (int) env('FOLLOWUP_REMINDER_LEAD_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Productivity tracking (GLOSSARY §2.3) - PROPOSED
    |--------------------------------------------------------------------------
    | A gap longer than this between tracked actions counts as idle time.
    | Writing notes counts as active - idle is not "not on a call".
    */
    'idle_threshold_minutes' => (int) env('IDLE_THRESHOLD_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Lead scoring (BR-SCORE-01) - PROPOSED
    |--------------------------------------------------------------------------
    | Score is 0-100, DERIVED from `interest_signals` rather than accumulated on
    | the lead, so re-weighting the model is a config change and a recompute
    | rather than a guess at history (T-16).
    |
    | The weights are here and not in the enum precisely because they are still
    | a proposal: changing what a connected call is worth must not be a
    | deployment.
    */
    'scoring' => [
        'signals' => [
            'call_connected' => 10,
            'interest_stated' => 25,
            // Multiplied by the AI's confidence (BR-INT-04).
            'ai_interest_detected' => 15,
            'inbound_reply' => 15,
            'email_opened' => 3,
            'email_clicked' => 8,
            'follow_up_completed' => 5,
            'proposal_sent' => 20,
            'negotiation_entered' => 25,
            'callback_requested' => 10,
            'call_no_answer' => -2,
            'follow_up_missed' => -5,
            'not_interested' => -20,
        ],

        // Cumulative floors for repeat negatives. Ten unanswered calls to a
        // genuinely busy prospect should not bury an otherwise warm lead.
        'caps' => [
            'call_no_answer' => -10,
        ],

        // -5 per 7 days with no engagement. Applied at recomputation time from
        // `last_engagement_at`, not stored as signals - decay is the absence of
        // events, and inventing rows for it would corrupt the explanation.
        'decay_points' => -5,
        'decay_every_days' => 7,

        'min' => 0,
        'max' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Interest engine (FR-INT-02) - PROPOSED
    |--------------------------------------------------------------------------
    */
    'interest' => [
        // Hours ahead to schedule a follow-up when a lead expresses interest.
        // Null disables the effect; the other six still apply (BR-INT-02).
        'auto_follow_up_hours' => env('INTEREST_AUTO_FOLLOW_UP_HOURS', 24),

        // The tag applied to interested leads, so the "All Interested" view and
        // campaign targeting can use one label.
        'label' => 'Interested',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI calling (BR-INT-04) - PROPOSED
    |--------------------------------------------------------------------------
    | AI-detected interest below this confidence is recorded as a signal but
    | does not by itself move lead status.
    */
    'ai_interest_confidence_threshold' => (float) env('AI_INTEREST_CONFIDENCE_THRESHOLD', 0.75),

    /*
    |--------------------------------------------------------------------------
    | Sales (BR-SALE-03) - PROPOSED
    |--------------------------------------------------------------------------
    | Discounts above this percentage require Manager+ approval.
    */
    'discount_approval_threshold' => (float) env('DISCOUNT_APPROVAL_THRESHOLD', 15),

    /*
    |--------------------------------------------------------------------------
    | Recordings (BR-REC-02)
    |--------------------------------------------------------------------------
    | Retention is finite by default; indefinite retention is never the default.
    */
    'recordings' => [
        'disk' => env('RECORDINGS_DISK', 'recordings'),
        'retention_days' => (int) env('RECORDINGS_RETENTION_DAYS', 365),
        // Lifetime of a signed recording URL (SEC-FILE-03).
        'signed_url_minutes' => (int) env('RECORDINGS_SIGNED_URL_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | API (API_DOCUMENTATION §4, §7)
    |--------------------------------------------------------------------------
    */
    'api' => [
        'default_per_page' => 25,
        'max_per_page' => 100,

        // Requests per minute. PROPOSED values (T-04).
        'rate_limits' => [
            'auth' => (int) env('RATE_LIMIT_AUTH', 5),
            'standard' => (int) env('RATE_LIMIT_STANDARD', 120),
            'bulk' => (int) env('RATE_LIMIT_BULK', 10),
            'webhook' => (int) env('RATE_LIMIT_WEBHOOK', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy (ADR-C)
    |--------------------------------------------------------------------------
    | Schema reservation only until Phase 36. 0 = the default tenant; real
    | tenant IDs begin at 1. Never null - a nullable tenant_id silently disables
    | every composite unique index that includes it.
    */
    'default_tenant_id' => 0,

];
