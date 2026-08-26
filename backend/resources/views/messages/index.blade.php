{{--
    Sent-message history across every lead the caller may see (FR-COMM-05).

    The lead page already shows one lead's thread. The question this screen
    exists for is the other one - "what went out this week, and how much of it
    never arrived" - which a per-lead view cannot answer no matter how many
    leads you open. Failures and DNC skips are the point, so the delivery state
    is a badge and the reason sits under it rather than being hidden behind a
    row click.

    Rows come from GET /api/v1/messages, which scopes through the lead: a
    telecaller sees their own book and nobody else's (SEC-AUTHZ-03). Nothing is
    scoped here - the shell renders reference data only (ADR-A).
--}}
@extends('layouts.app')
@section('title', 'Messages')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-channel">Channel</label>
                    <select id="f-channel" class="form-select form-select-sm">
                        <option value="">Any channel</option>
                        {{-- Call and AI Call are here because a Message row can
                             carry them: campaign voice sends are recorded as
                             messages. Filtering to a channel that has no rows is
                             an empty list, not an error. --}}
                        @foreach ($channels as $channel)
                            <option value="{{ $channel->value }}">{{ $channel->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-status">Delivery state</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">Any state</option>
                        {{-- Hard-coded rather than enum-driven: `messages.status`
                             is a plain string column and the states below are the
                             whole set the app writes (OutboundMessageService,
                             SendMessage, DeliveryStatusService). --}}
                        <option value="queued">Queued</option>
                        <option value="sent">Sent</option>
                        <option value="delivered">Delivered</option>
                        <option value="read">Read</option>
                        <option value="replied">Replied</option>
                        <option value="failed">Failed</option>
                        <option value="skipped">Skipped (suppressed)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-direction">Direction</label>
                    <select id="f-direction" class="form-select form-select-sm">
                        <option value="">Both</option>
                        <option value="outbound">Outbound</option>
                        <option value="inbound">Inbound</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="f-sort">Sort</label>
                    <select id="f-sort" class="form-select form-select-sm">
                        <option value="-created_at">Newest first</option>
                        <option value="created_at">Oldest first</option>
                        <option value="-sent_at">Most recently sent</option>
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
                    <th>When</th><th>Lead</th><th>Channel</th>
                    <th>Delivery</th><th>Message</th>
                </tr>
                </thead>
                <tbody id="message-rows">
                <tr><td colspan="5" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="message-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        “Skipped” means the do-not-contact list stopped the send before it left the building —
        the row is the audit trail, not a failure to retry (BR-DNC-05).
    </p>
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;

    /*
     * `status` is a bare string on the model, so the label and the colour are
     * drawn here rather than coming back as `status_label` the way an enum
     * field would. Both fall back to the raw value: a state added server-side
     * must render as itself, never as blank.
     *
     * The colour is progress and not decoration - the states run queued → sent
     * → delivered → read → replied, so grey is still in flight, green actually
     * landed, red needs somebody.
     */
    const DELIVERY = {
        queued: ['Queued', 'secondary'],
        sent: ['Sent', 'info'],
        delivered: ['Delivered', 'success'],
        read: ['Read', 'success'],
        replied: ['Replied', 'success'],
        failed: ['Failed', 'danger'],
        skipped: ['Skipped', 'warning']
    };

    function deliveryLabel(status) {
        return (DELIVERY[status] || [status])[0];
    }

    function deliveryClass(status) {
        return (DELIVERY[status] || [])[1] || 'secondary';
    }

    function truncate(value, length) {
        const text = (value === null || value === undefined) ? '' : String(value);
        return text.length > length ? text.slice(0, length) + '…' : text;
    }

    function stamp(value) {
        return value ? value.substring(0, 16).replace('T', ' ') : '—';
    }

    function load() {
        const params = { page: page, sort: $('#f-sort').val() };

        // A blank select means "any". The endpoint rejects an unknown OR empty
        // filter field with a 422 rather than ignoring it, so an unset filter
        // must not be sent at all.
        const channel = $('#f-channel').val();
        if (channel) params['filter[channel]'] = channel;

        const status = $('#f-status').val();
        if (status) params['filter[status]'] = status;

        const direction = $('#f-direction').val();
        if (direction) params['filter[direction]'] = direction;

        $.getJSON('/api/v1/messages', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#message-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">Could not load messages.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#message-rows').html('<tr><td colspan="5" class="text-center text-muted py-4">No messages match.</td></tr>');
        } else {
            $('#message-rows').html(items.map(function (message) {
                // Why it did not arrive, in the row: chasing a failed send
                // should not need a second screen.
                const reason = message.skip_reason || message.failure_reason;

                return '<tr' + (message.lead ? ' data-href="/leads/' + message.lead.id + '"' : '') + '>'
                    + '<td class="small text-muted">' + stamp(message.sent_at || message.created_at) + '</td>'
                    + '<td>' + CRM.escape(message.lead ? message.lead.name : 'Lead #' + message.lead_id)
                    + '<div class="small text-muted">' + CRM.escape(message.recipient) + '</div></td>'
                    + '<td class="small">' + CRM.escape(message.channel_label)
                    + (message.direction === 'inbound' ? '<div class="text-muted">Inbound</div>' : '')
                    + '</td>'
                    + '<td><span class="badge text-bg-' + deliveryClass(message.status) + '">'
                    + CRM.escape(deliveryLabel(message.status)) + '</span>'
                    + (reason ? '<div class="small text-muted">' + CRM.escape(truncate(reason, 40)) + '</div>' : '')
                    + '</td>'
                    + '<td class="small">'
                    + (message.subject ? '<div class="fw-semibold">' + CRM.escape(truncate(message.subject, 60)) + '</div>' : '')
                    + '<span class="text-muted">' + CRM.escape(truncate(message.body, 90)) + '</span>'
                    + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#message-meta').text(
            meta.total + ' message' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    // Delegated: the rows are replaced on every load.
    $('#message-rows').on('click', 'tr[data-href]', function () {
        window.location = $(this).data('href');
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
