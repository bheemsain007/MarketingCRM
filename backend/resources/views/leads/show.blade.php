@extends('layouts.app')
@section('title', $lead->name)

@section('content')
@php
    // Archived state comes from the model's own soft delete, the same fact
    // LeadResource publishes as `is_archived`. Every write endpoint resolves
    // the lead by implicit binding and 404s on a trashed one, so the page shows
    // the record read-only rather than offering controls that cannot work.
    $isArchived = $lead->trashed();
@endphp

<div class="row g-3">
    {{-- ---------------------------------------------------------------- --}}
    {{-- Left: identity and status                                        --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h2 class="h5 mb-1">{{ $lead->name }}</h2>
                        @if ($lead->company)
                            <div class="text-muted small">{{ $lead->company }}</div>
                        @endif
                    </div>
                    <span class="badge badge-status text-bg-secondary" id="status-badge">
                        {{ $lead->status->label() }}
                    </span>
                </div>

                @if ($isArchived)
                    {{-- FR-LEAD-06. Archiving hides a lead; it deletes nothing,
                         and saying so is what stops somebody re-entering the
                         lead by hand because they think it is gone. --}}
                    <div class="alert alert-secondary py-2 px-3 small mt-3 mb-0" id="archived-banner">
                        <strong>Archived.</strong> This lead is out of every list, queue and dialler run.
                        Nothing has been deleted — restore it to work on it again.
                    </div>
                @endif

                @if ($lead->is_suppressed)
                    {{-- The flag is a cache; DncService is the authority. Shown
                         here as a warning, never used to decide anything. --}}
                    <div class="alert alert-danger py-2 px-3 small mt-3 mb-0">
                        <strong>Do not contact.</strong> This lead is suppressed on at least one channel.
                    </div>
                @endif

                <dl class="row small mt-3 mb-0">
                    <dt class="col-4 text-muted fw-normal">Phone</dt>
                    <dd class="col-8">{{ $lead->phone_e164 }}</dd>

                    <dt class="col-4 text-muted fw-normal">Email</dt>
                    <dd class="col-8">{{ $lead->email ?: '—' }}</dd>

                    <dt class="col-4 text-muted fw-normal">Location</dt>
                    <dd class="col-8">{{ collect([$lead->city, $lead->state])->filter()->join(', ') ?: '—' }}</dd>

                    <dt class="col-4 text-muted fw-normal">Owner</dt>
                    <dd class="col-8">{{ $lead->assignedUser?->name ?? 'Unassigned' }}</dd>

                    <dt class="col-4 text-muted fw-normal">Created</dt>
                    <dd class="col-8">{{ $lead->created_at?->format('d M Y') }}</dd>
                </dl>

                <div class="mt-3 d-flex flex-wrap gap-1">
                    @unless ($isArchived)
                        @permission('leads.update')
                            <a href="{{ route('web.leads.edit', $lead) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil me-1"></i>Edit details
                            </a>
                        @endpermission
                    @endunless

                    {{--
                        Archive / restore (FR-LEAD-06, SEC-AUTHZ-04).

                        One control with two faces, driven by the lead's own
                        archived state: offering "Archive" on a lead that is
                        already archived is how you get somebody clicking it and
                        deciding the page is broken.
                    --}}
                    @permission('leads.archive')
                        @if ($isArchived)
                            {{-- Restoring is recoverable and expected, so it is a
                                 plain button; archiving is the one that asks. --}}
                            <button class="btn btn-sm btn-outline-success" id="lead-restore">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Restore lead
                            </button>
                        @else
                            <button class="btn btn-sm btn-outline-danger" id="lead-archive"
                                    data-bs-toggle="modal" data-bs-target="#archive-modal">
                                <i class="bi bi-archive me-1"></i>Archive lead
                            </button>
                        @endif
                    @endpermission
                </div>
            </div>
        </div>

        {{-- Nothing below is drawn for an archived lead: every /leads/{lead}/*
             endpoint 404s on a trashed one, so these cards could only fail. --}}
        @unless ($isArchived)
        {{--
            Why this lead has the score it has (BR-SCORE-01, FR-INT-01).

            The requirement is that a telecaller can see *why* a lead is Hot. A
            bare number cannot answer that, and a score nobody understands is a
            score nobody trusts - so the breakdown the API computed is drawn in
            full, decay included.
        --}}
        <div class="card mb-3" id="score-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <h3 class="h6 mb-0">Interest score</h3>
                    <span class="badge text-bg-secondary" id="score-temperature">…</span>
                </div>

                <div class="d-flex align-items-baseline gap-2 mt-2">
                    <span class="h3 mb-0" id="score-value">—</span>
                    <span class="text-muted small" id="score-raw"></span>
                </div>

                <div class="progress mt-2" style="height:.4rem">
                    <div class="progress-bar" id="score-bar" style="width:0"></div>
                </div>

                <div id="score-lines" class="mt-3">
                    <p class="text-muted small mb-0">Loading…</p>
                </div>

                <div class="text-muted mt-2" style="font-size:.78rem" id="score-engagement"></div>

                @permission('leads.update')
                    {{--
                        The dedicated interest signal (FR-INT-01), which is what
                        feeds the number above. Distinct from the Products tab's
                        per-product interest state: that records WHAT they want,
                        this records that they said so, and only this one scores.
                    --}}
                    <button class="btn btn-sm btn-outline-primary mt-3" id="signal-open"
                            data-bs-toggle="modal" data-bs-target="#signal-modal">
                        <i class="bi bi-graph-up-arrow me-1"></i>Record an interest signal
                    </button>
                @endpermission
            </div>
        </div>

        @permission('leads.update')
        <div class="card mb-3">
            <div class="card-body">
                <h3 class="h6">Change status</h3>

                {{--
                    The options come from /transitions, not from the full status
                    list: the API returns only the moves this caller can
                    actually make, so the UI cannot offer a button that is
                    guaranteed to fail (BR-STAT-02).
                --}}
                <div id="transition-empty" class="text-muted small d-none">
                    No status changes are available to you for this lead.
                </div>

                <div id="transition-form">
                    <select id="next-status" class="form-select form-select-sm mb-2"></select>

                    <div id="reason-wrap" class="mb-2 d-none">
                        <label class="form-label small mb-1" for="status-reason">Reason (required to reopen)</label>
                        <input type="text" id="status-reason" class="form-control form-control-sm"
                               maxlength="255" placeholder="Why is this lead being reopened?">
                    </div>

                    <button id="apply-status" class="btn btn-sm btn-primary w-100">Update status</button>
                </div>
            </div>
        </div>
        @endpermission

        @permission('leads.assign')
        {{--
            Assignment is supervisory and has its own permission, so this card
            is absent for a telecaller entirely - they must not be able to push
            a difficult lead onto a colleague or claim someone else's
            (BR-ASSIGN-05).
        --}}
        <div class="card mb-3">
            <div class="card-body">
                <h3 class="h6">Assignment</h3>

                <div class="small text-muted mb-2">
                    Currently: <span id="current-owner" class="fw-semibold text-body">
                        {{ $lead->assignedUser?->name ?? 'Unassigned' }}
                    </span>
                </div>

                {{-- Open-lead counts come with the list, because "who is free?"
                     is the actual question a manager is asking here
                     (BR-ASSIGN-02). --}}
                <select id="assignee" class="form-select form-select-sm mb-2">
                    <option value="">Loading…</option>
                </select>

                <input type="text" id="assign-reason" class="form-control form-control-sm mb-2"
                       maxlength="255" placeholder="Reason (optional)">

                <div class="d-grid gap-1">
                    <button id="do-assign" class="btn btn-sm btn-primary">Assign</button>
                    <button id="do-auto-assign" class="btn btn-sm btn-outline-secondary">Auto-assign</button>
                    <button id="do-unassign" class="btn btn-sm btn-outline-danger">Return to pool</button>
                </div>
            </div>
        </div>
        @endpermission
        @endunless
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Right: products, notes, history                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="col-lg-8">
        @if ($isArchived)
            {{--
                Every /leads/{lead}/* endpoint resolves the lead by implicit
                binding, so an archived lead has no working data endpoints at
                all - calls, notes, timeline and score would each answer 404.
                Saying that plainly beats a page of tabs quietly failing to
                fill (ADR-A: the page never queries the database itself).
            --}}
            <div class="card">
                <div class="card-body">
                    <h3 class="h6">This lead's history is hidden while it is archived</h3>
                    <p class="small text-muted mb-0">
                        Calls, notes, messages, follow-ups, deals and the interest score are all kept and
                        none of them have been deleted. Restore the lead to work on it and to see them
                        again.
                    </p>
                </div>
            </div>
        @else
        <div class="card">
            <div class="card-header p-0">
                <ul class="nav nav-tabs card-header-tabs m-0 px-2 pt-2" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-calls" type="button">Calls</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-products" type="button">Products</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-notes" type="button">Notes</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-timeline" type="button">Timeline</button></li>
                    @permission('follow_ups.view')
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-followups" type="button">Follow-ups</button></li>
                    @endpermission
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-messages" type="button">Messages</button></li>
                    @permission('sales.view')
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-deals" type="button">Deals</button></li>
                    @endpermission
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-history" type="button">Status history</button></li>
                </ul>
            </div>

            <div class="card-body tab-content">
                {{-- Calls --------------------------------------------------- --}}
                <div class="tab-pane fade show active" id="tab-calls">
                    @permission('calls.create')
                    {{--
                        The button state comes from /callability, so a
                        telecaller sees *why* a lead cannot be called before
                        trying — suppression or calling hours, with the time it
                        reopens (FR-CALL-08, BR-CALL-04).
                    --}}
                    <div id="call-blocked" class="alert alert-warning py-2 px-3 small d-none"></div>

                    {{--
                        AI calling being unconfigured is a fact about the
                        installation, true until somebody adds the credential in
                        Settings - so it stays on the page instead of flashing
                        past in a toast (SEC-CFG-04).
                    --}}
                    <div id="ai-call-blocked" class="alert alert-secondary py-2 px-3 small d-none"></div>

                    <div class="row g-2 align-items-end border-bottom pb-3 mb-3" id="call-form">
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="call-outcome">Log a call</label>
                            <select id="call-outcome" class="form-select form-select-sm">
                                <option value="">Outcome…</option>
                                @foreach (\App\Enums\CallStatus::cases() as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 d-none" id="callback-wrap">
                            <label class="form-label small mb-1" for="callback-at">Call back at</label>
                            <input type="datetime-local" id="callback-at" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="call-duration">Duration (s)</label>
                            <input type="number" id="call-duration" class="form-control form-control-sm" min="0">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button id="log-call" class="btn btn-sm btn-primary">Log</button>
                        </div>
                        <div class="col-12">
                            <input type="text" id="call-notes" class="form-control form-control-sm mt-1"
                                   maxlength="5000" placeholder="Notes (optional)">
                        </div>

                        {{--
                            The AI call (FR-AI-01) lives INSIDE #call-form on
                            purpose. That container is shown and hidden by the
                            /callability verdict, so DNC and calling hours gate
                            the AI dial with the same answer that gates the
                            manual one - a second check here is a second place
                            for BR-CALL-04 to drift.
                        --}}
                        <div class="col-md-9">
                            <input type="text" id="ai-script" class="form-control form-control-sm mt-1"
                                   maxlength="2000"
                                   placeholder="Script for the AI (optional — blank uses the configured one)">
                        </div>
                        <div class="col-md-3 d-grid">
                            <button id="ai-call" class="btn btn-sm btn-outline-primary mt-1">
                                <i class="bi bi-robot me-1"></i>AI call
                            </button>
                        </div>
                    </div>
                    @endpermission

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="small text-muted">
                            <tr><th>When</th><th>Outcome</th><th>Duration</th><th>By</th><th>Notes</th>
                                {{-- Recordings are a separate, audited permission
                                     (SEC-FILE-04): a role that may not listen does
                                     not get a column telling it audio exists. --}}
                                @permission('recordings.listen')<th>Recording</th>@endpermission
                            </tr>
                            </thead>
                            <tbody id="call-rows"></tbody>
                        </table>
                    </div>
                </div>

                {{-- Products ------------------------------------------------ --}}
                <div class="tab-pane fade" id="tab-products">
                    <p class="text-muted small">
                        Each product carries its own interest state. Changing one never affects another,
                        and declining one product does not suppress the lead.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="small text-muted">
                            <tr><th>Product</th><th>Interest</th><th>Quoted</th><th></th></tr>
                            </thead>
                            <tbody id="product-rows"></tbody>
                        </table>
                    </div>

                    @permission('leads.update')
                    <div class="row g-2 align-items-end border-top pt-3">
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="new-product">Add interest</label>
                            <select id="new-product" class="form-select form-select-sm">
                                <option value="">Choose a product…</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="new-product-status">Starting at</label>
                            <select id="new-product-status" class="form-select form-select-sm">
                                @foreach ($statuses as $status)
                                    @if (! in_array($status->value, ['converted', 'lost', 'not_interested']))
                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3 d-grid">
                            <button id="add-product" class="btn btn-sm btn-outline-primary">Add</button>
                        </div>
                    </div>
                    @endpermission
                </div>

                {{-- Notes --------------------------------------------------- --}}
                <div class="tab-pane fade" id="tab-notes">
                    @permission('leads.update')
                    <div class="mb-3">
                        <textarea id="note-body" class="form-control form-control-sm" rows="3"
                                  maxlength="5000" placeholder="Add a note…"></textarea>
                        <button id="add-note" class="btn btn-sm btn-primary mt-2">Save note</button>
                    </div>
                    @endpermission

                    <div id="note-list"></div>
                </div>

                {{-- Timeline ------------------------------------------------ --}}
                <div class="tab-pane fade" id="tab-timeline">
                    <p class="text-muted small">
                        Everything that has happened to this lead, in one chronological view.
                    </p>
                    <div id="timeline-list"></div>
                </div>

                {{-- Status history ------------------------------------------ --}}
                {{-- Follow-ups (Phase 21) -------------------------------- --}}
                @permission('follow_ups.view')
                <div class="tab-pane fade" id="tab-followups">
                    @permission('follow_ups.manage')
                    <div class="row g-2 align-items-end border-bottom pb-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small mb-1" for="fu-when">Schedule a follow-up</label>
                            <input type="datetime-local" id="fu-when" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="fu-channel">Channel</label>
                            <select id="fu-channel" class="form-select form-select-sm">
                                @foreach (\App\Enums\Channel::cases() as $case)
                                    <option value="{{ $case->value }}" @selected($case === \App\Enums\Channel::Call)>
                                        {{ $case->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="fu-subject">Subject</label>
                            <input type="text" id="fu-subject" class="form-control form-control-sm" maxlength="190">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button id="fu-create" class="btn btn-sm btn-primary">Schedule</button>
                        </div>
                    </div>
                    {{-- One open follow-up per lead-product (BR-FUP-01), so a
                         second schedule reschedules rather than duplicating. --}}
                    <p class="text-muted small">
                        Scheduling again moves the open follow-up rather than adding a second one.
                        The previous time is kept in the history below.
                    </p>
                    @endpermission

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="small text-muted">
                            <tr><th>Due</th><th>Channel</th><th>Subject</th><th>Owner</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody id="followup-rows"></tbody>
                        </table>
                    </div>
                </div>
                @endpermission

                {{-- Messages (Phases 13 + 15) ------------------------------ --}}
                <div class="tab-pane fade" id="tab-messages">
                    @permission('messages.send')
                    <div id="message-blocked" class="alert alert-warning py-2 px-3 small d-none"></div>

                    <div class="row g-2 align-items-end border-bottom pb-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small mb-1" for="msg-channel">Send</label>
                            <select id="msg-channel" class="form-select form-select-sm">
                                @foreach (\App\Enums\Channel::cases() as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-7" id="msg-subject-wrap">
                            <label class="form-label small mb-1" for="msg-subject">Subject</label>
                            <input type="text" id="msg-subject" class="form-control form-control-sm" maxlength="255">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button id="msg-send" class="btn btn-sm btn-primary">Send</button>
                        </div>
                        <div class="col-12">
                            <textarea id="msg-body" class="form-control form-control-sm mt-1" rows="3"
                                      placeholder="Message"></textarea>
                        </div>
                    </div>
                    @endpermission

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="small text-muted">
                            <tr><th>When</th><th>Channel</th><th>Subject</th><th>Status</th><th>Provider</th></tr>
                            </thead>
                            <tbody id="message-rows"></tbody>
                        </table>
                    </div>
                </div>

                {{-- Deals (Phases 22 + 23) --------------------------------- --}}
                @permission('sales.view')
                <div class="tab-pane fade" id="tab-deals">
                    @permission('sales.manage')
                    <div class="row g-2 align-items-end border-bottom pb-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label small mb-1" for="opp-title">Open an opportunity</label>
                            <input type="text" id="opp-title" class="form-control form-control-sm" maxlength="190"
                                   placeholder="What is the deal?">
                        </div>
                        <div class="col-md-4 d-grid">
                            <button id="opp-create" class="btn btn-sm btn-primary">Open</button>
                        </div>
                    </div>
                    @endpermission

                    <div id="deal-list"></div>

                    {{--
                        Sale and payment controls are drawn by JS into each deal,
                        so the flags the page needs are declared once here.
                        `canManage` opens deals and records sales; `canTakeMoney`
                        is the Accounts power (payments.manage), which a
                        telecaller does not hold; `canRefund` is separate again
                        (SEC-AUTHZ-06).
                    --}}
                    <script>
                        const canManageSales = @json(auth()->user()->hasPermission('sales.manage'));
                        const canTakeMoney = @json(auth()->user()->hasPermission('payments.manage'));
                        const canSeeMoney = @json(auth()->user()->hasPermission('payments.view'));
                        const canApproveDiscount = @json(auth()->user()->hasPermission('discounts.approve'));
                    </script>
                </div>
                @endpermission

                <div class="tab-pane fade" id="tab-history">
                    <p class="text-muted small">
                        Append-only. Never edited, never deleted — this is the record consulted when a
                        conversion is disputed.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="small text-muted">
                            <tr><th>When</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr>
                            </thead>
                            <tbody id="history-rows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

{{-- ---------------------------------------------------------------------- --}}
{{-- Modals                                                                  --}}
{{-- ---------------------------------------------------------------------- --}}

@permission('leads.archive')
@unless ($isArchived)
    {{-- Archiving reads as destructive even though it is not, so it asks first
         and says plainly what it does and does not do (FR-LEAD-06). --}}
    <div class="modal fade" id="archive-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Archive this lead</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small mb-2">
                        <strong>{{ $lead->name }}</strong> leaves every list, queue and dialler run.
                    </p>
                    <p class="small text-muted mb-3">
                        Nothing is deleted. Calls, notes, messages and status history are kept, and the
                        lead can be restored from this page.
                    </p>

                    <label class="form-label small mb-1" for="archive-reason">Reason (optional)</label>
                    <input type="text" id="archive-reason" class="form-control form-control-sm" maxlength="255"
                           placeholder="Why is this lead being archived?">
                    <div class="invalid-feedback"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="archive-confirm">Archive lead</button>
                </div>
            </div>
        </div>
    </div>
@endunless
@endpermission

@unless ($isArchived)
@permission('leads.update')
    {{--
        Manual interest capture (FR-INT-01, BR-INT-01).

        Four fields, so a modal rather than an inline row. This is the SCORING
        signal and is deliberately separate from the Products tab's interest
        state: one records that the lead said something, the other records which
        product they said it about.
    --}}
    <div class="modal fade" id="signal-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Record an interest signal</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small mb-1" for="signal-type">What happened</label>
                            <select id="signal-type" class="form-select form-select-sm">
                                @foreach (\App\Enums\InterestSignalType::cases() as $case)
                                    {{-- The AI signal is omitted: it carries a model
                                         confidence that only Vaaad can supply, and a
                                         human claiming one would be fabricating
                                         evidence the score is weighted by (BR-INT-04). --}}
                                    @unless ($case->isAiDetected())
                                        <option value="{{ $case->value }}"
                                                @selected($case === \App\Enums\InterestSignalType::InterestStated)>
                                            {{ $case->label() }}
                                        </option>
                                    @endunless
                                @endforeach
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-7">
                            <label class="form-label small mb-1" for="signal-product">Product (optional)</label>
                            <select id="signal-product" class="form-select form-select-sm">
                                <option value="">Not product-specific</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="signal-channel">Channel (optional)</label>
                            <select id="signal-channel" class="form-select form-select-sm">
                                <option value="">—</option>
                                @foreach (\App\Enums\Channel::cases() as $case)
                                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small mb-1" for="signal-excerpt">What they said (optional)</label>
                            <textarea id="signal-excerpt" class="form-control form-control-sm" rows="2"
                                      maxlength="2000" placeholder="Their words, so the score can be defended later"></textarea>
                            <div class="invalid-feedback"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" id="signal-save">Record signal</button>
                </div>
            </div>
        </div>
    </div>
@endpermission
@endunless

@permission('recordings.delete')
    {{-- Deleting a recording destroys evidence, so it asks - and says what
         survives, because the row does (BR-REC-02). --}}
    <div class="modal fade" id="rec-delete-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Delete this recording</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small mb-2" id="rec-delete-call"></p>
                    <p class="small text-muted mb-0">
                        The audio is deleted permanently and cannot be recovered. The call itself, and the
                        record that it was recorded and then deleted, are kept.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="rec-delete-confirm">Delete audio</button>
                </div>
            </div>
        </div>
    </div>
@endpermission
@endsection

@push('scripts')
<script>
$(function () {
    const leadId = {{ $lead->id }};
    const base = '/api/v1/leads/' + leadId;

    // Read into JS because these decide markup that JS draws, not markup Blade
    // draws. Hiding a control is usability only - the route middleware and the
    // policies are what actually refuse the action.
    const isArchived = @json($isArchived);
    const canListen = @json(auth()->user()->hasPermission('recordings.listen'));
    const canDeleteRecording = @json(auth()->user()->hasPermission('recordings.delete'));

    // The Recording column only exists for a role that may listen, so the
    // "no calls yet" row has to span a different number of cells for each.
    const callColumns = canListen ? 6 : 5;

    // ------------------------------------------------------------ assignment
    // The card is only rendered for holders of leads.assign, so everything
    // here is a no-op for everyone else.
    if ($('#assignee').length) {
        function loadAssignees() {
            $.getJSON('/api/v1/leads/assignees')
                .done(function (response) {
                    const people = response.data;

                    if (!people.length) {
                        // Nobody eligible is a real operational state, not an
                        // error: everyone is at the open-lead cap or inactive
                        // (BR-ASSIGN-02).
                        $('#assignee').html('<option value="">No telecaller can take more work</option>');
                        $('#do-assign').prop('disabled', true);
                        return;
                    }

                    $('#assignee').html(people.map(function (person) {
                        return '<option value="' + person.id + '">'
                            + CRM.escape(person.name)
                            + ' (' + person.open_lead_count + ' open)'
                            + '</option>';
                    }).join(''));
                    $('#do-assign').prop('disabled', false);
                })
                .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
        }

        function afterAssignment(response, message) {
            const owner = response.data.assigned_to;
            $('#current-owner').text(owner ? owner.name : 'Unassigned');
            $('#assign-reason').val('');
            CRM.alert(message, 'success');
            loadAssignees();   // The counts just moved.
            loadTimeline();
        }

        function post(path, payload, fallbackMessage) {
            $.ajax({ url: base + path, method: 'POST', data: payload })
                .done(function (response) { afterAssignment(response, response.message || fallbackMessage); })
                .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
        }

        $('#do-assign').on('click', function () {
            const userId = $('#assignee').val();
            if (!userId) return;

            const payload = { user_id: userId };
            const reason = $.trim($('#assign-reason').val());
            if (reason) payload.reason = reason;

            post('/assign', payload, 'Lead assigned.');
        });

        // Auto-assign can legitimately assign nobody; the API says so in its
        // message, which is why the response message wins over the fallback.
        $('#do-auto-assign').on('click', function () {
            post('/auto-assign', {}, 'Lead assigned.');
        });

        $('#do-unassign').on('click', function () {
            const reason = $.trim($('#assign-reason').val());
            post('/unassign', reason ? { reason: reason } : {}, 'Lead unassigned.');
        });

        loadAssignees();
    }

    // ----------------------------------------------------------------- score
    // BR-SCORE-01: the score is DERIVED from the signals every time, so the
    // breakdown below is the same arithmetic the number came from - not a
    // second, drifting explanation of it.
    const temperatureTone = { hot: 'danger', warm: 'warning', cold: 'info', dormant: 'secondary' };
    const temperatureLabel = { hot: 'Hot', warm: 'Warm', cold: 'Cold', dormant: 'Dormant' };

    function loadScore() {
        $.getJSON(base + '/score').done(function (response) {
            const data = response.data;
            const tone = temperatureTone[data.temperature] || 'secondary';

            $('#score-value').text(data.score);

            // The pre-clamp total is only worth showing when it differs: a lead
            // sitting well above 100 should not look identical to one exactly at
            // it, and one below 0 should not look merely cold.
            $('#score-raw').text(data.raw === data.score ? '/ 100' : '/ 100 · ' + data.raw + ' before clamping');

            $('#score-bar')
                .attr('class', 'progress-bar bg-' + tone)
                .css('width', Math.max(0, Math.min(100, data.score)) + '%');

            $('#score-temperature')
                .attr('class', 'badge text-bg-' + tone)
                .text(temperatureLabel[data.temperature] || data.temperature);

            // Biggest contribution first: the question this panel answers is
            // "why is this lead hot", and the answer is at the top.
            const lines = (data.signals || []).slice().sort(function (a, b) { return b.points - a.points; });

            const rows = lines.map(function (line) {
                return '<div class="d-flex justify-content-between align-items-center small border-top py-1">'
                    + '<span>' + CRM.escape(line.label)
                    + (line.count > 1 ? ' <span class="text-muted">×' + line.count + '</span>' : '')
                    // A cap is why the column does not add up. Saying so is
                    // cheaper than letting somebody re-derive it (BR-SCORE-01).
                    + (line.capped ? ' <span class="badge text-bg-light text-muted fw-normal">capped</span>' : '')
                    + '</span>'
                    + '<span class="' + (line.points < 0 ? 'text-danger' : 'text-success') + '">'
                    + (line.points > 0 ? '+' : '') + line.points + '</span>'
                    + '</div>';
            }).join('');

            // Decay is drawn apart from the signals because it IS apart: it is
            // the absence of events, computed rather than recorded, and showing
            // it as a signal would invent a row that does not exist.
            const decay = data.decay
                ? '<div class="d-flex justify-content-between small border-top py-1">'
                  + '<span class="fst-italic text-muted">Decay for silence</span>'
                  + '<span class="text-danger">' + data.decay + '</span></div>'
                : '';

            $('#score-lines').html((rows + decay)
                || '<p class="text-muted small mb-0">No interest signals recorded yet.</p>');

            // `last_engagement_at` tracks INBOUND signals only, so "never" here
            // does not mean nobody has worked the lead.
            $('#score-engagement').text(data.last_engagement_at
                ? 'Last engagement ' + data.last_engagement_at.substring(0, 16).replace('T', ' ')
                : 'No inbound engagement recorded.');
        }).fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    // ------------------------------------------------------- interest signals
    if ($('#signal-save').length) {
        const signalModal = new bootstrap.Modal(document.getElementById('signal-modal'));
        const signalFields = {
            type: '#signal-type',
            product_id: '#signal-product',
            channel: '#signal-channel',
            excerpt: '#signal-excerpt',
        };

        $('#signal-save').on('click', function () {
            const $button = $(this).prop('disabled', true);
            $('#signal-modal .is-invalid').removeClass('is-invalid');

            // Blank optionals are omitted rather than sent empty - the API
            // validates them as enums when present, and '' is not one.
            const payload = { type: $('#signal-type').val() };
            const productId = $('#signal-product').val();
            if (productId) payload.product_id = productId;
            const channel = $('#signal-channel').val();
            if (channel) payload.channel = channel;
            const excerpt = $.trim($('#signal-excerpt').val());
            if (excerpt) payload.excerpt = excerpt;

            $.ajax({ url: base + '/interest', method: 'POST', data: payload })
                .done(function (response) {
                    signalModal.hide();
                    $('#signal-excerpt').val('');

                    // `acted_on: false` means it was kept as evidence but moved
                    // nothing (BR-INT-04). Saying so avoids "I recorded it and
                    // the page did not change".
                    CRM.alert(response.data.signal.acted_on
                        ? response.message
                        : 'Signal recorded as evidence, but it did not move the lead.', 'success');

                    loadScore();        // The point of the control: show the effect.
                    loadTransitions();  // Interest can promote status (FR-INT-02).
                    loadProducts();
                    loadTimeline();
                })
                .fail(function (xhr) {
                    const errors = (xhr.responseJSON || {}).errors || [];
                    const fieldError = errors.find(e => signalFields[e.field]);

                    if (fieldError) {
                        const $field = $(signalFields[fieldError.field]);
                        $field.addClass('is-invalid');
                        $field.siblings('.invalid-feedback').text(fieldError.message);
                    } else {
                        signalModal.hide();
                        CRM.alert(CRM.errorFrom(xhr));
                    }
                })
                .always(function () { $button.prop('disabled', false); });
        });
    }

    // ------------------------------------------------------ archive / restore
    if ($('#archive-confirm').length) {
        const archiveModal = new bootstrap.Modal(document.getElementById('archive-modal'));

        $('#archive-confirm').on('click', function () {
            const $button = $(this).prop('disabled', true);
            $('#archive-reason').removeClass('is-invalid');

            const reason = $.trim($('#archive-reason').val());

            $.ajax({ url: base, method: 'DELETE', data: reason ? { reason: reason } : {} })
                .done(function () {
                    // Reloaded rather than sent back to the list: this page is
                    // where the lead is restored from, so landing on it with
                    // Restore offered IS the undo path.
                    window.location.reload();
                })
                .fail(function (xhr) {
                    const errors = (xhr.responseJSON || {}).errors || [];
                    const fieldError = errors.find(e => e.field === 'reason');

                    if (fieldError) {
                        $('#archive-reason').addClass('is-invalid');
                        $('#archive-modal .invalid-feedback').text(fieldError.message);
                    } else {
                        archiveModal.hide();
                        CRM.alert(CRM.errorFrom(xhr));
                    }
                })
                .always(function () { $button.prop('disabled', false); });
        });
    }

    $('#lead-restore').on('click', function () {
        const $button = $(this).prop('disabled', true);

        // Restore is addressed by id, not by the bound model: the lead is
        // trashed, so implicit binding would never find it.
        $.ajax({ url: '/api/v1/leads/' + leadId + '/restore', method: 'POST' })
            .done(function () { window.location.reload(); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $button.prop('disabled', false);
            });
    });

    // ---------------------------------------------------------------- status
    function loadTransitions() {
        $.getJSON(base + '/transitions').done(function (response) {
            const available = response.data.available;

            $('#status-badge')
                .attr('class', 'badge badge-status text-bg-' + CRM.statusClass(response.data.current.status))
                .text(response.data.current.label);

            if (!available.length) {
                $('#transition-form').addClass('d-none');
                $('#transition-empty').removeClass('d-none');
                return;
            }

            $('#transition-form').removeClass('d-none');
            $('#transition-empty').addClass('d-none');

            $('#next-status').html(available.map(function (option) {
                return '<option value="' + option.status + '" data-reopen="' + option.is_reopen + '">'
                    + CRM.escape(option.label) + '</option>';
            }).join(''));

            toggleReason();
        });
    }

    // A reopen needs a written reason; the field appears only when it applies.
    function toggleReason() {
        const isReopen = $('#next-status option:selected').data('reopen') === true;
        $('#reason-wrap').toggleClass('d-none', !isReopen);
    }

    $('#next-status').on('change', toggleReason);

    $('#apply-status').on('click', function () {
        const payload = { status: $('#next-status').val() };
        const reason = $('#status-reason').val();
        if (reason) payload.reason = reason;

        $.ajax({ url: base + '/status', method: 'PATCH', data: payload })
            .done(function () {
                CRM.alert('Status updated.', 'success');
                $('#status-reason').val('');
                loadTransitions();
                loadHistory();
                loadTimeline();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // -------------------------------------------------------------- products
    function loadProducts() {
        $.getJSON(base + '/products').done(function (response) {
            const rows = response.data;

            if (!rows.length) {
                $('#product-rows').html('<tr><td colspan="4" class="text-muted small">No product interest recorded.</td></tr>');
                return;
            }

            $('#product-rows').html(rows.map(function (row) {
                return '<tr>'
                    + '<td>' + CRM.escape(row.product ? row.product.name : '#' + row.product_id) + '</td>'
                    + '<td><span class="badge text-bg-' + CRM.statusClass(row.interest_status) + '">'
                    + CRM.escape(row.interest_status) + '</span></td>'
                    + '<td class="small">' + (row.quoted_value ? CRM.escape(row.currency + ' ' + row.quoted_value) : '—') + '</td>'
                    + '<td class="text-end">'
                    + '<button class="btn btn-sm btn-outline-danger remove-product" data-id="' + row.id + '">Remove</button>'
                    + '</td></tr>';
            }).join(''));
        });
    }

    $('#add-product').on('click', function () {
        const productId = $('#new-product').val();
        if (!productId) return CRM.alert('Choose a product first.', 'warning');

        $.post(base + '/products', {
            product_id: productId,
            interest_status: $('#new-product-status').val()
        })
            .done(function () {
                CRM.alert('Product interest added.', 'success');
                loadProducts();
                // Adding an advanced interest can pull the lead forward
                // (BR-STAT-04), so the status controls have to be re-read.
                loadTransitions();
                loadTimeline();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#product-rows').on('click', '.remove-product', function () {
        $.ajax({ url: base + '/products/' + $(this).data('id'), method: 'DELETE' })
            .done(function () { loadProducts(); loadTimeline(); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // ----------------------------------------------------------------- notes
    function loadNotes() {
        $.getJSON(base + '/notes').done(function (response) {
            const items = response.data.items || response.data;

            if (!items.length) {
                $('#note-list').html('<p class="text-muted small mb-0">No notes yet.</p>');
                return;
            }

            $('#note-list').html(items.map(function (note) {
                return '<div class="border-bottom py-2">'
                    + '<div class="small">' + CRM.escape(note.body) + '</div>'
                    + '<div class="text-muted" style="font-size:.78rem">'
                    + CRM.escape((note.user ? note.user.name : 'System')) + ' · ' + note.created_at.substring(0, 16).replace('T', ' ')
                    + '</div></div>';
            }).join(''));
        });
    }

    $('#add-note').on('click', function () {
        const body = $('#note-body').val().trim();
        if (!body) return;

        $.post(base + '/notes', { body: body })
            .done(function () {
                $('#note-body').val('');
                loadNotes();
                loadTimeline();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // -------------------------------------------------------------- timeline
    function loadTimeline() {
        $.getJSON(base + '/timeline').done(function (response) {
            const items = response.data.items || response.data;

            if (!items.length) {
                $('#timeline-list').html('<p class="text-muted small mb-0">Nothing yet.</p>');
                return;
            }

            $('#timeline-list').html(items.map(function (entry) {
                return '<div class="border-start border-3 ps-3 py-2 mb-2">'
                    + '<div class="small fw-semibold">' + CRM.escape(entry.title) + '</div>'
                    + '<div class="text-muted" style="font-size:.78rem">'
                    + CRM.escape(entry.occurred_at || entry.created_at || '').substring(0, 16).replace('T', ' ')
                    + '</div></div>';
            }).join(''));
        });
    }

    // --------------------------------------------------------------- history
    // ------------------------------------------------------------ follow-ups
    function loadFollowUps() {
        if (!$('#followup-rows').length) return;

        $.getJSON(base + '/follow-ups').done(function (response) {
            const rows = response.data;

            $('#followup-rows').html(rows.length
                ? rows.map(function (f) {
                    // `is_overdue` is derived server-side rather than compared
                    // in the browser - the two clocks disagree.
                    const badge = f.is_overdue
                        ? '<span class="badge text-bg-warning">Overdue</span>'
                        : '<span class="badge text-bg-' + (
                            f.status === 'completed' ? 'success'
                                : f.status === 'missed' ? 'danger'
                                    : f.status === 'cancelled' ? 'secondary' : 'primary'
                        ) + '">' + CRM.escape(f.status_label) + '</span>';

                    return '<tr>'
                        + '<td class="small">' + (f.scheduled_at || '').replace('T', ' ').substring(0, 16) + '</td>'
                        + '<td class="small">' + CRM.escape(f.channel || '—') + '</td>'
                        + '<td class="small">' + CRM.escape(f.subject || '—') + '</td>'
                        + '<td class="small">' + CRM.escape(f.assigned_to ? f.assigned_to.name : 'Unassigned') + '</td>'
                        + '<td>' + badge + '</td>'
                        + '<td class="text-end">'
                        // Missed follow-ups keep their actions: late is not void.
                        + (['open', 'missed'].indexOf(f.status) !== -1
                            ? '<button class="btn btn-sm btn-outline-success fu-complete" data-id="' + f.id + '">Done</button>'
                              + ' <button class="btn btn-sm btn-outline-secondary fu-cancel" data-id="' + f.id + '">Cancel</button>'
                            : '')
                        + '</td></tr>';
                }).join('')
                : '<tr><td colspan="6" class="text-center text-muted py-3">No follow-ups.</td></tr>');
        });
    }

    $('#fu-create').on('click', function () {
        $.ajax({
            url: base + '/follow-ups',
            method: 'POST',
            data: {
                scheduled_at: $('#fu-when').val(),
                channel: $('#fu-channel').val(),
                subject: $('#fu-subject').val()
            }
        })
            .done(function () {
                $('#fu-when, #fu-subject').val('');
                CRM.alert('Follow-up scheduled.', 'success');
                loadFollowUps();
                loadTimeline();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#followup-rows').on('click', '.fu-complete, .fu-cancel', function () {
        const action = $(this).hasClass('fu-complete') ? 'complete' : 'cancel';

        $.ajax({ url: '/api/v1/follow-ups/' + $(this).data('id') + '/' + action, method: 'POST' })
            .done(function () { loadFollowUps(); loadTimeline(); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // -------------------------------------------------------------- messages
    function loadMessages() {
        $.getJSON(base + '/messages').done(function (response) {
            const rows = response.data.items;

            $('#message-rows').html(rows.length
                ? rows.map(function (m) {
                    return '<tr>'
                        + '<td class="small text-muted">' + (m.created_at || '').substring(0, 16).replace('T', ' ') + '</td>'
                        + '<td class="small">' + CRM.escape(m.channel_label || m.channel) + '</td>'
                        + '<td class="small">' + CRM.escape(m.subject || (m.body || '').substring(0, 60)) + '</td>'
                        + '<td>' + statusBadge(m) + '</td>'
                        // "log" means nothing actually left the building. Shown
                        // so "why did nobody receive this?" is answerable here.
                        + '<td class="small text-muted">' + CRM.escape(m.provider || '—') + '</td>'
                        + '</tr>';
                }).join('')
                : '<tr><td colspan="5" class="text-center text-muted py-3">Nothing sent yet.</td></tr>');
        });
    }

    function statusBadge(m) {
        if (m.status === 'skipped') {
            return '<span class="badge text-bg-danger">Skipped</span>'
                + '<div class="small text-muted">' + CRM.escape(m.skip_reason || '') + '</div>';
        }

        const tone = ({
            queued: 'secondary', sent: 'info', delivered: 'success',
            read: 'success', replied: 'success', failed: 'danger', bounced: 'danger'
        })[m.status] || 'secondary';

        return '<span class="badge text-bg-' + tone + '">' + CRM.escape(m.status) + '</span>'
            + (m.failure_reason ? '<div class="small text-muted">' + CRM.escape(m.failure_reason) + '</div>' : '');
    }

    // Only email carries a subject; the API rejects one on anything else.
    $('#msg-channel').on('change', function () {
        $('#msg-subject-wrap').toggleClass('d-none', $(this).val() !== 'email');
    }).trigger('change');

    $('#msg-send').on('click', function () {
        const payload = {
            channel: $('#msg-channel').val(),
            body: $('#msg-body').val()
        };

        if (payload.channel === 'email' && $('#msg-subject').val()) {
            payload.subject = $('#msg-subject').val();
        }

        $('#msg-send').prop('disabled', true);

        $.ajax({ url: base + '/messages', method: 'POST', data: payload })
            .done(function (response) {
                $('#msg-body, #msg-subject').val('');
                $('#message-blocked').addClass('d-none');
                // The API says in words when no provider is configured, so the
                // message is not mistaken for a delivery.
                CRM.alert(response.message, 'success');
                loadMessages();
                loadTimeline();
            })
            .fail(function (xhr) {
                const body = xhr.responseJSON || {};

                // A DNC refusal is shown in the panel rather than as a toast -
                // it is a standing fact about this lead, not a transient error.
                if (xhr.status === 403 && (body.errors || [])[0] && body.errors[0].code === 'dnc.suppressed') {
                    $('#message-blocked').removeClass('d-none').text(body.message);
                } else {
                    CRM.alert(CRM.errorFrom(xhr));
                }
            })
            .always(function () { $('#msg-send').prop('disabled', false); });
    });

    // ----------------------------------------------------------------- deals
    function loadDeals() {
        if (!$('#deal-list').length) return;

        $.getJSON(base + '/opportunities').done(function (response) {
            const deals = response.data;

            if (!deals.length) {
                $('#deal-list').html('<p class="text-muted small mb-0">No opportunities yet.</p>');
                return;
            }

            $('#deal-list').html(deals.map(function (d) {
                const tone = d.status === 'won' ? 'success' : d.status === 'lost' ? 'dark' : 'primary';

                return '<div class="border rounded p-3 mb-2" data-deal="' + d.id + '">'
                    + '<div class="d-flex justify-content-between align-items-start">'
                    + '<div><span class="fw-semibold">' + CRM.escape(d.title) + '</span>'
                    + ' <span class="badge text-bg-' + tone + '">' + CRM.escape(d.status_label) + '</span>'
                    + '<div class="small text-muted">' + CRM.escape(d.currency) + ' ' + CRM.escape(d.value)
                    + (d.lost_reason_label ? ' · lost: ' + CRM.escape(d.lost_reason_label) : '')
                    + (d.sale ? ' · sale ' + CRM.escape(d.sale.reference) : '')
                    + '</div>'
                    + dealProducts(d)
                    + '</div>'
                    + '<div>' + dealActions(d) + '</div>'
                    + '</div>'
                    + salePanel(d)
                    + '</div>';
            }).join(''));

            // Each won deal's money is a second call, so the deal list renders
            // immediately rather than waiting on every sale's balance.
            $('#deal-list [data-sale]').each(function () { loadPayments($(this)); });
        });
    }

    /* The lines the deal is made of (FR-SALE-02). Their sum IS the deal value -
       the API owns that, so this only lists them. */
    function dealProducts(d) {
        const lines = d.products || [];

        if (!lines.length) {
            return d.status === 'open' && canManageSales
                ? '<div class="small text-muted fst-italic">No products on this deal yet.</div>'
                : '';
        }

        return '<ul class="small text-muted mb-0 mt-1 ps-3">'
            + lines.map(function (p) {
                return '<li>' + CRM.escape(p.name) + ' × ' + p.quantity
                    + ' @ ' + CRM.escape(p.unit_price) + ' = ' + CRM.escape(p.line_total)
                    + (d.status === 'open' && canManageSales
                        ? ' <a href="#" class="deal-line-remove text-danger" data-deal="' + d.id
                          + '" data-product="' + p.product_id + '">remove</a>'
                        : '')
                    + '</li>';
            }).join('')
            + '</ul>';
    }

    function dealActions(d) {
        if (d.status !== 'open') return '';

        let html = '';

        if (canManageSales) {
            html += '<button class="btn btn-sm btn-outline-secondary me-1 deal-line-add" data-id="' + d.id + '">Add product</button>'
                // BR-SALE-03: above the threshold a quotation needs Manager+
                // approval before it can be issued, and the approver may not be
                // the raiser - all enforced by the API.
                + '<button class="btn btn-sm btn-outline-secondary me-1 deal-quote" data-id="' + d.id + '">Quotations</button>'
                /* The control that makes Converted reachable at all. BR-STAT-05
                   requires a sale before a lead may be marked Converted, and
                   until this button existed there was no way to record one from
                   the browser. */
                + '<button class="btn btn-sm btn-success me-1 deal-sale" data-id="' + d.id + '">Record sale</button>';
        }

        return html
            + '<button class="btn btn-sm btn-outline-danger deal-lost" data-id="' + d.id + '">Mark lost</button>';
    }

    /* Money against a won deal (FR-PAY-01/03/04). Drawn only for those who may
       see it - a telecaller holds sales.view but no payments permission. */
    function salePanel(d) {
        if (!d.sale || !canSeeMoney) return '';

        return '<div class="mt-2 pt-2 border-top" data-sale="' + d.sale.id + '">'
            + '<div class="small fw-semibold mb-1">Payments'
            + ' <span class="text-muted fw-normal" data-balance>loading…</span></div>'
            + '<div data-payments></div>'
            + (canTakeMoney
                ? '<button class="btn btn-sm btn-outline-primary mt-2 pay-add" data-sale="' + d.sale.id + '">Record payment</button>'
                : '')
            + '</div>';
    }

    $('#opp-create').on('click', function () {
        const title = $.trim($('#opp-title').val());
        if (!title) return;

        $.ajax({ url: base + '/opportunities', method: 'POST', data: { title: title } })
            .done(function () {
                $('#opp-title').val('');
                CRM.alert('Opportunity opened.', 'success');
                loadDeals();
                loadTimeline();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // ---- Product lines (FR-SALE-02) ------------------------------------
    $('#deal-list').on('click', '.deal-line-add', function () {
        const id = $(this).data('id');
        const productId = window.prompt('Product ID to add:');
        if (!productId) return;

        const quantity = window.prompt('Quantity:', '1');
        if (!quantity) return;

        $.ajax({
            url: '/api/v1/opportunities/' + id + '/products',
            method: 'POST',
            data: { product_id: productId, quantity: quantity, unit_price: window.prompt('Unit price (blank = list price):') || null },
        })
            .done(function () { CRM.alert('Product added.', 'success'); loadDeals(); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#deal-list').on('click', '.deal-line-remove', function (e) {
        e.preventDefault();
        const $a = $(this);

        $.ajax({
            url: '/api/v1/opportunities/' + $a.data('deal') + '/products/' + $a.data('product'),
            method: 'DELETE',
        })
            .done(loadDeals)
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // ---- Quotations (FR-SALE-03/04, BR-SALE-03) -------------------------
    $('#deal-list').on('click', '.deal-quote', function () {
        const id = $(this).data('id');

        $.getJSON('/api/v1/opportunities/' + id + '/quotations').done(function (response) {
            const quotes = response.data || [];

            const lines = quotes.length
                ? quotes.map(function (q) {
                    return '#' + q.id + '  ' + q.status + '  ' + q.currency + ' ' + q.total
                        + (q.discount_percent ? '  (-' + q.discount_percent + '%)' : '');
                }).join('\n')
                : '(none yet)';

            if (! window.confirm('Quotations for this deal:\n\n' + lines + '\n\nRaise a new one?')) return;

            const discount = window.prompt('Discount percent (0 for none):', '0');
            if (discount === null) return;

            $.ajax({
                url: '/api/v1/opportunities/' + id + '/quotations',
                method: 'POST',
                data: { discount_percent: discount, valid_until: null },
            })
                .done(function (r) {
                    /* Said out loud because it is the rule most likely to
                       surprise: past the threshold the quotation cannot be
                       issued until a Manager+ who did NOT raise it approves. */
                    CRM.alert(r.message || 'Quotation raised.', 'success');
                })
                .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
        }).fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // ---- Record a sale (BR-STAT-05) ------------------------------------
    $('#deal-list').on('click', '.deal-sale', function () {
        const id = $(this).data('id');

        /* Amount is optional: the API falls back to the deal's own value, which
           is the sum of its lines. Asking anyway, because a negotiated final
           figure is common and retyping it is cheaper than editing a sale. */
        const amount = window.prompt('Sale amount (blank = the deal value):');
        if (amount === null) return;

        $.ajax({
            url: '/api/v1/opportunities/' + id + '/sale',
            method: 'POST',
            data: { amount: amount || null, notes: window.prompt('Notes (optional):') || null },
        })
            .done(function (r) {
                // The lead can now be marked Converted - which was unreachable
                // from this screen until the sale existed (BR-STAT-05).
                CRM.alert(r.message || 'Sale recorded.', 'success');
                loadDeals();
                loadTimeline();

            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // ---- Payments (FR-PAY-01/03/04) -------------------------------------
    function loadPayments($panel) {
        const saleId = $panel.data('sale');

        $.getJSON('/api/v1/sales/' + saleId + '/payments').done(function (response) {
            const d = response.data;

            // The balance is derived by the API, never stored (BR-PAY-04) - it
            // is the number an argument with a customer turns on.
            $panel.find('[data-balance]').text(
                '· collected ' + d.collected + ' of ' + d.sale.amount + ' · balance ' + d.balance
            );

            const rows = (d.payments || []).map(function (p) {
                return '<div class="small d-flex justify-content-between border-top py-1">'
                    + '<span>' + CRM.escape(p.reference) + ' · ' + CRM.escape(p.amount)
                    + ' · <span class="text-muted">' + CRM.escape(p.method || '—') + '</span></span>'
                    + '<span><span class="badge text-bg-secondary">' + CRM.escape(p.status) + '</span>'
                    + (canTakeMoney ? ' <a href="#" class="pay-move" data-id="' + p.id + '">change</a>' : '')
                    + '</span></div>';
            }).join('');

            $panel.find('[data-payments]').html(rows || '<p class="small text-muted mb-0">Nothing collected yet.</p>');
        });
    }

    $('#deal-list').on('click', '.pay-add', function () {
        const $panel = $(this).closest('[data-sale]');
        const amount = window.prompt('Amount received:');
        if (!amount) return;

        $.ajax({
            url: '/api/v1/sales/' + $panel.data('sale') + '/payments',
            method: 'POST',
            data: {
                amount: amount,
                method: window.prompt('Method: cash, bank_transfer, upi, card, cheque, other', 'upi') || null,
                // BR-PAY-03: a payment names one product. The API fills it in
                // for a single-product sale, so blank is usually right (T-58).
                product_id: window.prompt('Product ID (blank if the sale has one product):') || null,
            },
        })
            .done(function (r) {
                CRM.alert(r.message || 'Payment recorded.', 'success');
                loadDeals();

            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#deal-list').on('click', '.pay-move', function (e) {
        e.preventDefault();

        // The API enforces the BR-PAY-02 matrix; offering the values it accepts
        // rather than free text keeps the prompt honest.
        const status = window.prompt('New status: pending, partial, paid, failed, overdue, refund', 'paid');
        if (!status) return;

        $.ajax({
            url: '/api/v1/payments/' + $(this).data('id'),
            method: 'PATCH',
            data: { status: status, reason: window.prompt('Reason (required for a refund):') || null },
        })
            .done(function () { loadDeals(); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#deal-list').on('click', '.deal-lost', function () {
        // FR-SALE-05: a lost deal records WHY, from the closed list the API
        // accepts - so the prompt offers those values rather than free text.
        const reason = window.prompt(
            'Why was this lost?\nprice, competitor, no_budget, no_requirement, timing, no_response, unreachable, other',
            'price',
        );

        if (!reason) return;

        $.ajax({
            url: '/api/v1/opportunities/' + $(this).data('id') + '/lost',
            method: 'POST',
            data: { reason: reason, notes: reason === 'other' ? window.prompt('Explain:') : null }
        })
            .done(function () { loadDeals(); loadTimeline(); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    function loadHistory() {
        $.getJSON(base + '/status-history').done(function (response) {
            const items = response.data.items;

            if (!items.length) {
                $('#history-rows').html('<tr><td colspan="5" class="text-muted small">No status changes yet.</td></tr>');
                return;
            }

            $('#history-rows').html(items.map(function (row) {
                return '<tr>'
                    + '<td class="small text-muted">' + row.created_at.substring(0, 16).replace('T', ' ') + '</td>'
                    + '<td class="small">' + CRM.escape(row.from_label || '—') + '</td>'
                    + '<td class="small">' + CRM.escape(row.to_label) + '</td>'
                    // A null actor means the system moved it, not that we lost
                    // track of who did.
                    + '<td class="small">' + (row.is_automatic ? '<em class="text-muted">System</em>'
                        : CRM.escape(row.changed_by ? row.changed_by.name : '—')) + '</td>'
                    + '<td class="small text-muted">' + CRM.escape(row.reason || '') + '</td>'
                    + '</tr>';
            }).join(''));
        });
    }

    // ----------------------------------------------------------------- calls
    function loadCallability() {
        $.getJSON(base + '/callability').done(function (response) {
            const data = response.data;

            if (data.callable) {
                $('#call-blocked').addClass('d-none');
                $('#call-form').removeClass('d-none');
                return;
            }

            // Explain rather than just disable — "cannot call" without a reason
            // reads as a broken page.
            let text = data.message;
            if (data.next_opening) {
                text += ' Reachable again from ' + data.next_opening.substring(0, 16).replace('T', ' ') + '.';
            }

            $('#call-blocked').removeClass('d-none').text(text);
            $('#call-form').addClass('d-none');
        });
    }

    function loadCalls() {
        $.getJSON(base + '/calls', { sort: '-started_at' }).done(function (response) {
            const items = response.data.items;

            if (!items.length) {
                $('#call-rows').html('<tr><td colspan="' + callColumns + '" class="text-muted small">No calls yet.</td></tr>');
                return;
            }

            $('#call-rows').html(items.map(function (call) {
                return '<tr>'
                    + '<td class="small text-muted">' + (call.started_at || '').substring(0, 16).replace('T', ' ') + '</td>'
                    + '<td>' + (call.is_pending
                        ? '<span class="badge text-bg-secondary">In progress</span>'
                        : '<span class="badge text-bg-' + (call.is_connected ? 'success' : 'secondary') + '">'
                          + CRM.escape(call.status_label) + '</span>') + '</td>'
                    // Zero on anything that did not connect — an attempt is not
                    // talk time.
                    + '<td class="small">' + (call.duration_seconds ? call.duration_seconds + 's' : '—') + '</td>'
                    + '<td class="small">' + CRM.escape(call.user ? call.user.name : '—') + '</td>'
                    + '<td class="small text-muted">' + CRM.escape(call.notes || '') + '</td>'
                    + (canListen ? '<td class="small">' + recordingCell(call) + '</td>' : '')
                    + '</tr>';
            }).join(''));
        });
    }

    // ------------------------------------------------------------ recordings
    /*
     * FR-REC-04, BR-REC-01.
     *
     * Drawn from `has_recording` on the call itself, and the recording's own
     * endpoint is not touched until somebody clicks. Reading a recording is
     * AUDITED (SEC-FILE-04), so probing it once per row would fill the audit log
     * with accesses nobody made - the audit entry has to mean "a person listened
     * to this", or it means nothing.
     */
    function recordingCell(call) {
        if (!call.has_recording) return '<span class="text-muted">—</span>';

        return '<button class="btn btn-sm btn-outline-secondary rec-open" data-id="' + call.id + '">'
            + '<i class="bi bi-play-circle me-1"></i>Play</button>'
            + '<div class="rec-panel mt-1" data-call="' + call.id + '"></div>';
    }

    $('#call-rows').on('click', '.rec-open', function () {
        const callId = $(this).data('id');
        const $panel = $('.rec-panel[data-call="' + callId + '"]');
        const $button = $(this).prop('disabled', true);

        $.getJSON('/api/v1/calls/' + callId + '/recording')
            .done(function (response) {
                const recording = response.data;

                if (!recording.is_playable) {
                    /* The three "no audio" histories are different facts and the
                       API reports them separately, so the panel says which one
                       this is. Collapsing them into "no recording" would hide
                       two of them (BR-REC-02/03). */
                    $panel.html('<div class="alert alert-secondary py-2 px-3 small mb-0">'
                        + (recording.is_purged ? 'The audio has been deleted.'
                            : recording.is_unavailable ? 'The handset could not record this call.'
                                : 'The recording has not finished uploading.')
                        + (recording.failure_reason ? ' ' + CRM.escape(recording.failure_reason) : '')
                        + '</div>');
                    return;
                }

                // The URL is signed and short-lived, so it is fetched at play
                // time and never baked into the row (SEC-FILE-03).
                $panel.html('<audio controls preload="none" class="w-100" src="' + CRM.escape(recording.audio_url) + '"></audio>'
                    + '<div class="text-muted" style="font-size:.75rem">'
                    + (recording.duration_seconds ? recording.duration_seconds + 's · ' : '')
                    + 'this playback link expires shortly'
                    + (recording.expires_at ? ' · audio kept until ' + CRM.escape(recording.expires_at.substring(0, 10)) : '')
                    + '</div>'
                    + (canDeleteRecording
                        ? '<button class="btn btn-sm btn-outline-danger mt-1 rec-delete" data-id="' + callId + '">'
                          + 'Delete recording</button>'
                        : ''));
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
            .always(function () { $button.prop('disabled', false); });
    });

    if ($('#rec-delete-confirm').length) {
        const recDeleteModal = new bootstrap.Modal(document.getElementById('rec-delete-modal'));
        let recDeleteCallId = null;

        $('#call-rows').on('click', '.rec-delete', function () {
            recDeleteCallId = $(this).data('id');
            $('#rec-delete-call').text('The recording of call #' + recDeleteCallId + '.');
            recDeleteModal.show();
        });

        $('#rec-delete-confirm').on('click', function () {
            const $button = $(this).prop('disabled', true);

            $.ajax({ url: '/api/v1/calls/' + recDeleteCallId + '/recording', method: 'DELETE' })
                .done(function (response) {
                    recDeleteModal.hide();
                    CRM.alert(response.message || 'Recording deleted.', 'success');
                    // The row survives with no audio, so the list is re-read
                    // rather than the player simply removed - "there was a
                    // recording and it is gone" is what the call now says.
                    loadCalls();
                })
                .fail(function (xhr) {
                    recDeleteModal.hide();
                    CRM.alert(CRM.errorFrom(xhr));
                })
                .always(function () { $button.prop('disabled', false); });
        });
    }

    $('#call-outcome').on('change', function () {
        $('#callback-wrap').toggleClass('d-none', $(this).val() !== 'call_back_requested');
    });

    $('#log-call').on('click', function () {
        const status = $('#call-outcome').val();
        if (!status) return CRM.alert('Choose an outcome first.', 'warning');

        const payload = { status: status };

        const duration = $('#call-duration').val();
        if (duration) payload.duration_seconds = duration;

        const notes = $('#call-notes').val();
        if (notes) payload.notes = notes;

        const callback = $('#callback-at').val();
        if (callback) payload.callback_at = callback;

        $.post(base + '/calls', payload)
            .done(function () {
                CRM.alert('Call logged.', 'success');
                $('#call-outcome, #call-duration, #call-notes, #callback-at').val('');
                $('#callback-wrap').addClass('d-none');
                loadCalls();
                loadTimeline();
                // A connected call and a no-answer are both scoring signals
                // (BR-INT-01), so the breakdown has just changed.
                loadScore();
                // A wrong or invalid number suppresses the lead, which changes
                // whether it can be called again (BR-DNC-07).
                loadCallability();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // ------------------------------------------------------------- AI calling
    // FR-AI-01. No separate gate: the button sits inside #call-form, which
    // loadCallability() shows or hides, so DNC and calling hours refuse an AI
    // dial with exactly the verdict that refuses a manual one (BR-CALL-04).
    let aiUnavailable = false;

    $('#ai-call').on('click', function () {
        const $button = $(this).prop('disabled', true);

        const payload = {};
        const script = $.trim($('#ai-script').val());
        if (script) payload.script = script;

        $.ajax({ url: base + '/ai-call', method: 'POST', data: payload })
            .done(function (response) {
                $('#ai-call-blocked').addClass('d-none');
                $('#ai-script').val('');
                // 202, not 201: Vaaad is dialling and the outcome arrives by
                // webhook, so the row shows as In progress and fills in later.
                CRM.alert(response.message, 'success');
                loadCalls();
                loadTimeline();
            })
            .fail(function (xhr) {
                const body = xhr.responseJSON || {};
                const code = ((body.errors || [])[0] || {}).code;

                // 503 names the missing credential. That is true of the whole
                // installation until somebody changes Settings, so it belongs in
                // the panel, not in a toast that hides itself after four seconds.
                if (xhr.status === 503) {
                    aiUnavailable = true;
                    $('#ai-call-blocked').removeClass('d-none').text(body.message || CRM.errorFrom(xhr));
                    return;
                }

                // The verdict moved under us - the lead was suppressed, or the
                // calling window closed, between the page load and the click.
                // Re-reading it puts the reason in the panel the manual form
                // already uses rather than inventing a second one.
                if (code === 'dnc.suppressed' || code === 'call.outside_calling_hours' || code === 'lead.archived') {
                    loadCallability();
                    return;
                }

                CRM.alert(CRM.errorFrom(xhr));
            })
            .always(function () { $button.prop('disabled', aiUnavailable); });
    });

    // Nothing is loaded for an archived lead: every /leads/{lead}/* endpoint
    // resolves it by implicit binding and would answer 404, so the page shows
    // the identity card and the Restore control and asks for nothing else.
    if (!isArchived) {
        loadScore();
        loadTransitions();
        loadCallability();
        loadCalls();
        loadProducts();
        loadNotes();
        loadTimeline();
        loadHistory();
    }

    // Loaded on first open rather than on page load: three extra requests on
    // every lead view, for tabs most people never touch, is a slow page for
    // nothing.
    //
    // Bound inside the same permission blocks that draw the tabs, so a role
    // without the permission has neither the tab nor a handler referring to
    // it - the markup then says plainly what each role can reach.
    @permission('follow_ups.view')
    $('[data-bs-target="#tab-followups"]').one('shown.bs.tab', loadFollowUps);
    @endpermission

    $('[data-bs-target="#tab-messages"]').one('shown.bs.tab', loadMessages);

    @permission('sales.view')
    $('[data-bs-target="#tab-deals"]').one('shown.bs.tab', loadDeals);
    @endpermission
});
</script>
@endpush
