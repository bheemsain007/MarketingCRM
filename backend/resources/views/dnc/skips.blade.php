{{--
    Suppressed contact attempts - the DNC skip log (BR-DNC-05, FR-DNC-03).

    BR-DNC-05 says a send refused because a lead is suppressed must be VISIBLE,
    not a silent nothing. The aggregate report already gives the totals; a
    compliance question is never "how many", it is "did you contact this person
    after they asked you not to". Only a per-attempt log answers that, so this
    screen lists the attempts and puts the reason in front.

    Deliberately wider than the two skip views that already exist: the campaign
    screen shows one campaign's recipients and the dialer shows one run's skips.
    This is the log across all of them - manual sends, campaign sends and the
    calls the dialer never placed - because "who did we not reach today" is one
    question and should not have to be asked in three places.
--}}
@extends('layouts.app')
@section('title', 'Suppressed Attempts')

@section('content')
    {{-- Two figures, deliberately not added together: one is a count of events
         in the window, the other a snapshot of the list as it stands. --}}
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card h-100 stat-card">
                <div class="card-body py-3">
                    <div class="small text-muted">Message sends refused in this window</div>
                    <div class="h3" id="sum-skips">—</div>
                    <div class="small text-muted" id="sum-skips-channels"></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100 stat-card">
                <div class="card-body py-3">
                    <div class="small text-muted">On the do-not-contact list now</div>
                    <div class="h3" id="sum-active">—</div>
                    <div class="small text-muted" id="sum-active-reasons"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-from">From</label>
                    <input type="date" id="f-from" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-to">To</label>
                    <input type="date" id="f-to" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-q">Search</label>
                    <input type="search" id="f-q" class="form-control form-control-sm"
                           placeholder="Lead name or phone">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-reason">On the list for</label>
                    <select id="f-reason" class="form-select form-select-sm">
                        <option value="">Any reason</option>
                        @foreach ($reasons as $reason)
                            <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                        @endforeach
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
                    <th>Lead</th><th>Attempt</th><th>Why we did not reach them</th>
                    <th>On the list for</th><th class="text-end">When</th>
                </tr>
                </thead>
                <tbody id="skip-rows">
                <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="skip-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0" id="skip-window"></p>
    <p class="text-muted small mb-0">
        A red reason means the do-not-contact list stopped the attempt. A grey one means we
        chose not to yet — a cooldown, a missing number, a paused campaign — and the lead may
        become contactable again on its own.
    </p>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;

    // Only from/to; the summary is a period figure and a per-lead search or a
    // reason filter would make it disagree with the label above it.
    function period() {
        const params = {};

        const from = $('#f-from').val();
        if (from) params.from = from;

        const to = $('#f-to').val();
        if (to) params.to = to;

        return params;
    }

    function load() {
        const params = period();
        params.page = page;

        const q = $('#f-q').val();
        if (q) params.q = q;

        // Blank means "any"; the endpoint rejects an unknown or empty filter
        // with a 422 rather than ignoring it.
        const reason = $('#f-reason').val();
        if (reason) params['filter[dnc_reason]'] = reason;

        $.getJSON('/api/v1/dnc/skips/log', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#skip-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">Could not load the log.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#skip-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">No suppressed attempts in this window.</td></tr>');
        } else {
            $('#skip-rows').html(items.map(function (attempt) {
                return '<tr data-href="/leads/' + attempt.lead.id + '">'
                    + '<td>' + CRM.escape(attempt.lead.name)
                    + '<div class="small text-muted">' + CRM.escape(attempt.lead.phone) + '</div></td>'
                    + '<td class="small">' + CRM.escape(attempt.source_label)
                    + '<div class="text-muted">' + CRM.escape(attempt.channel_label)
                    + (attempt.campaign_name ? ' · ' + CRM.escape(attempt.campaign_name) : '') + '</div></td>'
                    // The point of the screen, so it is a badge and not a
                    // muted cell: the reason is what a caller has to act on.
                    + '<td><span class="badge text-bg-' + (attempt.suppressed ? 'danger' : 'secondary') + '">'
                    + CRM.escape(attempt.reason_label) + '</span></td>'
                    + '<td class="small text-muted">'
                    + (attempt.dnc_reason_label ? CRM.escape(attempt.dnc_reason_label) : '—') + '</td>'
                    + '<td class="small text-end text-muted">' + attempt.occurred_at.substring(0, 10)
                    + '<div>' + attempt.occurred_at.substring(11, 16) + '</div></td>'
                    + '</tr>';
            }).join(''));
        }

        $('#skip-meta').text(
            meta.total + ' suppressed attempt' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );

        // The window is stated rather than implied - "no skips" means nothing
        // without the days it covers, and the API defaults to this month.
        $('#skip-window').text(
            'Showing attempts from ' + meta.period.from + ' to ' + meta.period.to
            + ' (' + meta.period.timezone + '). Covers message sends and dialer attempts;'
            + ' the figure above counts message sends only.'
        );

        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    function loadSummary() {
        $.getJSON('/api/v1/dnc/skips', period())
            .done(function (response) {
                const report = response.data.report;

                $('#sum-skips').text(report.skips.total);
                $('#sum-skips-channels').text(
                    report.skips.by_channel.map(function (row) {
                        return row.channel_label + ' ' + row.total;
                    }).join(' · ')
                );

                $('#sum-active').text(report.active_suppressions.total);
                $('#sum-active-reasons').text(
                    report.active_suppressions.by_reason.map(function (row) {
                        return row.reason_label + ' ' + row.total;
                    }).join(' · ')
                );
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    // Delegated: the rows are replaced on every load.
    $('#skip-rows').on('click', 'tr[data-href]', function () {
        window.location = $(this).data('href');
    });

    $('#f-apply').on('click', function () { page = 1; load(); loadSummary(); });
    $('#f-q').on('keypress', function (e) { if (e.which === 13) { page = 1; load(); } });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
    loadSummary();
});
</script>
@endpush
