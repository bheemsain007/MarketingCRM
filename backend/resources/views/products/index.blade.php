@extends('layouts.app')
@section('title', 'Products')

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="show-archived">
                <label class="form-check-label small" for="show-archived">Show archived</label>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light small">
                    <tr><th>Code</th><th>Name</th><th>Delivery</th><th>Price</th><th>Status</th></tr>
                    </thead>
                    <tbody id="product-rows">
                    <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Products are archived rather than deleted, so historical lead interest and product reporting survive.
    </p>
@endsection

@push('scripts')
<script>
$(function () {
    function load() {
        const params = {};
        if ($('#show-archived').is(':checked')) params.with_archived = 1;

        $.getJSON('/api/v1/products', params)
            .done(function (response) {
                const items = response.data.items;

                if (!items.length) {
                    $('#product-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">No products.</td></tr>');
                    return;
                }

                $('#product-rows').html(items.map(function (product) {
                    return '<tr>'
                        + '<td class="small"><code>' + CRM.escape(product.code) + '</code></td>'
                        + '<td>' + CRM.escape(product.name) + '</td>'
                        + '<td class="small">' + CRM.escape(product.delivery_type) + '</td>'
                        + '<td class="small">' + CRM.escape(product.base_price ?? '—') + '</td>'
                        + '<td>' + (product.is_archived
                            ? '<span class="badge text-bg-secondary">Archived</span>'
                            : (product.is_active
                                ? '<span class="badge text-bg-success">Active</span>'
                                : '<span class="badge text-bg-warning">Inactive</span>')) + '</td>'
                        + '</tr>';
                }).join(''));
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    $('#show-archived').on('change', load);
    load();
});
</script>
@endpush
