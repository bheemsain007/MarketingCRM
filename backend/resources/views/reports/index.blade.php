{{--
    Business report dashboards (T-60, FR-RPT-02/03/04/06).

    A shell: every figure comes from /api/v1/reports/*, so there is one
    implementation of each formula and this page cannot disagree with the API
    (ADR-A).

    The one rule the markup exists to enforce is FR-RPT-06 - **every rate is
    drawn with its denominator**. The API returns rates as objects carrying
    `numerator`, `denominator` and `of` precisely so a screen cannot render a
    bare percentage, and `rate()` below is the only place that formats one.

    Chart.js is loaded from a CDN like Bootstrap and jQuery, for the same reason
    (DEPLOYMENT §3A: no Node on the server). Same CSP trade-off, tracked as T-39.
--}}
@extends('layouts.app')
@section('title', 'Reports')

@push('styles')
<style>
    .tile { border-left: 3px solid #e5e7eb; }
    .tile .figure { font-size: 1.5rem; font-weight: 600; line-height: 1.1; }
    .tile .denom { font-size: .75rem; color: #6b7280; }
    .chart-box { position: relative; height: 260px; }
</style>
@endpush

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-from">From</label>
                    <input type="date" id="f-from" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-to">To</label>
                    <input type="date" id="f-to" class="form-control form-control-sm">
                </div>
                <div class="col-md-3 d-grid">
                    <button id="f-apply" class="btn btn-sm btn-primary">Apply</button>
                </div>
                <div class="col-md-3 small text-muted align-self-center" id="period-label"></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3" id="tiles"></div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2"><span class="small fw-semibold">Lead funnel</span></div>
                <div class="card-body"><div class="chart-box"><canvas id="chart-funnel"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2"><span class="small fw-semibold">Money</span></div>
                <div class="card-body">
                    <div class="chart-box"><canvas id="chart-money"></canvas></div>
                    {{-- Said out loud, because summing these is the classic
                         mistake and the chart puts them side by side. --}}
                    <p class="text-muted small mb-0 mt-2">
                        Booked and collected are separate figures and are never added together.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2"><span class="small fw-semibold">Why deals were lost</span></div>
                <div class="card-body"><div class="chart-box"><canvas id="chart-loss"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2"><span class="small fw-semibold">Lead sources</span></div>
                <div class="card-body"><div class="chart-box"><canvas id="chart-sources"></canvas></div></div>
            </div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header py-2"><span class="small fw-semibold">Revenue by product</span></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr class="small"><th>Product</th><th class="text-end">Payments</th><th class="text-end">Collected</th></tr>
                        </thead>
                        <tbody id="product-rows">
                        <tr><td colspan="3" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
$(function () {
    const charts = {};

    /**
     * The only place a rate is formatted (FR-RPT-06).
     *
     * A null value means the denominator was zero, and that is rendered as an
     * em dash rather than 0% - "nothing happened" and "things happened and none
     * succeeded" are different facts.
     */
    function rate(r) {
        if (!r) return '—';
        if (r.value === null) return '<span class="text-muted">—</span>';

        return r.value.toFixed(1) + '%'
            + '<div class="denom">' + r.numerator + ' of ' + r.denominator + ' ' + CRM.escape(r.of) + '</div>';
    }

    function money(n) {
        return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    }

    function tile(label, figure, note) {
        return '<div class="col-6 col-lg-3">'
            + '<div class="card tile h-100"><div class="card-body py-3">'
            + '<div class="small text-muted">' + CRM.escape(label) + '</div>'
            + '<div class="figure">' + figure + '</div>'
            + (note ? '<div class="denom">' + note + '</div>' : '')
            + '</div></div></div>';
    }

    function draw(id, config) {
        if (charts[id]) charts[id].destroy();
        charts[id] = new Chart(document.getElementById(id), config);
    }

    function params() {
        const p = {};
        if ($('#f-from').val()) p.from = $('#f-from').val();
        if ($('#f-to').val()) p.to = $('#f-to').val();
        return p;
    }

    function load() {
        $.getJSON('/api/v1/reports/summary', params())
            .done(function (response) {
                const s = response.data.summary;
                const period = response.data.period;

                // Stated so two people comparing dashboards can see they used
                // the same window and the same timezone.
                $('#period-label').text(period.from + ' to ' + period.to + ' (' + period.timezone + ')');

                $('#tiles').html([
                    tile('New leads', s.leads.new, 'of ' + s.leads.total + ' total'),
                    tile('Interested', s.leads.interested, rate(s.leads.interest_rate)),
                    tile('Hot leads', s.leads.hot, 'right now'),
                    tile('Connected calls', s.calls.connected, rate(s.calls.connect_rate)),
                    tile('Talk time', Math.round(s.calls.talk_time_seconds / 60) + ' min',
                        s.calls.average_duration_seconds === null
                            ? 'no connected calls'
                            : 'avg ' + s.calls.average_duration_seconds + 's per connected call'),
                    tile('Sales won', s.sales.won, ''),
                    tile('Collected', money(s.revenue.collected), 'booked ' + money(s.revenue.booked)),
                    tile('Conversion', rate(s.conversion.lead_to_sale), ''),
                ].join(''));

                draw('chart-funnel', {
                    type: 'bar',
                    data: {
                        labels: ['New', 'Contacted', 'Interested', 'Hot'],
                        datasets: [{
                            label: 'Leads',
                            data: [s.leads.new, s.leads.contacted, s.leads.interested, s.leads.hot],
                            backgroundColor: '#3b82f6'
                        }]
                    },
                    options: { maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });

                draw('chart-money', {
                    type: 'bar',
                    data: {
                        labels: ['Booked', 'Collected', 'Outstanding', 'Overdue'],
                        datasets: [{
                            label: 'Amount',
                            data: [
                                s.revenue.booked, s.revenue.collected,
                                s.revenue.outstanding, s.revenue.overdue
                            ],
                            backgroundColor: ['#6366f1', '#10b981', '#f59e0b', '#ef4444']
                        }]
                    },
                    options: { maintainAspectRatio: false, plugins: { legend: { display: false } } }
                });
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });

        $.getJSON('/api/v1/reports/pipeline', params()).done(function (response) {
            const reasons = response.data.conversion.loss_reasons || [];

            if (!reasons.length) {
                draw('chart-loss', {
                    type: 'doughnut',
                    data: { labels: ['No deals lost this period'], datasets: [{ data: [1], backgroundColor: ['#e5e7eb'] }] },
                    options: { maintainAspectRatio: false }
                });
                return;
            }

            draw('chart-loss', {
                type: 'doughnut',
                data: {
                    labels: reasons.map(r => r.reason || 'Unspecified'),
                    datasets: [{
                        data: reasons.map(r => r.count),
                        backgroundColor: ['#ef4444', '#f59e0b', '#6366f1', '#10b981', '#8b5cf6', '#64748b', '#0ea5e9', '#a3a3a3']
                    }]
                },
                options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
            });
        });

        $.getJSON('/api/v1/reports/sources', params()).done(function (response) {
            const sources = response.data.sources || [];

            draw('chart-sources', {
                type: 'bar',
                data: {
                    labels: sources.map(s => s.name),
                    datasets: [
                        { label: 'Leads', data: sources.map(s => s.leads), backgroundColor: '#93c5fd' },
                        // Plotted alongside, because volume without outcomes is
                        // the number that flatters a bad source.
                        { label: 'Converted', data: sources.map(s => s.converted), backgroundColor: '#10b981' }
                    ]
                },
                options: { maintainAspectRatio: false, indexAxis: 'y' }
            });
        });

        $.getJSON('/api/v1/reports/products', params()).done(function (response) {
            const products = response.data.products || [];

            $('#product-rows').html(products.length
                ? products.map(p => '<tr>'
                    + '<td>' + CRM.escape(p.name) + '</td>'
                    + '<td class="text-end small">' + p.payments + '</td>'
                    + '<td class="text-end">' + money(p.collected) + '</td>'
                    + '</tr>').join('')
                : '<tr><td colspan="3" class="text-center text-muted py-4">No payments in this period.</td></tr>');
        });
    }

    $('#f-apply').on('click', load);

    load();
});
</script>
@endpush
