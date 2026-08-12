{{--
    Campaign list (Phase 18) - FR-CAMP-01/02/04.

    A shell over /api/v1/campaigns (ADR-A). The lifecycle rules are the
    server's: this screen renders the `can_start` / `can_pause` / `can_stop`
    flags the API returns rather than deciding from the status string, so the
    transition matrix (BR-CAMP-05) has exactly one implementation.
--}}
@extends('layouts.app')
@section('title', 'Campaigns')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h5 mb-0">Campaigns</h1>
        @permission('campaigns.manage')
            <a href="{{ route('web.campaigns.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New campaign
            </a>
        @endpermission
    </div>

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="f-status">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="f-channel">Channel</label>
                    <select id="f-channel" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
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
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>Name</th><th>Channel</th><th>Status</th>
                    <th class="text-end">Targeted</th><th class="text-end">Sent</th>
                    <th class="text-end">Skipped</th><th>Started</th>
                </tr>
                </thead>
                <tbody id="campaign-rows">
                <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="campaign-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Skipped is not failure. A lead is skipped when the DNC gate refuses the channel, when there is
        no usable address, or when a frequency cap is reached — every one carries its reason (BR-DNC-05).
    </p>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;

    const tone = {
        draft: 'secondary', scheduled: 'info', running: 'primary',
        paused: 'warning', completed: 'success', stopped: 'dark',
    };

    function load() {
        const params = { page: page };

        const status = $('#f-status').val();
        if (status) params['filter[status]'] = status;

        const channel = $('#f-channel').val();
        if (channel) params['filter[channel]'] = channel;

        $.getJSON('/api/v1/campaigns', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#campaign-rows').html('<tr><td colspan="7" class="text-center text-muted py-4">Could not load campaigns.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#campaign-rows').html('<tr><td colspan="7" class="text-center text-muted py-4">No campaigns yet.</td></tr>');
            $('#campaign-meta').text('');
            return;
        }

        const rows = items.map(function (c) {
            return '<tr>'
                + '<td><a href="/campaigns/' + c.id + '">' + CRM.escape(c.name) + '</a></td>'
                + '<td class="small text-muted">' + CRM.escape(c.channel_label) + '</td>'
                + '<td><span class="badge text-bg-' + (tone[c.status] || 'secondary') + '">'
                    + CRM.escape(c.status_label) + '</span></td>'
                + '<td class="text-end">' + c.counts.targeted.toLocaleString() + '</td>'
                + '<td class="text-end">' + c.counts.sent.toLocaleString() + '</td>'
                + '<td class="text-end ' + (c.counts.skipped > 0 ? 'text-warning' : 'text-muted') + '">'
                    + c.counts.skipped.toLocaleString() + '</td>'
                + '<td class="small text-muted">' + (c.started_at ? c.started_at.substring(0, 10) : '—') + '</td>'
                + '</tr>';
        });

        $('#campaign-rows').html(rows.join(''));
        $('#campaign-meta').text('Page ' + meta.current_page + ' of ' + meta.last_page + ' — ' + meta.total + ' total');
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
