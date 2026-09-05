@extends('layouts.app')
@section('title', 'Interested Leads')

@section('content')
    <p class="text-muted small mb-3">
        The maintained interested / hot / warm / product-wise views (FR-INT-03). Hottest first by default —
        this is the list a telecaller works from the top of to find who to call next.
    </p>

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-temperature">Temperature</label>
                    <select id="f-temperature" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($temperatures as $temperature)
                            <option value="{{ $temperature->value }}">{{ $temperature->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-product">Product</label>
                    <select id="f-product" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-status">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach (\App\Enums\LeadStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-sort">Sort</label>
                    <select id="f-sort" class="form-select form-select-sm">
                        <option value="">Hottest first</option>
                        <option value="-last_engagement_at">Most recently active</option>
                        <option value="-created_at">Newest first</option>
                        <option value="created_at">Oldest first</option>
                    </select>
                </div>
                <div class="col-md-1 d-grid">
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
                    <th>Lead</th>
                    {{-- The two columns this screen exists for: a telecaller
                         scans these first, so they lead the row. --}}
                    <th>Temperature</th>
                    <th>Score</th>
                    <th>Matching product</th>
                    <th>Owner</th>
                    <th class="text-end">Last activity</th>
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

    // Same tones as the lead detail score panel (BR-SCORE-01) - a Hot badge
    // means the same thing wherever it is drawn. LeadResource returns the raw
    // enum value, not a label, so the label is mapped here too.
    const temperatureTone = { hot: 'danger', warm: 'warning', cold: 'info', dormant: 'secondary' };
    const temperatureLabel = { hot: 'Hot', warm: 'Warm', cold: 'Cold', dormant: 'Dormant' };

    /*
     * GET /interested-leads only allows filter[temperature|status|assigned_to|
     * lead_source_id] plus a plain product_id - an unknown or blank filter
     * 422s, so only a field that actually has a value is sent.
     */
    function filters() {
        const params = {};

        const temperature = $('#f-temperature').val();
        if (temperature) params['filter[temperature]'] = temperature;

        const status = $('#f-status').val();
        if (status) params['filter[status]'] = status;

        const productId = $('#f-product').val();
        if (productId) params.product_id = productId;

        return params;
    }

    function load() {
        const params = $.extend(
            { page: page, include: 'leadProducts.product' },
            filters(),
        );

        const sort = $('#f-sort').val();
        if (sort) params.sort = sort;

        $.getJSON('/api/v1/interested-leads', params)
            .done(function (response) {
                render(response.data.items, response.data.meta);
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#lead-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">Could not load leads.</td></tr>');
            });
    }

    /* The products a telecaller would actually mention on the call - not every
       product ever touched, just the ones still marked interested (BR-PROD-01). */
    function matchingProducts(lead) {
        const names = (lead.products || [])
            .filter(function (p) { return p.interest_status === 'interested'; })
            .map(function (p) { return p.product ? p.product.name : null; })
            .filter(Boolean);

        return names.length ? CRM.escape(names.join(', ')) : '<span class="text-muted">—</span>';
    }

    function render(items, meta) {
        if (!items.length) {
            $('#lead-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">No leads match.</td></tr>');
        } else {
            $('#lead-rows').html(items.map(function (lead) {
                const tone = temperatureTone[lead.temperature] || 'secondary';

                return '<tr data-href="/leads/' + lead.id + '">'
                    + '<td>' + CRM.escape(lead.name)
                    + (lead.company ? '<div class="small text-muted">' + CRM.escape(lead.company) + '</div>' : '')
                    + '</td>'
                    + '<td><span class="badge fs-6 text-bg-' + tone + '">' + CRM.escape(temperatureLabel[lead.temperature] || lead.temperature) + '</span></td>'
                    + '<td>'
                    + '<span class="fw-semibold fs-5">' + lead.score + '</span>'
                    + '<div class="progress mt-1" style="height:.3rem;width:5rem">'
                    + '<div class="progress-bar bg-' + tone + '" style="width:' + Math.max(0, Math.min(100, lead.score)) + '%"></div>'
                    + '</div>'
                    + '</td>'
                    + '<td class="small">' + matchingProducts(lead) + '</td>'
                    + '<td class="small">' + CRM.escape(lead.assigned_to ? lead.assigned_to.name : 'Unassigned') + '</td>'
                    + '<td class="small text-end text-muted">'
                    + (lead.last_engagement_at ? lead.last_engagement_at.substring(0, 16).replace('T', ' ') : 'Never')
                    + '</td>'
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
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
