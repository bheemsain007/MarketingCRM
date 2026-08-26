{{--
    Product catalogue administration (PROJECT_REQUIREMENTS §1.1, P1-P7).

    Kept as data rather than an enum so the business can add a product without a
    deployment - which is only true if there is a screen to add one from, which
    is what this page is.

    Every write goes to /api/v1/products (ADR-A). Nothing here decides what a
    valid product is: the code format, the delivery types and the duplicate-code
    conflict all come back from the API, so the browser and the Flutter app
    cannot drift apart on the rules.

    Archive, never delete: `lead_products` holds a RESTRICT foreign key, and
    product history feeds performance reporting (ProductService::archive).
--}}
@extends('layouts.app')
@section('title', 'Products')

@section('content')
    @php($canManage = auth()->user()->hasPermission('products.manage'))

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="show-archived">
            <label class="form-check-label small" for="show-archived">Show archived</label>
        </div>

        @permission('products.manage')
            <button class="btn btn-sm btn-primary" id="new-product">
                <i class="bi bi-plus-lg me-1"></i>New product
            </button>
        @endpermission
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>Code</th><th>Name</th><th>Delivery</th><th>Price</th><th>Status</th>
                    @if ($canManage)
                        <th class="text-end">Actions</th>
                    @endif
                </tr>
                </thead>
                <tbody id="product-rows">
                <tr><td colspan="{{ $canManage ? 6 : 5 }}" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="product-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Products are archived rather than deleted, so historical lead interest and product reporting survive.
    </p>

    @permission('products.manage')
        {{-- One modal for create and edit. The two differ only in the endpoint
             and whether the fields start populated, so a second copy of the form
             would be a second place for the field list to go stale. --}}
        <div class="modal fade" id="product-modal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6" id="product-modal-title">New product</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label small mb-1" for="p-code">Code <span class="text-danger">*</span></label>
                                <input type="text" id="p-code" class="form-control form-control-sm"
                                       maxlength="50" placeholder="NEWS_PORTAL">
                                <div class="invalid-feedback"></div>
                                <div class="form-text small">Uppercase letters, numbers and underscores.</div>
                            </div>

                            <div class="col-md-7">
                                <label class="form-label small mb-1" for="p-name">Name <span class="text-danger">*</span></label>
                                <input type="text" id="p-name" class="form-control form-control-sm" maxlength="150">
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small mb-1" for="p-delivery_type">Delivery <span class="text-danger">*</span></label>
                                <select id="p-delivery_type" class="form-select form-select-sm">
                                    <option value="saas">SaaS</option>
                                    <option value="project">Project</option>
                                    <option value="service">Service</option>
                                </select>
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-5">
                                <label class="form-label small mb-1" for="p-base_price">Base price</label>
                                <input type="number" id="p-base_price" class="form-control form-control-sm"
                                       min="0" step="0.01">
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label small mb-1" for="p-currency">Currency</label>
                                <input type="text" id="p-currency" class="form-control form-control-sm"
                                       maxlength="3" value="INR">
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-12">
                                <label class="form-label small mb-1" for="p-description">Description</label>
                                <textarea id="p-description" class="form-control form-control-sm" rows="3"
                                          maxlength="2000"></textarea>
                                <div class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small mb-1" for="p-sort_order">Sort order</label>
                                <input type="number" id="p-sort_order" class="form-control form-control-sm"
                                       min="0" max="65535" value="0">
                                <div class="invalid-feedback"></div>
                                <div class="form-text small">Lowest first in every product list.</div>
                            </div>

                            <div class="col-md-8 d-flex align-items-center">
                                <div class="form-check form-switch mt-3">
                                    <input class="form-check-input" type="checkbox" id="p-is_active" checked>
                                    <label class="form-check-label small" for="p-is_active">
                                        Active — offered on new leads, campaigns and quotations
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="product-save">Save product</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Archiving is reversible, but it pulls the product out of every
             dropdown and campaign audience, so it is confirmed rather than
             fired from a single click on a row. --}}
        <div class="modal fade" id="archive-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6">Archive product</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small mb-2" id="archive-summary"></p>
                        <p class="small text-muted mb-0" id="archive-detail"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-danger" id="confirm-archive">Archive product</button>
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
    let loaded = [];      // the current page's products, so Edit needs no second read
    let editingId = null; // null while creating
    let archivingId = null;

    const canManage = @json($canManage);
    const columnCount = canManage ? 6 : 5;

    // The modals only exist for someone who may write, so they are only built
    // for that person - `new bootstrap.Modal(null)` throws.
    const productModal = canManage ? new bootstrap.Modal(document.getElementById('product-modal')) : null;
    const archiveModal = canManage ? new bootstrap.Modal(document.getElementById('archive-modal')) : null;

    function load() {
        const params = { page: page };
        if ($('#show-archived').is(':checked')) params.with_archived = 1;

        $.getJSON('/api/v1/products', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#product-rows').html('<tr><td colspan="' + columnCount + '" class="text-center text-muted py-4">Could not load the list.</td></tr>');
            });
    }

    function render(items, meta) {
        loaded = items;

        if (!items.length) {
            $('#product-rows').html('<tr><td colspan="' + columnCount + '" class="text-center text-muted py-4">No products.</td></tr>');
        } else {
            $('#product-rows').html(items.map(function (product) {
                return '<tr>'
                    + '<td class="small"><code>' + CRM.escape(product.code) + '</code></td>'
                    + '<td>' + CRM.escape(product.name) + '</td>'
                    + '<td class="small">' + CRM.escape(product.delivery_type) + '</td>'
                    + '<td class="small">' + CRM.escape(product.base_price === null
                        ? '—'
                        : (product.currency || '') + ' ' + product.base_price) + '</td>'
                    + '<td>' + (product.is_archived
                        ? '<span class="badge text-bg-secondary">Archived</span>'
                        : (product.is_active
                            ? '<span class="badge text-bg-success">Active</span>'
                            : '<span class="badge text-bg-warning">Inactive</span>')) + '</td>'
                    + (canManage ? '<td class="text-end">' + actions(product) + '</td>' : '')
                    + '</tr>';
            }).join(''));
        }

        $('#product-meta').text(
            meta.total + ' product' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    // An archived product offers Restore and nothing else: editing a record
    // that is out of circulation is a control that only ever confuses.
    function actions(product) {
        if (product.is_archived) {
            return '<button class="btn btn-sm btn-outline-success restore-product" data-id="' + product.id + '">Restore</button>';
        }

        return '<div class="btn-group btn-group-sm">'
            + '<button class="btn btn-outline-secondary edit-product" data-id="' + product.id + '">Edit</button>'
            + '<button class="btn btn-outline-danger archive-product" data-id="' + product.id + '">Archive</button>'
            + '</div>';
    }

    function findLoaded(id) {
        return loaded.filter(function (product) { return product.id === id; })[0];
    }

    function clearErrors() {
        $('#product-modal .is-invalid').removeClass('is-invalid');
        $('#product-modal .invalid-feedback').text('');
    }

    function fill(product) {
        $('#p-code').val(product ? product.code : '');
        $('#p-name').val(product ? product.name : '');
        $('#p-delivery_type').val(product ? product.delivery_type : 'saas');
        $('#p-base_price').val(product ? product.base_price : '');
        $('#p-currency').val(product ? (product.currency || 'INR') : 'INR');
        $('#p-description').val(product ? (product.description || '') : '');
        $('#p-sort_order').val(product ? product.sort_order : 0);
        $('#p-is_active').prop('checked', product ? product.is_active : true);
    }

    function payload() {
        const data = {
            code: $('#p-code').val(),
            name: $('#p-name').val(),
            delivery_type: $('#p-delivery_type').val(),
            description: $('#p-description').val(),
            // 1/0, not true/false: Laravel's `boolean` rule rejects the strings
            // jQuery would otherwise serialise a JS boolean into.
            is_active: $('#p-is_active').is(':checked') ? 1 : 0,
            sort_order: Number($('#p-sort_order').val() || 0)
        };

        /*
         * base_price and currency are NOT NULL columns with defaults, and their
         * validation rule is `nullable` - so a blank field would validate and
         * then be written as an explicit null. Omitting them instead lets the
         * column default stand on create and leaves the stored value alone on
         * edit.
         */
        const price = $('#p-base_price').val();
        if (price !== '') data.base_price = price;

        const currency = $.trim($('#p-currency').val());
        if (currency !== '') data.currency = currency;

        return data;
    }

    function showErrors(xhr) {
        const body = xhr.responseJSON || {};
        const errors = body.errors || [];
        let handled = false;

        clearErrors();

        errors.forEach(function (error) {
            if (!error.field) return;

            const input = $('#p-' + error.field);
            const feedback = input.siblings('.invalid-feedback');

            if (input.length && feedback.length) {
                input.addClass('is-invalid');
                feedback.text(error.message);
                handled = true;
            }
        });

        /*
         * A duplicate code arrives as a 409 with no field - the API is
         * reporting a state conflict rather than a validation failure - but the
         * only thing the operator can act on is the code, and its message names
         * whether the clash is with an archived product. Shown on the field
         * rather than in a toast, because closing the form would throw away
         * everything else they had typed.
         */
        if (!handled && xhr.status === 409) {
            $('#p-code').addClass('is-invalid').siblings('.invalid-feedback').text(body.message);
            handled = true;
        }

        if (!handled) {
            productModal.hide();
            CRM.alert(CRM.errorFrom(xhr));
        }
    }

    $('#new-product').on('click', function () {
        editingId = null;
        clearErrors();
        fill(null);
        $('#product-modal-title').text('New product');
        productModal.show();
    });

    $('#product-rows').on('click', '.edit-product', function () {
        const product = findLoaded($(this).data('id'));
        if (!product) return;

        editingId = product.id;
        clearErrors();
        fill(product);
        $('#product-modal-title').text('Edit ' + product.name);
        productModal.show();
    });

    $('#product-save').on('click', function () {
        $('#product-save').prop('disabled', true);

        $.ajax({
            url: editingId ? '/api/v1/products/' + editingId : '/api/v1/products',
            method: editingId ? 'PATCH' : 'POST',
            data: payload()
        })
            .done(function (response) {
                productModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(showErrors)
            .always(function () { $('#product-save').prop('disabled', false); });
    });

    $('#product-rows').on('click', '.archive-product', function () {
        const product = findLoaded($(this).data('id'));
        if (!product) return;

        archivingId = product.id;

        $('#archive-summary').text(
            'Archive ' + product.name + ' (' + product.code + ')?'
        );

        // The interest count is why archiving exists rather than deletion, so
        // it is stated here rather than left as a surprise.
        const interest = product.lead_interest_count || 0;
        $('#archive-detail').text(
            'It will be deactivated and hidden from new leads, campaigns and quotations. '
            + (interest
                ? interest + ' lead' + (interest === 1 ? '' : 's') + ' recorded interest in it; that history is kept, and the product can be restored.'
                : 'It can be restored at any time.')
        );

        archiveModal.show();
    });

    $('#confirm-archive').on('click', function () {
        $('#confirm-archive').prop('disabled', true);

        $.ajax({ url: '/api/v1/products/' + archivingId, method: 'DELETE' })
            .done(function (response) {
                archiveModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) {
                archiveModal.hide();
                CRM.alert(CRM.errorFrom(xhr));
            })
            .always(function () { $('#confirm-archive').prop('disabled', false); });
    });

    $('#product-rows').on('click', '.restore-product', function () {
        const button = $(this).prop('disabled', true);

        $.ajax({ url: '/api/v1/products/' + button.data('id') + '/restore', method: 'POST' })
            .done(function (response) {
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) {
                button.prop('disabled', false);
                CRM.alert(CRM.errorFrom(xhr));
            });
    });

    // Archived rows only exist on the archived view, so the page returns to the
    // first page when the switch flips rather than landing on a page that no
    // longer exists.
    $('#show-archived').on('change', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
