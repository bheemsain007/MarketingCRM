{{--
    Call history across every lead the caller may see (FR-CALL-05).

    The lead page shows one lead's calls; this is the log across all of them,
    which is the view a manager actually works from - "what did the team do
    yesterday, and how much of it connected". Notes are on the row rather than
    behind a click because the notes ARE the history: an outcome without what
    was said is a tally, not a record.

    Rows come from GET /api/v1/calls, which scopes on the caller who made the
    call - a telecaller sees only their own (SEC-AUTHZ-03). The shell renders
    reference data only and never queries (ADR-A).
--}}
@extends('layouts.app')
@section('title', 'Call History')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-from">From</label>
                    <input type="date" id="f-from" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-to">To</label>
                    <input type="date" id="f-to" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-status">Outcome</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">Any outcome</option>
                        @foreach ($callStatuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-source">Dialled by</label>
                    <select id="f-source" class="form-select form-select-sm">
                        <option value="">Any source</option>
                        {{-- The four values StoreCallRequest accepts; there is no
                             enum behind `dial_source`. --}}
                        <option value="manual">Manual</option>
                        <option value="auto_dialer">Auto dialer</option>
                        <option value="ai">AI call</option>
                        <option value="inbound">Inbound</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-sort">Sort</label>
                    <select id="f-sort" class="form-select form-select-sm">
                        <option value="-started_at">Newest first</option>
                        <option value="started_at">Oldest first</option>
                        <option value="-duration_seconds">Longest first</option>
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
                    <th>When</th><th>Lead</th><th>Outcome</th>
                    <th>Duration</th><th>Who called</th><th>Notes</th>
                </tr>
                </thead>
                <tbody id="call-rows">
                <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="call-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Duration is talk time and only a connected call has any — an attempt is not talk time
        (GLOSSARY §2.2). A call with no outcome yet was dialled and never reported back.
    </p>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;

    function truncate(value, length) {
        const text = (value === null || value === undefined) ? '' : String(value);
        return text.length > length ? text.slice(0, length) + '…' : text;
    }

    // `dial_source` is a plain string column with no enum behind it, so the
    // wording lives here. Falls back to the raw value rather than blank, so a
    // source added server-side still says something.
    function sourceLabel(source) {
        return ({
            manual: 'Manual', auto_dialer: 'Auto dialer',
            ai: 'AI call', inbound: 'Inbound'
        })[source] || source;
    }

    function duration(seconds) {
        if (!seconds) return '—';
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's';
    }

    /*
     * A date input gives a day, but `started_at` is a timestamp - so "to" has to
     * cover the whole of its day or the day you picked comes back empty. One
     * bound alone uses gte/lte; `between` needs both (QueryOptions rejects a
     * one-sided range rather than guessing).
     */
    function applyPeriod(params) {
        const from = $('#f-from').val();
        const to = $('#f-to').val();

        if (from && to) {
            params['filter[started_at][between]'] = from + ' 00:00:00,' + to + ' 23:59:59';
        } else if (from) {
            params['filter[started_at][gte]'] = from + ' 00:00:00';
        } else if (to) {
            params['filter[started_at][lte]'] = to + ' 23:59:59';
        }
    }

    function load() {
        const params = { page: page, sort: $('#f-sort').val() };

        // Blank means "any". The endpoint rejects an unknown or empty filter
        // field with a 422 rather than ignoring it, so it must not be sent.
        const status = $('#f-status').val();
        if (status) params['filter[status]'] = status;

        const source = $('#f-source').val();
        if (source) params['filter[dial_source]'] = source;

        applyPeriod(params);

        $.getJSON('/api/v1/calls', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#call-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">Could not load call history.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#call-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">No calls match.</td></tr>');
        } else {
            $('#call-rows').html(items.map(function (call) {
                // A null status is "dialled, outcome not reported" - a real
                // state, not a missing value to render as a dash.
                const outcome = call.is_pending
                    ? '<span class="badge text-bg-secondary">In progress</span>'
                    : '<span class="badge text-bg-' + (call.is_connected ? 'success' : 'secondary') + '">'
                      + CRM.escape(call.status_label) + '</span>';

                return '<tr' + (call.lead ? ' data-href="/leads/' + call.lead.id + '"' : '') + '>'
                    + '<td class="small text-muted">'
                    + (call.started_at ? call.started_at.substring(0, 16).replace('T', ' ') : '—') + '</td>'
                    + '<td>' + CRM.escape(call.lead ? call.lead.name : 'Lead #' + call.lead_id) + '</td>'
                    + '<td>' + outcome + '</td>'
                    + '<td class="small">' + duration(call.duration_seconds) + '</td>'
                    + '<td class="small">' + CRM.escape(call.user ? call.user.name : 'System')
                    + '<div class="text-muted">' + CRM.escape(sourceLabel(call.dial_source)) + '</div></td>'
                    + '<td class="small text-muted">' + CRM.escape(truncate(call.notes, 90)) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#call-meta').text(
            meta.total + ' call' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    // Delegated: the rows are replaced on every load.
    $('#call-rows').on('click', 'tr[data-href]', function () {
        window.location = $(this).data('href');
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
