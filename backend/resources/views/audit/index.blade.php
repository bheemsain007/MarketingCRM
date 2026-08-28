{{--
    The compliance trail (SEC-AUD-01..04).

    `audit.view` was granted to Admin and Super Admin from the first seeder and
    read by nothing: no endpoint, no page, no query. An audit trail nobody can
    read is a table, not a control - and the people who need to answer "who
    lifted that suppression" are precisely the ones who cannot open a database
    console.

    Read-only by construction, not by omission. `audit_logs` refuses updates and
    deletes at the model and the query builder (SEC-AUD-01), the API exposes one
    verb, and there is deliberately nothing on this screen to press. A mistaken
    entry is corrected by writing another one, the way a ledger is.

    Newest first, because a trail is read backwards from the incident.
--}}
@extends('layouts.app')
@section('title', 'Audit Trail')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-actor">Who</label>
                    <select id="f-actor" class="form-select form-select-sm">
                        <option value="">Anyone</option>
                        @foreach ($actors as $actor)
                            <option value="{{ $actor->id }}">{{ $actor->name }}</option>
                        @endforeach
                    </select>
                    <div class="form-text small mb-0">System events have no actor.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-q">What</label>
                    <input type="search" id="f-q" class="form-control form-control-sm"
                           placeholder="e.g. refund, dnc, status">
                    <div class="form-text small mb-0">Matches the action or its description.</div>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-from">From</label>
                    <input type="date" id="f-from" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-to">To</label>
                    <input type="date" id="f-to" class="form-control form-control-sm">
                </div>

                <div class="col-md-2 d-grid">
                    <button id="f-apply" class="btn btn-sm btn-primary">Apply</button>
                </div>

                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-subject-type">About</label>
                    <select id="f-subject-type" class="form-select form-select-sm">
                        <option value="">Anything</option>
                        @foreach ($subjectTypes as $label => $class)
                            <option value="{{ $class }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-subject-id">Record #</label>
                    <input type="number" id="f-subject-id" class="form-control form-control-sm" min="1">
                </div>
                <div class="col-md-2 d-grid">
                    <button id="f-clear" class="btn btn-sm btn-outline-secondary">Clear</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>When</th><th>Who</th><th>Action</th><th>About</th><th>What changed</th>
                </tr>
                </thead>
                <tbody id="audit-rows">
                <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="audit-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;

    function load() {
        const params = { page: page };

        // Blank filters are omitted: the API rejects an unknown or empty filter
        // field with a 422 rather than ignoring it.
        const actor = $('#f-actor').val();
        if (actor) params['filter[user_id]'] = actor;

        const q = ($('#f-q').val() || '').trim();
        if (q) params.q = q;

        const subjectType = $('#f-subject-type').val();
        if (subjectType) params['filter[auditable_type]'] = subjectType;

        const subjectId = ($('#f-subject-id').val() || '').trim();
        if (subjectId) params['filter[auditable_id]'] = subjectId;

        /*
         * A date range is sent as a timestamp range with both ends spelled out.
         * `between` on a bare date would compare against midnight and silently
         * drop everything that happened during the last day of the window -
         * which on an audit search is the day you are looking for.
         */
        const from = $('#f-from').val();
        const to = $('#f-to').val();
        if (from || to) {
            params['filter[created_at][between]'] =
                (from || '1970-01-01') + ' 00:00:00,' + (to || '2999-12-31') + ' 23:59:59';
        }

        $.getJSON('/api/v1/audit-logs', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#audit-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">Could not load the audit trail.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#audit-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">No entries match.</td></tr>');
        } else {
            $('#audit-rows').html(items.map(function (entry) {
                return '<tr>'
                    + '<td class="small text-nowrap">' + when(entry.created_at) + '</td>'
                    + '<td class="small">' + actorCell(entry) + '</td>'
                    + '<td class="small"><span class="font-monospace">' + CRM.escape(entry.action) + '</span></td>'
                    + '<td class="small">' + subjectCell(entry) + '</td>'
                    + '<td class="small">' + changeCell(entry) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#audit-meta').text(
            meta.total + ' entr' + (meta.total === 1 ? 'y' : 'ies')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    function when(iso) {
        if (!iso) return '—';
        const at = new Date(iso);
        return CRM.escape(at.toLocaleDateString()) + '<div class="text-muted">'
            + CRM.escape(at.toLocaleTimeString()) + '</div>';
    }

    /*
     * A null actor is a fact about the event - a scheduled advance, a webhook, a
     * failed sign-in - not missing data, so it is stated rather than blanked.
     */
    function actorCell(entry) {
        if (!entry.actor) return '<span class="text-muted">System</span>';

        let html = CRM.escape(entry.actor.name);
        if (entry.ip_address) {
            html += '<div class="text-muted font-monospace">' + CRM.escape(entry.ip_address) + '</div>';
        }

        return html;
    }

    function subjectCell(entry) {
        if (!entry.subject_type) return '<span class="text-muted">—</span>';

        return CRM.escape(entry.subject_label)
            + ' <span class="text-muted">#' + CRM.escape(entry.subject_id) + '</span>';
    }

    function changeCell(entry) {
        let html = entry.description
            ? '<div>' + CRM.escape(entry.description) + '</div>'
            : '';

        const before = entry.old_values || {};
        const after = entry.new_values || {};

        // Before and after are the whole reason the trail can answer anything,
        // so they are rendered per key rather than left as a JSON blob.
        Object.keys(after).forEach(function (key) {
            const to = after[key];
            if (to === null || to === '' || to === undefined) return;

            const from = before[key];
            html += '<div class="text-muted"><span class="font-monospace">' + CRM.escape(key) + '</span>: '
                + (from === undefined || from === null
                    ? CRM.escape(String(to))
                    : CRM.escape(String(from)) + ' → ' + CRM.escape(String(to)))
                + '</div>';
        });

        return html || '<span class="text-muted">—</span>';
    }

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#f-actor, #f-subject-type').on('change', function () { page = 1; load(); });
    $('#f-q').on('keypress', function (e) { if (e.which === 13) { page = 1; load(); } });

    $('#f-clear').on('click', function () {
        $('#f-actor, #f-subject-type').val('');
        $('#f-q, #f-from, #f-to, #f-subject-id').val('');
        page = 1;
        load();
    });

    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
