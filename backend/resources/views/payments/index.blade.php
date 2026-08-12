{{--
    Payments (Phase 23, FR-PAY-01/03/04, ROLE-05).

    The Accounts role's screen. Until this existed that role held
    payments.view/manage/refund and nothing in the browser used any of them -
    somebody whose whole job is money signed in to a lead list and no way to do
    it.

    A shell like every other page: the rows come from /api/v1/payments, which is
    data-scoped, so this cannot show more than the caller may see
    (SEC-AUTHZ-03). Recording a payment happens against a SALE, so it is done
    from the deal on the lead page - a payment with no sale is not a thing the
    API allows (BR-PAY-03).
--}}
@extends('layouts.app')
@section('title', 'Payments')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-status">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending">Pending</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
                        <option value="overdue">Overdue</option>
                        <option value="failed">Failed</option>
                        <option value="refund">Refund</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-method">Method</label>
                    <select id="f-method" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="cash">Cash</option>
                        <option value="bank_transfer">Bank transfer</option>
                        <option value="upi">UPI</option>
                        <option value="card">Card</option>
                        <option value="cheque">Cheque</option>
                        <option value="gateway">Gateway</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button id="f-apply" class="btn btn-sm btn-primary">Apply</button>
                </div>
                <div class="col-md-3 small text-muted align-self-center" id="result-count"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                <tr class="small">
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Product</th>
                    <th class="text-end">Amount</th>
                    <th>Method</th>
                    <th>Due</th>
                    <th>Paid</th>
                    <th>Status</th>
                    @if ($canManage)
                        <th></th>
                    @endif
                </tr>
                </thead>
                <tbody id="rows">
                <tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <nav class="mt-3"><ul class="pagination pagination-sm mb-0" id="pager"></ul></nav>
@endsection

@push('scripts')
<script>
$(function () {
    // Drawn from the server's view of this user, not guessed at in JS. Refund
    // is its own power: it moves money back out (SEC-AUTHZ-06).
    const canManage = @json($canManage);
    const canRefund = @json($canRefund);

    let page = 1;

    function params() {
        const p = { page: page };
        if ($('#f-status').val()) p.status = $('#f-status').val();
        if ($('#f-method').val()) p.method = $('#f-method').val();
        return p;
    }

    function statusTone(status) {
        return {
            paid: 'success', partial: 'info', pending: 'secondary',
            overdue: 'danger', failed: 'dark', refund: 'warning',
        }[status] || 'secondary';
    }

    function load() {
        $.getJSON('/api/v1/payments', params())
            .done(function (response) {
                const items = response.data.items || [];
                const meta = response.data.meta || {};

                $('#result-count').text(
                    (meta.total || items.length) + ' payment' + ((meta.total || items.length) === 1 ? '' : 's')
                );

                if (!items.length) {
                    $('#rows').html('<tr><td colspan="9" class="text-center text-muted py-4">'
                        + 'No payments match.</td></tr>');
                    $('#pager').empty();
                    return;
                }

                $('#rows').html(items.map(function (p) {
                    return '<tr>'
                        + '<td class="small">' + CRM.escape(p.reference) + '</td>'
                        + '<td>' + CRM.escape(p.customer ? p.customer.name : '—') + '</td>'
                        + '<td class="small">' + CRM.escape(p.product ? p.product.name : '—') + '</td>'
                        + '<td class="text-end">' + CRM.escape(p.currency) + ' ' + CRM.escape(p.amount) + '</td>'
                        + '<td class="small">' + CRM.escape(p.method || '—') + '</td>'
                        + '<td class="small">' + CRM.escape((p.due_on || '—').substring(0, 10)) + '</td>'
                        + '<td class="small">' + CRM.escape((p.paid_at || '—').substring(0, 10)) + '</td>'
                        + '<td><span class="badge text-bg-' + statusTone(p.status) + '">'
                        + CRM.escape(p.status) + '</span></td>'
                        + (canManage
                            ? '<td class="text-end"><button class="btn btn-sm btn-outline-secondary move" '
                              + 'data-id="' + p.id + '" data-status="' + CRM.escape(p.status) + '">Change</button></td>'
                            : '')
                        + '</tr>';
                }).join(''));

                renderPager(meta);
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    }

    function renderPager(meta) {
        const last = meta.last_page || 1;
        if (last <= 1) { $('#pager').empty(); return; }

        let html = '';
        for (let i = 1; i <= last; i++) {
            html += '<li class="page-item ' + (i === page ? 'active' : '') + '">'
                + '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }
        $('#pager').html(html);
    }

    $('#pager').on('click', 'a', function (e) {
        e.preventDefault();
        page = $(this).data('page');
        load();
    });

    $('#f-apply').on('click', function () { page = 1; load(); });

    $('#rows').on('click', '.move', function () {
        /*
            The API owns the BR-PAY-02 transition matrix and will refuse an
            illegal move, so this offers the values it accepts rather than free
            text. Refund is only offered to those who hold payments.refund -
            the API refuses it otherwise, and a button that always 403s is its
            own small failure.
        */
        const allowed = canRefund
            ? 'pending, partial, paid, failed, overdue, refund'
            : 'pending, partial, paid, failed, overdue';

        const status = window.prompt('New status: ' + allowed, 'paid');
        if (!status) return;

        $.ajax({
            url: '/api/v1/payments/' + $(this).data('id'),
            method: 'PATCH',
            data: { status: status, reason: window.prompt('Reason (required for a refund):') || null },
        })
            .done(function () { CRM.alert('Payment updated.', 'success'); load(); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    load();
});
</script>
@endpush
