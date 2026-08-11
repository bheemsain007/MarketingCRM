@extends('layouts.app')
@section('title', $lead->name)

@section('content')
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

                @permission('leads.update')
                    <a href="{{ route('web.leads.edit', $lead) }}" class="btn btn-sm btn-outline-secondary mt-3">
                        <i class="bi bi-pencil me-1"></i>Edit details
                    </a>
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
    </div>

    {{-- ---------------------------------------------------------------- --}}
    {{-- Right: products, notes, history                                  --}}
    {{-- ---------------------------------------------------------------- --}}
    <div class="col-lg-8">
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
                    </div>
                    @endpermission

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="small text-muted">
                            <tr><th>When</th><th>Outcome</th><th>Duration</th><th>By</th><th>Notes</th></tr>
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
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const leadId = {{ $lead->id }};
    const base = '/api/v1/leads/' + leadId;

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

                return '<div class="border rounded p-3 mb-2">'
                    + '<div class="d-flex justify-content-between align-items-start">'
                    + '<div><span class="fw-semibold">' + CRM.escape(d.title) + '</span>'
                    + ' <span class="badge text-bg-' + tone + '">' + CRM.escape(d.status_label) + '</span>'
                    + '<div class="small text-muted">' + CRM.escape(d.currency) + ' ' + CRM.escape(d.value)
                    + (d.lost_reason_label ? ' · lost: ' + CRM.escape(d.lost_reason_label) : '')
                    + (d.sale ? ' · sale ' + CRM.escape(d.sale.reference) : '')
                    + '</div></div>'
                    + '<div>' + dealActions(d) + '</div>'
                    + '</div></div>';
            }).join(''));
        });
    }

    function dealActions(d) {
        if (d.status !== 'open') return '';

        return '<a href="/api/v1/opportunities/' + d.id + '/quotations" class="d-none"></a>'
            + '<button class="btn btn-sm btn-outline-danger deal-lost" data-id="' + d.id + '">Mark lost</button>';
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
                $('#call-rows').html('<tr><td colspan="5" class="text-muted small">No calls yet.</td></tr>');
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
                    + '</tr>';
            }).join(''));
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
                // A wrong or invalid number suppresses the lead, which changes
                // whether it can be called again (BR-DNC-07).
                loadCallability();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    loadTransitions();
    loadCallability();
    loadCalls();
    loadProducts();
    loadNotes();
    loadTimeline();
    loadHistory();

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
