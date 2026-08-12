{{--
    Telecaller performance dashboard (Phase 26, FR-RPT-01/04/06).

    A shell: every figure comes from /api/v1/reports/telecallers, so there is one
    implementation of each formula and this page cannot disagree with the API
    (ADR-A). It mirrors the business report page (reports/index.blade.php) - same
    rate() helper, same Chart.js-from-CDN trade-off (T-39).

    Two things this page exists to say out loud:
      - Every rate is drawn with its denominator (FR-RPT-06). `rate()` below is
        the only place a rate is formatted.
      - The attribution model is named in a banner, because on reassignment it
        decides who gets conversion and revenue credit, and that affects pay
        (T-24). The API returns which model it used; the screen never guesses.
--}}
@extends('layouts.app')
@section('title', 'Telecaller Reports')

@push('styles')
<style>
    .tile { border-left: 3px solid #e5e7eb; }
    .tile .figure { font-size: 1.5rem; font-weight: 600; line-height: 1.1; }
    .tile .denom { font-size: .75rem; color: #6b7280; }
    .chart-box { position: relative; height: 300px; }
    #board td .denom { font-size: .7rem; color: #6b7280; }
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

    {{-- Attribution is named, not assumed: it decides who gets conversion and
         revenue credit on reassignment, and that is a pay question (T-24). --}}
    <div class="alert alert-secondary py-2 px-3 small mb-3" id="attribution-banner" role="status"></div>

    <div class="row g-3 mb-3" id="tiles"></div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2"><span class="small fw-semibold">Conversions by telecaller</span></div>
                <div class="card-body"><div class="chart-box"><canvas id="chart-conversions"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2"><span class="small fw-semibold">Connected calls &amp; talk time</span></div>
                <div class="card-body"><div class="chart-box"><canvas id="chart-calls"></canvas></div></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2"><span class="small fw-semibold">Leaderboard</span></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="board">
                <thead class="table-light">
                <tr class="small">
                    <th>Telecaller</th>
                    <th class="text-end">Attempts</th>
                    <th class="text-end">Connected</th>
                    <th class="text-end">Connect rate</th>
                    <th class="text-end">Leads touched</th>
                    <th class="text-end">Talk time</th>
                    <th class="text-end">Occupancy</th>
                    <th class="text-end">Follow-ups</th>
                    <th class="text-end">Converted</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end">Conversion</th>
                </tr>
                </thead>
                <tbody id="board-rows">
                <tr><td colspan="11" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
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

    // Seconds are stored, but nobody reads pay reports in seconds.
    function duration(seconds) {
        const s = Number(seconds || 0);
        if (s < 60) return s + 's';
        const h = Math.floor(s / 3600);
        const m = Math.round((s % 3600) / 60);
        return h > 0 ? h + 'h ' + m + 'm' : m + 'm';
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

    // "last_owner" -> "Last owner". The API is the source of truth for which
    // model produced these numbers; this only makes the token readable.
    function attributionLabel(model) {
        return String(model || '')
            .replace(/_/g, ' ')
            .replace(/^\w/, c => c.toUpperCase());
    }

    function load() {
        $.getJSON('/api/v1/reports/telecallers', params())
            .done(function (response) {
                const rows = response.data.rows || [];
                const period = response.data.period;
                const attribution = response.data.attribution;

                // Stated so two people comparing dashboards can see they used
                // the same window and the same timezone.
                $('#period-label').text(period.from + ' to ' + period.to + ' (' + period.timezone + ')');

                $('#attribution-banner').html(
                    '<i class="bi bi-info-circle me-1"></i>'
                    + 'Conversion and revenue are credited using the <strong>'
                    + CRM.escape(attributionLabel(attribution))
                    + '</strong> attribution model. On reassignment this decides who is credited.'
                );

                // Team totals, from the same rows the table draws - so the tiles
                // and the leaderboard can never disagree.
                const totals = rows.reduce(function (acc, r) {
                    acc.attempts   += r.calls.attempts;
                    acc.connected  += r.calls.connected;
                    acc.talk       += r.calls.talk_time_seconds;
                    acc.converted  += r.outcomes.converted;
                    acc.revenue    += Number(r.outcomes.revenue || 0);
                    return acc;
                }, { attempts: 0, connected: 0, talk: 0, converted: 0, revenue: 0 });

                $('#tiles').html([
                    tile('Telecallers', rows.length, 'in this report'),
                    tile('Call attempts', totals.attempts, totals.connected + ' connected'),
                    tile('Talk time', duration(totals.talk), 'across the team'),
                    tile('Converted', totals.converted, money(totals.revenue) + ' credited'),
                ].join(''));

                renderBoard(rows);
                renderCharts(rows);
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    function renderBoard(rows) {
        if (!rows.length) {
            $('#board-rows').html('<tr><td colspan="11" class="text-center text-muted py-4">'
                + 'No telecallers in this period.</td></tr>');
            return;
        }

        $('#board-rows').html(rows.map(function (r) {
            return '<tr>'
                + '<td>' + CRM.escape(r.user.name) + '</td>'
                + '<td class="text-end">' + r.calls.attempts + '</td>'
                + '<td class="text-end">' + r.calls.connected + '</td>'
                + '<td class="text-end small">' + rate(r.calls.connect_rate) + '</td>'
                + '<td class="text-end">' + r.calls.unique_leads_touched + '</td>'
                + '<td class="text-end">' + duration(r.calls.talk_time_seconds)
                    + '<div class="denom">' + (r.calls.average_call_seconds === null
                        ? 'no connected calls'
                        : 'avg ' + r.calls.average_call_seconds + 's') + '</div></td>'
                + '<td class="text-end small">' + rate(r.time.occupancy) + '</td>'
                + '<td class="text-end">' + r.follow_ups.completed + ' / ' + r.follow_ups.due
                    + '<div class="denom">' + r.follow_ups.missed + ' missed</div></td>'
                + '<td class="text-end">' + r.outcomes.converted + '</td>'
                + '<td class="text-end">' + money(r.outcomes.revenue) + '</td>'
                + '<td class="text-end small">' + rate(r.outcomes.conversion_rate) + '</td>'
                + '</tr>';
        }).join(''));
    }

    function renderCharts(rows) {
        const labels = rows.map(r => r.user.name);

        draw('chart-conversions', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Converted',
                    data: rows.map(r => r.outcomes.converted),
                    backgroundColor: '#10b981'
                }]
            },
            options: { maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } }
        });

        draw('chart-calls', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Connected calls', data: rows.map(r => r.calls.connected), backgroundColor: '#3b82f6', yAxisID: 'y' },
                    // Talk time in minutes on a second axis: a high call count
                    // with near-zero talk time is the number worth seeing.
                    { label: 'Talk time (min)', data: rows.map(r => Math.round(r.calls.talk_time_seconds / 60)), backgroundColor: '#f59e0b', yAxisID: 'y1' }
                ]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    y:  { position: 'left',  beginAtZero: true, title: { display: true, text: 'Calls' } },
                    y1: { position: 'right', beginAtZero: true, title: { display: true, text: 'Minutes' }, grid: { drawOnChartArea: false } }
                }
            }
        });
    }

    $('#f-apply').on('click', load);

    load();
});
</script>
@endpush
