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
    {{-- FR-DNC-01: somebody phones in and asks to be taken off the list. Until
         now that request could only be honoured by triggering an outcome that
         happens to suppress - the automatic sources (BR-DNC-07) were the only
         way in. --}}
    @permission('dnc.create')
        <div class="d-flex justify-content-end mb-3">
            <button type="button" id="add-open" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add to DNC
            </button>
        </div>
    @endpermission

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

    {{-- Manual suppression (FR-DNC-01). The lead is picked by searching the
         API rather than typed as an id: the search is data-scoped, so a
         telecaller can only suppress leads they are already allowed to see -
         the same rule the POST enforces server-side. --}}
    @permission('dnc.create')
        <div class="modal fade" id="add-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6">Add to Do Not Contact</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small mb-1" for="add-lead-q">
                            Lead <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="search" id="add-lead-q" class="form-control"
                                   placeholder="Name, company, phone or email">
                            <button class="btn btn-outline-secondary" type="button" id="add-lead-search">Search</button>
                        </div>
                        <select id="add-lead" class="form-select form-select-sm">
                            <option value="">Search for a lead first</option>
                        </select>
                        <div class="invalid-feedback"></div>

                        <label class="form-label small mb-1 mt-3" for="add-reason">
                            Reason <span class="text-danger">*</span>
                        </label>
                        <select id="add-reason" class="form-select form-select-sm">
                            @foreach ($reasons as $reason)
                                <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback"></div>

                        <label class="form-label small mb-1 mt-3" for="add-channel">Channel</label>
                        <select id="add-channel" class="form-select form-select-sm">
                            {{-- Left empty on purpose: the reason already decides
                                 which channels it blocks (BR-DNC-02), and naming a
                                 channel here NARROWS the entry to that one. --}}
                            <option value="">Everything this reason blocks</option>
                            @foreach (\App\Enums\Channel::cases() as $case)
                                <option value="{{ $case->value }}">Only {{ $case->label() }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback"></div>

                        <label class="form-label small mb-1 mt-3" for="add-note">Note</label>
                        <textarea id="add-note" class="form-control form-control-sm" rows="2" maxlength="1000"
                                  placeholder="e.g. Asked to be removed during a call on 14 March"></textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="confirm-add">Add suppression</button>
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
    let removing = null;
    const canRemove = @json(auth()->user()->hasPermission(App\Enums\Permission::DncRemove));
    const canCreate = @json(auth()->user()->hasPermission(App\Enums\Permission::DncCreate));
    const modal = new bootstrap.Modal(document.getElementById('remove-modal'));
    // The add modal only exists in the markup for a holder of dnc.create.
    const addModal = canCreate ? new bootstrap.Modal(document.getElementById('add-modal')) : null;

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

    if (canCreate) {
        // Field name -> control, so a 422 lands on the input that caused it
        // instead of a toast that makes the user guess.
        const addFields = {
            lead_id: '#add-lead',
            reason: '#add-reason',
            channel: '#add-channel',
            note: '#add-note'
        };

        function clearAddErrors() {
            $.each(addFields, function (field, selector) {
                $(selector).removeClass('is-invalid').next('.invalid-feedback').text('');
            });
        }

        function searchLeads() {
            const q = $('#add-lead-q').val();
            if (!q) return;

            $('#add-lead-search').prop('disabled', true);

            $.getJSON('/api/v1/leads', { q: q, per_page: 20 })
                .done(function (response) {
                    const items = response.data.items;

                    $('#add-lead').html(items.length
                        ? items.map(function (lead) {
                            return '<option value="' + lead.id + '">'
                                + CRM.escape(lead.name + ' — ' + (lead.phone_formatted || lead.phone))
                                + '</option>';
                        }).join('')
                        : '<option value="">No lead matches that search</option>');
                })
                .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
                .always(function () { $('#add-lead-search').prop('disabled', false); });
        }

        $('#add-open').on('click', function () {
            $('#add-lead-q').val('');
            $('#add-lead').html('<option value="">Search for a lead first</option>');
            $('#add-note').val('');
            $('#add-channel').val('');
            clearAddErrors();
            addModal.show();
        });

        $('#add-lead-search').on('click', searchLeads);
        $('#add-lead-q').on('keypress', function (e) { if (e.which === 13) { e.preventDefault(); searchLeads(); } });

        $('#confirm-add').on('click', function () {
            $('#confirm-add').prop('disabled', true);
            clearAddErrors();

            const data = { lead_id: $('#add-lead').val(), reason: $('#add-reason').val() };

            // Both are optional, and a blank channel must be OMITTED rather than
            // sent empty - an empty string is not a valid Channel case.
            const channel = $('#add-channel').val();
            if (channel) data.channel = channel;

            const note = $('#add-note').val();
            if (note) data.note = note;

            $.ajax({ url: '/api/v1/dnc', method: 'POST', data: data })
                .done(function (response) {
                    addModal.hide();
                    CRM.alert(response.message, 'success');
                    page = 1;
                    load();
                })
                .fail(function (xhr) {
                    const errors = (xhr.responseJSON || {}).errors || [];
                    let mapped = false;

                    errors.forEach(function (error) {
                        const selector = addFields[error.field];
                        if (!selector) return;

                        $(selector).addClass('is-invalid').next('.invalid-feedback').text(error.message);
                        mapped = true;
                    });

                    if (!mapped) {
                        addModal.hide();
                        CRM.alert(CRM.errorFrom(xhr));
                    }
                })
                .always(function () { $('#confirm-add').prop('disabled', false); });
        });
    }

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#f-q').on('keypress', function (e) { if (e.which === 13) { page = 1; load(); } });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
