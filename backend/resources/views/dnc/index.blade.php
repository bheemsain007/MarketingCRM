{{--
    Suppression administration (T-46) - FR-DNC-01/04, BR-DNC-06.

    Phase 19 owns the DNC Engine; this is its admin surface brought forward,
    because suppression has been WRITTEN since Phase 7 - by `Not Interested`,
    by Wrong Number call outcomes - with nothing able to read it back or lift
    it. A list that can only be added to is a compliance problem.

    Two things this screen deliberately does not do: it never decides
    contactability (DncService does, BR-DNC-01), and it never edits the
    reason x channel matrix (that is configuration, BR-DNC-04, Phase 19).
--}}
@extends('layouts.app')
@section('title', 'Do Not Contact')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="f-q">Search</label>
                    <input type="search" id="f-q" class="form-control form-control-sm"
                           placeholder="Lead name, phone or email">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-reason">Reason</label>
                    <select id="f-reason" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-active">Status</label>
                    <select id="f-active" class="form-select form-select-sm">
                        <option value="1">Active only</option>
                        <option value="0">Removed only</option>
                        <option value="">All</option>
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
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>Lead</th><th>Contact</th><th>Reason</th><th>Blocks</th>
                    <th>Source</th><th>Added</th><th class="text-end">Status</th>
                </tr>
                </thead>
                <tbody id="dnc-rows">
                <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="dnc-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Removing a suppression deactivates the record rather than deleting it — who lifted it, when,
        and why all survive the action (BR-DNC-06).
    </p>

    {{-- Removal modal. A reason is mandatory: the server rejects a removal
         without one, and "why was this person put back on the call list?" is
         the question a compliance review actually asks. --}}
    <div class="modal fade" id="remove-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Remove suppression</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="elevated-warning" class="alert alert-warning py-2 px-3 small d-none">
                        This is an explicit refusal to be contacted. Lift it only with a record of
                        the person asking for it.
                    </div>

                    <p class="small text-muted" id="remove-summary"></p>

                    <label class="form-label small mb-1" for="remove-reason">Reason <span class="text-danger">*</span></label>
                    <input type="text" id="remove-reason" class="form-control form-control-sm"
                           maxlength="255" placeholder="e.g. Customer called and asked to be reinstated">
                    <div class="invalid-feedback"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="confirm-remove">Remove suppression</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;
    let removing = null;
    const canRemove = @json(auth()->user()->hasPermission(App\Enums\Permission::DncRemove));
    const modal = new bootstrap.Modal(document.getElementById('remove-modal'));

    function load() {
        const params = { page: page };

        const q = $('#f-q').val();
        if (q) params.q = q;

        const reason = $('#f-reason').val();
        if (reason) params['filter[reason]'] = reason;

        // Empty means "all"; the API rejects unknown filters outright, so an
        // empty value must be omitted rather than sent.
        const active = $('#f-active').val();
        if (active !== '') params['filter[active]'] = active;

        $.getJSON('/api/v1/dnc', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#dnc-rows').html('<tr><td colspan="7" class="text-center text-muted py-4">Could not load the list.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#dnc-rows').html('<tr><td colspan="7" class="text-center text-muted py-4">No suppression records match.</td></tr>');
        } else {
            $('#dnc-rows').html(items.map(function (entry) {
                // A null channel does NOT mean "everything" - it means
                // everything this reason blocks (BR-DNC-02), which is why the
                // resolved channel list is rendered rather than a dash.
                const blocks = entry.blocked_channels.length === 7
                    ? 'All channels'
                    : entry.blocked_channels.join(', ');

                return '<tr>'
                    + '<td>' + (entry.lead
                        ? '<a href="/leads/' + entry.lead.id + '">' + CRM.escape(entry.lead.name) + '</a>'
                        : '<span class="text-muted">No lead record</span>') + '</td>'
                    + '<td class="small">' + CRM.escape(entry.phone || entry.email || '—') + '</td>'
                    + '<td class="small">' + CRM.escape(entry.reason_label) + '</td>'
                    + '<td class="small text-muted">' + CRM.escape(blocks) + '</td>'
                    + '<td class="small text-muted">' + CRM.escape(entry.source) + '</td>'
                    + '<td class="small text-muted">' + entry.created_at.substring(0, 10)
                    + (entry.created_by ? '<div>' + CRM.escape(entry.created_by.name) + '</div>' : '')
                    + '</td>'
                    + '<td class="text-end">' + statusCell(entry) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#dnc-meta').text(
            meta.total + ' record' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    function statusCell(entry) {
        if (!entry.active) {
            return '<span class="badge text-bg-secondary">Removed</span>'
                + (entry.removed_by ? '<div class="small text-muted">' + CRM.escape(entry.removed_by.name) + '</div>' : '');
        }

        if (!canRemove) {
            return '<span class="badge text-bg-danger">Suppressed</span>';
        }

        return '<button class="btn btn-sm btn-outline-danger remove-entry"'
            + ' data-id="' + entry.id + '"'
            + ' data-elevated="' + (entry.requires_elevated_removal ? '1' : '') + '"'
            + ' data-label="' + CRM.escape((entry.lead ? entry.lead.name : entry.phone || entry.email) + ' — ' + entry.reason_label) + '">'
            + 'Remove</button>';
    }

    $('#dnc-rows').on('click', '.remove-entry', function () {
        removing = $(this).data('id');

        $('#remove-summary').text($(this).data('label'));
        $('#elevated-warning').toggleClass('d-none', !$(this).data('elevated'));
        $('#remove-reason').val('').removeClass('is-invalid');
        $('#remove-modal .invalid-feedback').text('');

        modal.show();
    });

    $('#confirm-remove').on('click', function () {
        $('#confirm-remove').prop('disabled', true);

        $.ajax({
            url: '/api/v1/dnc/' + removing,
            method: 'DELETE',
            data: { reason: $('#remove-reason').val() }
        })
            .done(function (response) {
                modal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) {
                const errors = (xhr.responseJSON || {}).errors || [];
                const fieldError = errors.find(function (e) { return e.field === 'reason'; });

                if (fieldError) {
                    $('#remove-reason').addClass('is-invalid');
                    $('#remove-modal .invalid-feedback').text(fieldError.message);
                } else {
                    modal.hide();
                    CRM.alert(CRM.errorFrom(xhr));
                }
            })
            .always(function () { $('#confirm-remove').prop('disabled', false); });
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#f-q').on('keypress', function (e) { if (e.which === 13) { page = 1; load(); } });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
