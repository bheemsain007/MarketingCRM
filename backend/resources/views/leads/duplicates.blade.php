{{--
    Duplicate review (T-64) - BR-DUP-03/04.

    The queue BR-DUP-03 flags into. Both records are shown side by side because
    the decision is "are these the same person?", and it cannot be made from two
    ids - a screen that sent the reviewer to two other pages first would just be
    a list of homework.

    Nothing here merges on its own. Every merge is a person choosing which
    record survives, and it cannot be undone.
--}}
@extends('layouts.app')
@section('title', 'Duplicate review')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h5 mb-1">Duplicate review</h1>
            <p class="text-muted small mb-0">
                Leads sharing an email address with a different phone number. Phone is identity
                (BR-DUP-01); email is only a hint, so nothing is merged automatically.
            </p>
        </div>

        @permission('leads.archive')
            <button id="backfill" class="btn btn-sm btn-outline-secondary">
                Sweep existing leads
            </button>
        @endpermission
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label small mb-0" for="f-status">Show</label>
                <select id="f-status" class="form-select form-select-sm w-auto">
                    <option value="pending">Awaiting review</option>
                    <option value="dismissed">Marked as different people</option>
                    <option value="merged">Merged</option>
                </select>
            </div>
        </div>
    </div>

    <div id="candidates">
        <p class="text-muted small">Loading…</p>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="small text-muted" id="meta"></span>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
            <button class="btn btn-outline-secondary" id="page-next">Next</button>
        </div>
    </div>

    {{-- Merge confirmation. The warning is not decoration: repointing a lead's
         calls, messages and suppression into another record has no undo, and
         the reviewer should read that sentence before the button works. --}}
    <div class="modal fade" id="merge-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Merge these leads</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2 px-3 small">
                        <strong>This cannot be undone.</strong> Calls, messages, notes, follow-ups and
                        payments move to the surviving lead. If either record is suppressed, the survivor
                        stays suppressed.
                    </div>

                    <p class="small text-muted mb-2" id="merge-summary"></p>

                    <label class="form-label small mb-1" for="merge-note">Note</label>
                    <input type="text" id="merge-note" class="form-control form-control-sm"
                           maxlength="500" placeholder="e.g. Same person, second enquiry from a work phone">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-danger" id="confirm-merge">Merge</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Dismissal needs a reason: it is what stops the pair being raised again,
         so the next reviewer deserves to know why. --}}
    <div class="modal fade" id="dismiss-modal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h6">Different people</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        This pair will not be raised again. Two people at one company sharing an
                        address is the usual reason.
                    </p>
                    <label class="form-label small mb-1" for="dismiss-note">Why <span class="text-danger">*</span></label>
                    <input type="text" id="dismiss-note" class="form-control form-control-sm"
                           maxlength="500" placeholder="e.g. Colleagues sharing info@acme.example">
                    <div class="invalid-feedback"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" id="confirm-dismiss">Mark as different</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;
    let pending = null;
    const canResolve = @json(auth()->user()->hasPermission(App\Enums\Permission::LeadsArchive));
    const mergeModal = new bootstrap.Modal(document.getElementById('merge-modal'));
    const dismissModal = new bootstrap.Modal(document.getElementById('dismiss-modal'));

    function load() {
        $.getJSON('/api/v1/lead-duplicates', { page: page, 'filter[status]': $('#f-status').val() })
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#candidates').html('<p class="text-muted small">Could not load the queue.</p>');
            });
    }

    function side(candidate, lead, other) {
        if (!lead) return '<div class="col-md-6"><div class="card h-100"><div class="card-body">'
            + '<p class="text-muted small mb-0">This lead no longer exists.</p></div></div></div>';

        // The two facts that most often settle it, called out rather than
        // buried: how old the record is, and whether it is suppressed.
        const flags = [];
        if (lead.is_suppressed) flags.push('<span class="badge text-bg-danger">Suppressed</span>');
        flags.push('<span class="badge text-bg-secondary">' + CRM.escape(lead.status_label || '') + '</span>');

        return '<div class="col-md-6"><div class="card h-100"><div class="card-body">'
            + '<div class="d-flex justify-content-between align-items-start">'
            + '<div><a href="/leads/' + lead.id + '" class="fw-semibold">' + CRM.escape(lead.name) + '</a>'
            + '<div class="small text-muted">' + CRM.escape(lead.phone_e164 || '') + '</div></div>'
            + '<div class="text-end">' + flags.join(' ') + '</div></div>'
            + '<dl class="row small mt-2 mb-0">'
            + '<dt class="col-5 text-muted fw-normal">Email</dt><dd class="col-7">' + CRM.escape(lead.email || '—') + '</dd>'
            + '<dt class="col-5 text-muted fw-normal">Company</dt><dd class="col-7">' + CRM.escape(lead.company || '—') + '</dd>'
            + '<dt class="col-5 text-muted fw-normal">City</dt><dd class="col-7">' + CRM.escape(lead.city || '—') + '</dd>'
            + '<dt class="col-5 text-muted fw-normal">Created</dt><dd class="col-7">'
                + (lead.created_at ? lead.created_at.substring(0, 10) : '—') + '</dd>'
            + '</dl>'
            + (canResolve && candidate.status === 'pending'
                ? '<button class="btn btn-sm btn-outline-danger w-100 mt-3" data-merge="' + candidate.id + '"'
                    + ' data-survivor="' + lead.id + '" data-other="' + other.id + '">'
                    + 'Keep this one, merge the other in</button>'
                : '')
            + '</div></div></div>';
    }

    function render(items, meta) {
        if (!items.length) {
            $('#candidates').html('<div class="card"><div class="card-body text-center text-muted py-5">'
                + 'Nothing here. If this queue has never been swept, use “Sweep existing leads”.'
                + '</div></div>');
            $('#meta').text('');
            return;
        }

        $('#candidates').html(items.map(function (c) {
            return '<div class="card mb-3"><div class="card-header py-2 d-flex justify-content-between align-items-center">'
                + '<span class="small">Shared email <strong>' + CRM.escape(c.match_value || '') + '</strong></span>'
                + (canResolve && c.status === 'pending'
                    ? '<button class="btn btn-sm btn-link text-decoration-none" data-dismiss-id="' + c.id + '">'
                        + 'These are different people</button>'
                    : '<span class="small text-muted">' + CRM.escape(c.resolution_note || c.status) + '</span>')
                + '</div><div class="card-body"><div class="row g-3">'
                + side(c, c.lead, c.duplicate)
                + side(c, c.duplicate, c.lead)
                + '</div></div></div>';
        }).join(''));

        $('#meta').text('Page ' + meta.current_page + ' of ' + meta.last_page + ' — ' + meta.total + ' total');
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    $('#candidates').on('click', '[data-merge]', function () {
        pending = { id: $(this).data('merge'), survivor: $(this).data('survivor'), other: $(this).data('other') };
        $('#merge-summary').text('Lead #' + pending.other + ' will be merged into lead #' + pending.survivor + '.');
        $('#merge-note').val('');
        mergeModal.show();
    });

    $('#confirm-merge').on('click', function () {
        $.ajax({
            url: '/api/v1/lead-duplicates/' + pending.id + '/merge',
            method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ survivor_id: pending.survivor, note: $('#merge-note').val() || null }),
        })
            .done(function (response) {
                mergeModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#candidates').on('click', '[data-dismiss-id]', function () {
        pending = { id: $(this).data('dismiss-id') };
        $('#dismiss-note').val('').removeClass('is-invalid');
        dismissModal.show();
    });

    $('#confirm-dismiss').on('click', function () {
        const note = $('#dismiss-note').val();

        if (!note) {
            $('#dismiss-note').addClass('is-invalid').siblings('.invalid-feedback')
                .text('Say why, so the next reviewer does not have to work it out again.');
            return;
        }

        $.ajax({
            url: '/api/v1/lead-duplicates/' + pending.id + '/dismiss',
            method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ note: note }),
        })
            .done(function (response) {
                dismissModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#backfill').on('click', function () {
        const button = $(this).prop('disabled', true);

        $.ajax({ url: '/api/v1/lead-duplicates/backfill', method: 'POST' })
            .done(function (response) {
                CRM.alert(response.data.created + ' pair(s) found.', 'success');
                page = 1;
                load();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); })
            .always(function () { button.prop('disabled', false); });
    });

    $('#f-status').on('change', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
