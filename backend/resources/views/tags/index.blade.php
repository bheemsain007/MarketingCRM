{{--
    Tag vocabulary administration (FR-LEAD-03, BR-INT-02).

    Tags are free-form labels a telecaller puts on a lead, and the Interest
    Engine writes into the same table when it labels a lead automatically. That
    second source is what makes this screen worth having: until now the only way
    to see what the vocabulary had grown into was to read the lead form's
    checkboxes, and there was no way at all to correct a typo in one.

    Two kinds of tag, and only one of them is editable. A system tag is applied
    by the Interest Engine, which finds it BY NAME - so renaming one stops the
    engine finding it, and deleting one strips the label off every lead carrying
    it. They are listed here (an admin who cannot see them cannot explain a
    label a lead already has) with no controls on them. The refusal is TagPolicy's,
    not this page's: hiding a button is usability, the API is the gate.
--}}
@extends('layouts.app')
@section('title', 'Tags')

@section('content')
    @php($canManage = auth()->user()->hasPermission('settings.manage'))

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="f-kind">Kind</label>
                    <select id="f-kind" class="form-select form-select-sm">
                        <option value="">All tags</option>
                        <option value="0">Editable only</option>
                        <option value="1">Applied automatically</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button id="f-apply" class="btn btn-sm btn-primary">Apply</button>
                </div>
                @permission('settings.manage')
                    <div class="col-md-6 d-flex justify-content-md-end">
                        <button class="btn btn-sm btn-primary" id="new-tag">
                            <i class="bi bi-plus-lg me-1"></i>New tag
                        </button>
                    </div>
                @endpermission
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>Tag</th><th>Slug</th><th>Kind</th><th class="text-end">Leads</th>
                    @if ($canManage)
                        <th class="text-end">Actions</th>
                    @endif
                </tr>
                </thead>
                <tbody id="tag-rows">
                <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="tag-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Tags applied automatically belong to the Interest Engine, which finds them by name — they can be read
        here but not renamed or deleted. Deleting a tag you own also removes the label from every lead
        carrying it; there is no archive to restore it from.
    </p>

    @permission('settings.manage')
        {{-- One modal for create and rename: the two differ only in the endpoint
             and whether the fields start populated. --}}
        <div class="modal fade" id="tag-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6" id="tag-modal-title">New tag</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label small mb-1" for="t-name">Name <span class="text-danger">*</span></label>
                                <input type="text" id="t-name" class="form-control form-control-sm" maxlength="60">
                                <div class="invalid-feedback"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1" for="t-color">Colour</label>
                                <input type="color" id="t-color" class="form-control form-control-sm form-control-color"
                                       value="#6b7280">
                                <div class="invalid-feedback"></div>
                            </div>
                        </div>

                        {{-- Stated in the form, because the slug is what the
                             uniqueness rule is on and two names that look
                             different can collide on it. --}}
                        <p class="form-text small mb-0 mt-2">
                            The handle is derived from the name, so “VIP” and “vip” are the same tag.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="tag-save">Save tag</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Deletion is permanent AND it takes the label off leads, so the
             confirmation states the count rather than asking in the abstract. --}}
        <div class="modal fade" id="delete-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6">Delete tag</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small mb-2" id="delete-summary"></p>
                        <p class="small text-muted mb-0" id="delete-detail"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-danger" id="confirm-delete">Delete tag</button>
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
    let loaded = [];
    let editingId = null;
    let deletingId = null;

    const canManage = @json($canManage);
    const columnCount = canManage ? 5 : 4;

    const tagModal = canManage ? new bootstrap.Modal(document.getElementById('tag-modal')) : null;
    const deleteModal = canManage ? new bootstrap.Modal(document.getElementById('delete-modal')) : null;

    function load() {
        const params = { page: page };

        // Blank means "all". The API rejects an unknown or empty filter field
        // with a 422 rather than ignoring it, so it must be omitted.
        const kind = $('#f-kind').val();
        if (kind !== '') params['filter[is_system]'] = kind;

        $.getJSON('/api/v1/tags', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#tag-rows').html('<tr><td colspan="' + columnCount + '" class="text-center text-muted py-4">Could not load the list.</td></tr>');
            });
    }

    function render(items, meta) {
        loaded = items;

        if (!items.length) {
            $('#tag-rows').html('<tr><td colspan="' + columnCount + '" class="text-center text-muted py-4">No tags match.</td></tr>');
        } else {
            $('#tag-rows').html(items.map(function (tag) {
                return '<tr>'
                    + '<td>'
                    + '<span class="badge me-2" style="background-color: ' + CRM.escape(tag.color) + '">&nbsp;</span>'
                    + CRM.escape(tag.name)
                    + '</td>'
                    + '<td class="small text-muted"><code>' + CRM.escape(tag.slug) + '</code></td>'
                    + '<td class="small">' + (tag.is_system
                        ? '<span class="badge text-bg-secondary">Applied automatically</span>'
                        : '<span class="badge text-bg-light text-dark border">Editable</span>') + '</td>'
                    + '<td class="text-end small">' + CRM.escape(tag.leads_count) + '</td>'
                    + (canManage ? '<td class="text-end">' + actions(tag) + '</td>' : '')
                    + '</tr>';
            }).join(''));
        }

        $('#tag-meta').text(
            meta.total + ' tag' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    /*
     * `is_editable` comes from the API rather than being re-derived here.
     * A standing condition about a record belongs on the record: a system tag
     * shows why it has no controls, in the row, rather than producing a toast
     * after someone presses a button that was never going to work.
     */
    function actions(tag) {
        if (!tag.is_editable) {
            return '<span class="small text-muted">Interest Engine</span>';
        }

        return '<div class="btn-group btn-group-sm">'
            + '<button class="btn btn-outline-secondary edit-tag" data-id="' + tag.id + '">Rename</button>'
            + '<button class="btn btn-outline-danger delete-tag" data-id="' + tag.id + '">Delete</button>'
            + '</div>';
    }

    function findLoaded(id) {
        return loaded.filter(function (tag) { return tag.id === id; })[0];
    }

    function clearErrors() {
        $('#tag-modal .is-invalid').removeClass('is-invalid');
        $('#tag-modal .invalid-feedback').text('');
    }

    function showErrors(xhr) {
        const body = xhr.responseJSON || {};
        const errors = body.errors || [];
        let handled = false;

        clearErrors();

        errors.forEach(function (error) {
            if (!error.field) return;

            const input = $('#t-' + error.field);
            const feedback = input.siblings('.invalid-feedback');

            if (input.length && feedback.length) {
                input.addClass('is-invalid');
                feedback.text(error.message);
                handled = true;
            }
        });

        // A name that collides on the derived slug comes back as a 409 with no
        // field. The name is the only thing to fix, so it is reported there
        // rather than in a toast that closes the half-filled form.
        if (!handled && xhr.status === 409) {
            $('#t-name').addClass('is-invalid').siblings('.invalid-feedback').text(body.message);
            handled = true;
        }

        if (!handled) {
            tagModal.hide();
            CRM.alert(CRM.errorFrom(xhr));
        }
    }

    $('#new-tag').on('click', function () {
        editingId = null;
        clearErrors();
        $('#t-name').val('');
        $('#t-color').val('#6b7280');
        $('#tag-modal-title').text('New tag');
        tagModal.show();
    });

    $('#tag-rows').on('click', '.edit-tag', function () {
        const tag = findLoaded($(this).data('id'));
        if (!tag) return;

        editingId = tag.id;
        clearErrors();
        $('#t-name').val(tag.name);
        $('#t-color').val(tag.color);
        $('#tag-modal-title').text('Rename ' + tag.name);
        tagModal.show();
    });

    $('#tag-save').on('click', function () {
        $('#tag-save').prop('disabled', true);

        $.ajax({
            url: editingId ? '/api/v1/tags/' + editingId : '/api/v1/tags',
            method: editingId ? 'PATCH' : 'POST',
            data: { name: $('#t-name').val(), color: $('#t-color').val() }
        })
            .done(function (response) {
                tagModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(showErrors)
            .always(function () { $('#tag-save').prop('disabled', false); });
    });

    $('#tag-rows').on('click', '.delete-tag', function () {
        const tag = findLoaded($(this).data('id'));
        if (!tag) return;

        deletingId = tag.id;

        $('#delete-summary').text('Delete the tag “' + tag.name + '”?');

        const count = tag.leads_count || 0;
        $('#delete-detail').text(count
            ? count + ' lead' + (count === 1 ? '' : 's') + ' currently carry this label and will lose it. This cannot be undone.'
            : 'No lead carries this label. This cannot be undone.');

        deleteModal.show();
    });

    $('#confirm-delete').on('click', function () {
        $('#confirm-delete').prop('disabled', true);

        $.ajax({ url: '/api/v1/tags/' + deletingId, method: 'DELETE' })
            .done(function (response) {
                deleteModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) {
                deleteModal.hide();
                CRM.alert(CRM.errorFrom(xhr));
            })
            .always(function () { $('#confirm-delete').prop('disabled', false); });
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
