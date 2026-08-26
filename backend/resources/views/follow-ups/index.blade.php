{{--
    The cross-lead follow-up queue (FR-FUP-04).

    The per-lead tab answers "what have we promised this person"; that is only
    useful once you already know which person. This screen answers the question
    a telecaller actually opens the CRM with - "who am I due to contact now" -
    so it defaults to what is due today plus everything already late, not to
    every reminder ever scheduled.

    A shell like every other page (ADR-A): the rows arrive from
    GET /api/v1/follow-ups, which scopes to the signed-in user's own work, and
    every action posts to the same endpoints the lead-detail tab uses.
--}}
@extends('layouts.app')
@section('title', 'Follow-ups')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-due">Due</label>
                    <select id="f-due" class="form-select form-select-sm">
                        <option value="actionable" selected>Due today and overdue</option>
                        <option value="overdue">Overdue only</option>
                        <option value="today">Due today only</option>
                        <option value="week">Next 7 days</option>
                        <option value="">Any time</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-status">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        {{-- Blank is not "everything": the API's own default is
                             open plus missed, which is the working set. --}}
                        <option value="">Still to do (open and missed)</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-channel">Channel</label>
                    <select id="f-channel" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach (\App\Enums\Channel::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-sort">Sort</label>
                    <select id="f-sort" class="form-select form-select-sm">
                        <option value="scheduled_at">Soonest first</option>
                        <option value="-scheduled_at">Latest first</option>
                        <option value="-created_at">Recently added</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button id="f-apply" class="btn btn-sm btn-primary">Apply</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>Due</th><th>Lead</th><th>Channel</th>
                    <th>Subject</th><th>Status</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody id="followup-rows">
                <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="followup-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        A missed follow-up keeps its actions — it is late, not void (BR-FUP-02). Rescheduling closes
        this reminder and opens a replacement, so the time you originally promised stays readable in
        the lead's history (BR-FUP-03).
    </p>

    @permission('follow_ups.manage')
        {{-- Reschedule needs a new time, so it cannot be a bare button. --}}
        <div class="modal fade" id="reschedule-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6">Reschedule follow-up</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted" id="rs-summary"></p>

                        <label class="form-label small mb-1" for="rs-when">
                            New time <span class="text-danger">*</span>
                        </label>
                        {{-- `min` mirrors the server's after:now rule so the browser
                             pushes back before the round trip does. --}}
                        <input type="datetime-local" id="rs-when" class="form-control form-control-sm"
                               min="{{ now()->format('Y-m-d\TH:i') }}">
                        <div class="invalid-feedback"></div>

                        <label class="form-label small mb-1 mt-3" for="rs-notes">Why</label>
                        <textarea id="rs-notes" class="form-control form-control-sm" rows="2" maxlength="5000"
                                  placeholder="e.g. Asked to be called back after the weekend"></textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-sm btn-primary" id="confirm-reschedule">Reschedule</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Cancelling drops a commitment, so it is confirmed rather than fired
             from a single click, and the reason is what makes the closed
             follow-up readable later. --}}
        <div class="modal fade" id="cancel-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6">Cancel follow-up</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted" id="cancel-summary"></p>

                        <label class="form-label small mb-1" for="cancel-reason">Reason</label>
                        <input type="text" id="cancel-reason" class="form-control form-control-sm"
                               maxlength="255" placeholder="e.g. Lead already bought">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-sm btn-danger" id="confirm-cancel">Cancel follow-up</button>
                    </div>
                </div>
            </div>
        </div>
    @endpermission
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;
    let acting = null;
    // The rendered page, keyed by id. The modals read their summary from here
    // rather than from a data- attribute: CRM.escape does not escape quotes, so
    // a lead named O"Brien in an attribute would break out of it.
    let loaded = {};

    const canManage = @json($canManage);

    /*
     * Every boundary is the SERVER's clock, stamped once at render.
     *
     * The API filters on its own clock, so a browser running an hour out would
     * colour a row "today" that the query treated as tomorrow. The cost is that
     * a page left open across midnight is stale - which it is anyway, since the
     * rows themselves were fetched yesterday.
     */
    const clock = {
        // The strings go to the API, in the format its own column is stored in.
        now: @json(now()->format('Y-m-d H:i:s')),
        dayStart: @json(now()->startOfDay()->format('Y-m-d H:i:s')),
        dayEnd: @json(now()->endOfDay()->format('Y-m-d H:i:s')),
        weekEnd: @json(now()->addDays(7)->endOfDay()->format('Y-m-d H:i:s')),
        // The milliseconds are for colouring rows, compared against a parsed
        // `scheduled_at` - both are absolute instants, so no timezone is assumed.
        dayStartMs: @json(now()->startOfDay()->timestamp * 1000),
        dayEndMs: @json(now()->endOfDay()->timestamp * 1000)
    };

    const rescheduleModal = canManage ? new bootstrap.Modal(document.getElementById('reschedule-modal')) : null;
    const cancelModal = canManage ? new bootstrap.Modal(document.getElementById('cancel-modal')) : null;

    /** The window each "Due" choice means, as the API's own filter operators. */
    function dueFilter(choice) {
        if (choice === 'overdue') return { 'filter[scheduled_at][lt]': clock.now };
        if (choice === 'today') return { 'filter[scheduled_at][between]': clock.dayStart + ',' + clock.dayEnd };
        if (choice === 'week') return { 'filter[scheduled_at][lte]': clock.weekEnd };
        if (choice === '') return {};

        return { 'filter[scheduled_at][lte]': clock.dayEnd };
    }

    function load() {
        const params = $.extend({ page: page, sort: $('#f-sort').val() }, dueFilter($('#f-due').val()));

        // The API rejects unknown or blank filter fields with a 422 rather than
        // ignoring them, so a filter with no value is left off entirely.
        const status = $('#f-status').val();
        if (status) params['filter[status]'] = status;

        const channel = $('#f-channel').val();
        if (channel) params['filter[channel]'] = channel;

        $.getJSON('/api/v1/follow-ups', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#followup-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">Could not load follow-ups.</td></tr>');
            });
    }

    function emptyMessage() {
        // On the default view an empty list is good news, not a failed search.
        return $('#f-due').val() === 'actionable' && !$('#f-status').val() && !$('#f-channel').val()
            ? 'Nothing due today and nothing overdue. You are clear.'
            : 'No follow-ups match.';
    }

    function render(items, meta) {
        loaded = {};
        items.forEach(function (f) { loaded[f.id] = f; });

        if (!items.length) {
            $('#followup-rows').html(
                '<tr><td colspan="6" class="text-center text-muted py-4">' + CRM.escape(emptyMessage()) + '</td></tr>'
            );
        } else {
            $('#followup-rows').html(items.map(function (f) {
                const due = Date.parse(f.scheduled_at);

                // `is_overdue` is derived server-side rather than compared here,
                // and a missed follow-up belongs in the same bucket: both are
                // late. Late and due-today are the two states this screen exists
                // to tell apart, so they get different row colours.
                const overdue = f.is_overdue || f.status === 'missed';
                const today = !overdue && due >= clock.dayStartMs && due <= clock.dayEndMs;
                const rowClass = overdue ? 'table-danger' : (today ? 'table-warning' : '');

                const when = overdue ? 'Overdue' : (today ? 'Today' : '');

                return '<tr class="' + rowClass + '">'
                    + '<td class="small">' + CRM.escape((f.scheduled_at || '').replace('T', ' ').substring(0, 16))
                    + (when ? '<div class="small fw-semibold">' + when + '</div>' : '')
                    + '</td>'
                    + '<td>' + (f.lead
                        ? '<a href="/leads/' + f.lead.id + '">' + CRM.escape(f.lead.name) + '</a>'
                        : '<span class="text-muted">No lead record</span>') + '</td>'
                    + '<td class="small">' + CRM.escape(f.channel || '—') + '</td>'
                    + '<td class="small">' + CRM.escape(f.subject || '—')
                    + (f.product ? '<div class="small text-muted">' + CRM.escape(f.product.name) + '</div>' : '')
                    + '</td>'
                    + '<td>' + badge(f) + '</td>'
                    + '<td class="text-end">' + actions(f) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#followup-meta').text(
            meta.total + ' follow-up' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    function badge(f) {
        if (f.is_overdue) return '<span class="badge text-bg-warning">Overdue</span>';

        const colour = f.status === 'completed' ? 'success'
            : f.status === 'missed' ? 'danger'
                : f.status === 'cancelled' ? 'secondary' : 'primary';

        return '<span class="badge badge-status text-bg-' + colour + '">' + CRM.escape(f.status_label) + '</span>';
    }

    function actions(f) {
        // Missed keeps its actions: completing one late is exactly what should
        // happen (BR-FUP-02). Hiding the buttons is usability - the policy on
        // the endpoint is what refuses the write.
        if (!canManage || ['open', 'missed'].indexOf(f.status) === -1) return '';

        return '<div class="btn-group btn-group-sm">'
            + '<button class="btn btn-outline-success fu-complete" data-id="' + f.id + '">Done</button>'
            + '<button class="btn btn-outline-secondary fu-reschedule" data-id="' + f.id + '">Reschedule</button>'
            + '<button class="btn btn-outline-danger fu-cancel" data-id="' + f.id + '">Cancel</button>'
            + '</div>';
    }

    /** What the modals show above their inputs, so it is clear what is changing. */
    function summaryFor(id) {
        const f = loaded[id];
        if (!f) return '';

        return (f.lead ? f.lead.name : 'This lead')
            + ' — ' + (f.subject || 'follow-up')
            + ' — due ' + (f.scheduled_at || '').replace('T', ' ').substring(0, 16);
    }

    function clearErrors(selector) {
        $(selector).find('.form-control').removeClass('is-invalid');
        $(selector).find('.invalid-feedback').text('');
    }

    /** Puts a 422 back on the input that caused it; anything else is a toast. */
    function mapErrors(xhr, modal, fields) {
        const errors = (xhr.responseJSON || {}).errors || [];
        let mapped = false;

        errors.forEach(function (error) {
            const selector = fields[error.field];
            if (!selector) return;

            $(selector).addClass('is-invalid').next('.invalid-feedback').text(error.message);
            mapped = true;
        });

        if (!mapped) {
            modal.hide();
            CRM.alert(CRM.errorFrom(xhr));
        }
    }

    // Rows are re-rendered on every load, so the handlers are delegated.
    $('#followup-rows').on('click', '.fu-complete', function () {
        const button = $(this);
        button.prop('disabled', true);

        $.ajax({ url: '/api/v1/follow-ups/' + button.data('id') + '/complete', method: 'POST' })
            .done(function (response) {
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
            .always(function () { button.prop('disabled', false); });
    });

    $('#followup-rows').on('click', '.fu-reschedule', function () {
        acting = $(this).data('id');

        $('#rs-summary').text(summaryFor(acting));
        $('#rs-when').val('');
        $('#rs-notes').val('');
        clearErrors('#reschedule-modal');

        rescheduleModal.show();
    });

    $('#confirm-reschedule').on('click', function () {
        $('#confirm-reschedule').prop('disabled', true);
        clearErrors('#reschedule-modal');

        const data = { scheduled_at: $('#rs-when').val() };

        const notes = $('#rs-notes').val();
        if (notes) data.notes = notes;

        $.ajax({ url: '/api/v1/follow-ups/' + acting + '/reschedule', method: 'POST', data: data })
            .done(function (response) {
                rescheduleModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) {
                mapErrors(xhr, rescheduleModal, { scheduled_at: '#rs-when', notes: '#rs-notes' });
            })
            .always(function () { $('#confirm-reschedule').prop('disabled', false); });
    });

    $('#followup-rows').on('click', '.fu-cancel', function () {
        acting = $(this).data('id');

        $('#cancel-summary').text(summaryFor(acting));
        $('#cancel-reason').val('');
        clearErrors('#cancel-modal');

        cancelModal.show();
    });

    $('#confirm-cancel').on('click', function () {
        $('#confirm-cancel').prop('disabled', true);
        clearErrors('#cancel-modal');

        const data = {};

        const reason = $('#cancel-reason').val();
        if (reason) data.reason = reason;

        $.ajax({ url: '/api/v1/follow-ups/' + acting + '/cancel', method: 'POST', data: data })
            .done(function (response) {
                cancelModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) { mapErrors(xhr, cancelModal, { reason: '#cancel-reason' }); })
            .always(function () { $('#confirm-cancel').prop('disabled', false); });
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
