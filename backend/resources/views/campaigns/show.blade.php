{{--
    Campaign detail (Phase 18) - FR-CAMP-02/04.

    The screen a campaign is actually judged on. The counts answer "did it go
    out?"; the skip breakdown answers "why did it not reach these people?",
    which is the question that leads to an action.

    The lifecycle buttons follow the server's `can_*` flags. Re-deriving them
    from the status string here would be a second copy of BR-CAMP-05 that
    nothing keeps in step.
--}}
@extends('layouts.app')
@section('title', 'Campaign')

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h5 mb-1" id="c-name">…</h1>
            <div class="small text-muted">
                <span id="c-channel"></span> ·
                <span class="badge" id="c-status"></span>
            </div>
        </div>

        @permission('campaigns.run')
            <div class="btn-group btn-group-sm" id="controls"></div>
        @endpermission
    </div>

    <div class="row g-3 mb-4" id="counts"></div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6">Why leads were skipped</h2>
                    <div id="skip-breakdown">
                        <p class="text-muted small mb-0">Loading…</p>
                    </div>
                    <p class="text-muted small mt-3 mb-0">
                        A skip is a recorded outcome, not a failure to send. Some reasons lift by
                        themselves — a frequency cap clears tomorrow; a missing address does not.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card h-100">
                <div class="card-body pb-0">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="h6 mb-0">Recipients</h2>
                        <select id="f-skip" class="form-select form-select-sm w-auto">
                            <option value="">All recipients</option>
                            <option value="sent">Sent only</option>
                            @foreach ($skipReasons as $reason)
                                <option value="skip:{{ $reason->value }}">{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr class="small">
                            <th>Lead</th><th>Outcome</th><th>Reason</th><th>Processed</th>
                        </tr>
                        </thead>
                        <tbody id="recipient-rows">
                        <tr><td colspan="4" class="text-center text-muted py-4">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="small text-muted" id="recipient-meta"></span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                        <button class="btn btn-outline-secondary" id="page-next">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
$(function () {
    const id = @json($campaign->id);
    let page = 1;

    const tone = {
        draft: 'secondary', scheduled: 'info', running: 'primary',
        paused: 'warning', completed: 'success', stopped: 'dark',
    };

    function loadCampaign() {
        $.getJSON('/api/v1/campaigns/' + id)
            .done(function (response) { renderCampaign(response.data); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    function renderCampaign(c) {
        $('#c-name').text(c.name);
        $('#c-channel').text(c.channel_label);
        $('#c-status').text(c.status_label).attr('class', 'badge text-bg-' + (tone[c.status] || 'secondary'));

        const tiles = [
            ['Targeted', c.counts.targeted, 'dark'],
            ['Sent', c.counts.sent, 'primary'],
            ['Delivered', c.counts.delivered, 'success'],
            ['Skipped', c.counts.skipped, c.counts.skipped > 0 ? 'warning' : 'secondary'],
            ['Failed', c.counts.failed, c.counts.failed > 0 ? 'danger' : 'secondary'],
        ];

        $('#counts').html(tiles.map(function (t) {
            return '<div class="col-6 col-md">'
                + '<div class="card stat-card h-100"><div class="card-body py-3">'
                + '<div class="text-muted small">' + t[0] + '</div>'
                + '<div class="h3 text-' + t[2] + ' mb-0">' + t[1].toLocaleString() + '</div>'
                + '</div></div></div>';
        }).join(''));

        // Straight from the server's flags, so the button that is offered is
        // always one the API will actually accept.
        const buttons = [];
        if (c.can_start) buttons.push(['start', 'Start', 'primary']);
        if (c.can_pause) buttons.push(['pause', 'Pause', 'outline-secondary']);
        if (c.can_stop) buttons.push(['stop', 'Stop', 'outline-danger']);

        $('#controls').html(buttons.map(function (b) {
            return '<button class="btn btn-' + b[2] + '" data-action="' + b[0] + '">' + b[1] + '</button>';
        }).join('') + (c.is_final
            ? '<button class="btn btn-outline-secondary" data-action="clone">Clone</button>'
            : ''));
    }

    function loadSkipBreakdown() {
        // One request per reason would be four requests; asking for a large
        // page and counting client-side would be wrong the moment a campaign
        // outgrows one page. The API groups nothing, so this asks per reason
        // and reads only the total from each.
        const reasons = @json(collect($skipReasons)->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])->values());
        const results = [];

        const requests = reasons.map(function (reason) {
            return $.getJSON('/api/v1/campaigns/' + id + '/recipients', {
                'filter[skip_reason]': reason.value, per_page: 1,
            }).done(function (response) {
                results.push({ label: reason.label, count: response.data.meta.total });
            });
        });

        $.when.apply($, requests).always(function () {
            const withCounts = results.filter(function (r) { return r.count > 0; });

            if (!withCounts.length) {
                $('#skip-breakdown').html('<p class="text-muted small mb-0">Nobody was skipped.</p>');
                return;
            }

            $('#skip-breakdown').html(withCounts.map(function (r) {
                return '<div class="d-flex justify-content-between border-bottom py-2">'
                    + '<span class="small">' + CRM.escape(r.label) + '</span>'
                    + '<strong>' + r.count.toLocaleString() + '</strong></div>';
            }).join(''));
        });
    }

    function loadRecipients() {
        const params = { page: page, include: 'lead' };
        const filter = $('#f-skip').val();

        if (filter === 'sent') {
            params['filter[status]'] = 'sent';
        } else if (filter.startsWith('skip:')) {
            params['filter[skip_reason]'] = filter.substring(5);
        }

        $.getJSON('/api/v1/campaigns/' + id + '/recipients', params)
            .done(function (response) { renderRecipients(response.data.items, response.data.meta); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    function renderRecipients(items, meta) {
        if (!items.length) {
            $('#recipient-rows').html('<tr><td colspan="4" class="text-center text-muted py-4">Nothing to show.</td></tr>');
            $('#recipient-meta').text('');
            return;
        }

        $('#recipient-rows').html(items.map(function (r) {
            const name = r.lead ? r.lead.name : ('Lead #' + r.lead_id);
            return '<tr>'
                + '<td><a href="/leads/' + r.lead_id + '">' + CRM.escape(name) + '</a></td>'
                + '<td><span class="badge text-bg-' + (r.status === 'skipped' ? 'warning' : 'success') + '">'
                    + CRM.escape(r.status) + '</span></td>'
                + '<td class="small text-muted">' + CRM.escape(r.skip_reason_label || '—') + '</td>'
                + '<td class="small text-muted">'
                    + (r.processed_at ? r.processed_at.substring(0, 16).replace('T', ' ') : '—') + '</td>'
                + '</tr>';
        }).join(''));

        $('#recipient-meta').text('Page ' + meta.current_page + ' of ' + meta.last_page + ' — ' + meta.total + ' total');
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    $('#controls').on('click', 'button', function () {
        const action = $(this).data('action');

        // Stopping is irreversible and the API will not undo it, so the
        // warning belongs here rather than in a toast afterwards.
        if (action === 'stop' && !confirm('Stopping is final. A stopped campaign can only be cloned, never resumed. Continue?')) {
            return;
        }

        $.ajax({ url: '/api/v1/campaigns/' + id + '/' + action, method: 'POST' })
            .done(function (response) {
                CRM.alert(response.message, 'success');
                if (action === 'clone') {
                    window.location = '/campaigns/' + response.data.id;
                    return;
                }
                loadCampaign();
                loadRecipients();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#f-skip').on('change', function () { page = 1; loadRecipients(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; loadRecipients(); } });
    $('#page-next').on('click', function () { page++; loadRecipients(); });

    loadCampaign();
    loadSkipBreakdown();
    loadRecipients();
});
</script>
@endpush
