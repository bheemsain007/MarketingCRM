@extends('layouts.app')
@section('title', 'Leads')

@section('content')
    @permission('leads.create', 'leads.export')
        <div class="d-flex justify-content-end gap-2 mb-3">
            @permission('leads.export')
                {{--
                    Opens the panel; it does not export. A bulk export is the
                    whole lead database as a file (SEC-PII-04), so the button
                    that starts one is inside, next to the description of what
                    it would contain - not one stray click away from the list.
                --}}
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal" data-bs-target="#export-modal">
                    <i class="bi bi-download me-1"></i>Export
                </button>
            @endpermission

            @permission('leads.create')
                <a href="{{ route('web.leads.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>New lead
                </a>
            @endpermission
        </div>
    @endpermission

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="f-q">Search</label>
                    <input type="search" id="f-q" class="form-control form-control-sm"
                           placeholder="Name, company, phone or email">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-status">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-sort">Sort</label>
                    <select id="f-sort" class="form-select form-select-sm">
                        <option value="-created_at">Newest first</option>
                        <option value="created_at">Oldest first</option>
                        <option value="name">Name A–Z</option>
                        <option value="-priority">Priority</option>
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
                    <th>Name</th><th>Phone</th><th>City</th>
                    <th>Status</th><th>Owner</th><th class="text-end">Created</th>
                </tr>
                </thead>
                <tbody id="lead-rows">
                <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="lead-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    @permission('leads.export')
    <div class="modal fade" id="export-modal" tabindex="-1" aria-labelledby="export-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6" id="export-modal-title">Export leads to CSV</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">
                        The export contains every lead matching the filters on screen right now, with full
                        contact details. Sort order and paging do not apply — a CSV has neither.
                    </p>

                    {{-- Filled at open time from the same filter set the list is showing. --}}
                    <p class="small mb-3" id="export-scope"></p>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <button class="btn btn-sm btn-primary" id="export-start">
                            <i class="bi bi-download me-1"></i>Export current view
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" id="export-refresh">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="small text-muted">
                            <tr><th>Requested</th><th>Status</th><th class="text-end">Rows</th>
                                <th>Available until</th><th></th></tr>
                            </thead>
                            <tbody id="export-rows">
                            <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        Exports are prepared in the background — a large one keeps going after you close this
                        window. Finished files are deleted once they pass the date shown above.
                    </p>
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

    /*
     * The filter set the screen is currently showing.
     *
     * Shared with the export request rather than rebuilt there, so "export what
     * I am looking at" is the same audience the list drew (FR-LEAD-12) - two
     * copies of this would drift and nobody would notice until a CSV came back
     * with the wrong people in it.
     */
    function filters() {
        const params = {};

        const q = $('#f-q').val();
        if (q) params.q = q;

        const status = $('#f-status').val();
        // The API rejects unknown filter fields outright rather than ignoring
        // them, so only send a filter that actually has a value.
        if (status) params['filter[status]'] = status;

        return params;
    }

    function load() {
        const params = $.extend({ page: page, sort: $('#f-sort').val() }, filters());

        $.getJSON('/api/v1/leads', params)
            .done(function (response) {
                render(response.data.items, response.data.meta);
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#lead-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">Could not load leads.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#lead-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">No leads match.</td></tr>');
        } else {
            $('#lead-rows').html(items.map(function (lead) {
                return '<tr data-href="/leads/' + lead.id + '">'
                    + '<td>' + CRM.escape(lead.name)
                    + (lead.company ? '<div class="small text-muted">' + CRM.escape(lead.company) + '</div>' : '')
                    + '</td>'
                    + '<td class="small">' + CRM.escape(lead.phone_formatted || lead.phone) + '</td>'
                    + '<td class="small">' + CRM.escape(lead.city) + '</td>'
                    + '<td><span class="badge badge-status text-bg-' + CRM.statusClass(lead.status) + '">'
                    + CRM.escape(lead.status_label) + '</span>'
                    + (lead.is_suppressed ? ' <span class="badge text-bg-danger">DNC</span>' : '')
                    + '</td>'
                    + '<td class="small">' + CRM.escape(lead.assigned_to ? lead.assigned_to.name : 'Unassigned') + '</td>'
                    + '<td class="small text-end text-muted">' + lead.created_at.substring(0, 10) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#lead-meta').text(
            meta.total + ' lead' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    $('#lead-rows').on('click', 'tr[data-href]', function () {
        window.location = $(this).data('href');
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#f-q').on('keypress', function (e) { if (e.which === 13) { page = 1; load(); } });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();

@permission('leads.export')
    /*
     * Bulk CSV export (FR-LEAD-12, SEC-PII-04).
     *
     * The API accepts the job and queues it (202) - it does not return a file -
     * so this screen never offers a download until the export says it is
     * `completed`. Anything else would hand the user a link to a 404.
     */
    let exportPoll = null;

    function describeScope() {
        const parts = [];

        const q = $('#f-q').val();
        if (q) parts.push('search “' + CRM.escape(q) + '”');

        if ($('#f-status').val()) parts.push('status ' + CRM.escape($('#f-status option:selected').text()));

        // Said plainly, because an unfiltered export is the entire lead
        // database and the operator should know that before clicking.
        $('#export-scope').html(parts.length
            ? 'Exporting: ' + parts.join(', ') + '.'
            : '<span class="text-danger-emphasis">No filters — this exports every lead you can see.</span>');
    }

    function exportRow(row) {
        const tone = { completed: 'success', failed: 'danger',
                       processing: 'info', pending: 'secondary' }[row.status] || 'secondary';

        // `expires_at` is the download window the API enforces; past it the
        // endpoint refuses and the retention sweep deletes the file
        // (SEC-PII-05). Either way there is nothing to link to.
        const expired = row.expires_at && new Date(row.expires_at) <= new Date();

        let action = '';
        if (row.status === 'completed' && !expired) {
            action = '<a class="btn btn-sm btn-outline-primary" '
                + 'href="/api/v1/leads/exports/' + row.id + '/download">Download</a>';
        } else if (row.status === 'completed') {
            action = '<span class="small text-muted">File deleted</span>';
        } else if (row.status === 'failed') {
            action = '<span class="small text-muted">' + CRM.escape(row.failure_reason || '') + '</span>';
        } else {
            action = '<span class="small text-muted">Still preparing…</span>';
        }

        return '<tr>'
            + '<td class="small">' + row.requested_at.substring(0, 16).replace('T', ' ') + '</td>'
            + '<td><span class="badge text-bg-' + tone + '">' + CRM.escape(row.status_label) + '</span></td>'
            + '<td class="text-end small">' + (row.row_count === null ? '—' : row.row_count) + '</td>'
            + '<td class="small text-muted">'
            + (row.expires_at ? row.expires_at.substring(0, 10) : '—') + '</td>'
            + '<td class="text-end">' + action + '</td>'
            + '</tr>';
    }

    function loadExports() {
        $.getJSON('/api/v1/leads/exports')
            .done(function (response) {
                const items = response.data.items;

                $('#export-rows').html(items.length
                    ? items.map(exportRow).join('')
                    : '<tr><td colspan="5" class="text-center text-muted py-4">No exports yet.</td></tr>');

                // Poll only while something is actually running, and only while
                // the panel is on screen (mirrors the imports screen).
                clearTimeout(exportPoll);
                if (items.some(function (row) { return !row.is_finished; })) {
                    exportPoll = setTimeout(loadExports, 3000);
                }
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#export-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">Could not load exports.</td></tr>');
            });
    }

    $('#export-start').on('click', function () {
        const button = $(this).prop('disabled', true);

        $.post('/api/v1/leads/export', filters())
            .done(function (response) {
                // 202: accepted, not finished. The file is written on the queue.
                CRM.alert(response.message, 'success');
                loadExports();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
            .always(function () { button.prop('disabled', false); });
    });

    $('#export-refresh').on('click', loadExports);

    $('#export-modal')
        .on('show.bs.modal', function () { describeScope(); loadExports(); })
        .on('hidden.bs.modal', function () { clearTimeout(exportPoll); });
@endpermission
});
</script>
@endpush
