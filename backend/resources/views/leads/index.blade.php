@extends('layouts.app')
@section('title', 'Leads')

@section('content')
    @permission('leads.create')
        <div class="d-flex justify-content-end mb-3">
            <a href="{{ route('web.leads.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New lead
            </a>
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
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;

    function load() {
        const params = { page: page, sort: $('#f-sort').val() };

        const q = $('#f-q').val();
        if (q) params.q = q;

        const status = $('#f-status').val();
        // The API rejects unknown filter fields outright rather than ignoring
        // them, so only send a filter that actually has a value.
        if (status) params['filter[status]'] = status;

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
});
</script>
@endpush
