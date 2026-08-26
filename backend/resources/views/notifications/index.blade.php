{{--
    Your own notifications (FR-NOTIF-01, FR-NOTIF-03).

    No permission gate, deliberately - these are the caller's own records and
    every API query is bound to the authenticated user (BR-NOTIF-01). There is
    no "someone else's notifications" to gate, so a permission here would be
    theatre.

    The in-app row is the one channel with no external dependency: push and
    email may fail, this list cannot (BR-NOTIF-03). So it has to be readable
    and, where the notification points at a record, actionable - a reminder you
    cannot follow to the lead it is about is only half delivered.
--}}
@extends('layouts.app')
@section('title', 'Notifications')

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="f-unread">Show</label>
                    <select id="f-unread" class="form-select form-select-sm">
                        <option value="">All notifications</option>
                        <option value="1">Unread only</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <div class="small text-muted" id="unread-summary">&nbsp;</div>
                </div>
                <div class="col-md-2 d-grid">
                    <button id="mark-all" class="btn btn-sm btn-outline-secondary" disabled>
                        <i class="bi bi-check2-all me-1"></i>Mark all read
                    </button>
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
                    <th>Type</th><th>Notification</th>
                    <th class="text-end">Arrived</th><th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody id="notification-rows">
                <tr><td colspan="4" class="text-center text-muted py-4">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="notification-meta"></span>
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

    /*
     * A link is only offered when the viewer can actually open the target.
     * Notifications outlive permission changes - an ex-manager keeps the
     * campaign alerts they were sent - and a link that lands on a 403 is worse
     * than no link, because it reads as a broken screen rather than a closed
     * door. The route middleware is still what refuses the request.
     */
    const CAN_VIEW = {
        leads: @json(auth()->user()->hasPermission('leads.view')),
        campaigns: @json(auth()->user()->hasPermission('campaigns.view'))
    };

    /*
     * Which references become links.
     *
     * An allow-list rather than a sanitiser. `action_url` is written by server
     * code, never by a user, but it still lands in an href - and more to the
     * point, not every reference has a screen behind it. A quotation
     * notification carries '/quotations/9' and the Web CRM has no quotations
     * page, so linking it would only produce a 404, which is worse than no
     * link at all.
     */
    const LINKABLE = /^\/(leads|campaigns)\/([0-9]+)$/;

    /*
     * `lead_assigned` is written straight to the table by LeadAssignmentService
     * and carries no action_url - only the polymorphic reference. Without this
     * fallback the single commonest notification would be the one you could not
     * act on.
     */
    const REFERENCE_PATHS = {
        'App\\Models\\Lead': { section: 'leads', prefix: '/leads/' },
        'App\\Models\\Campaign': { section: 'campaigns', prefix: '/campaigns/' }
    };

    function linkFor(n) {
        const matched = n.action_url ? LINKABLE.exec(n.action_url) : null;
        if (matched) {
            return CAN_VIEW[matched[1]]
                ? { href: '/' + matched[1] + '/' + matched[2], section: matched[1] }
                : null;
        }

        const target = REFERENCE_PATHS[n.reference_type];
        if (target && n.reference_id && CAN_VIEW[target.section]) {
            // Coerced, not escaped: reference_id is an integer column and this
            // value ends up inside an href.
            return { href: target.prefix + parseInt(n.reference_id, 10), section: target.section };
        }

        return null;
    }

    function typeLabel(type) {
        return String(type || '').replace(/_/g, ' ').replace(/^./, function (c) {
            return c.toUpperCase();
        });
    }

    function typeClass(type) {
        const t = String(type || '');
        if (/fail|missed|overdue/.test(t)) return 'danger';
        if (/due|approval/.test(t)) return 'warning';
        if (/completed|assigned/.test(t)) return 'success';
        return 'secondary';
    }

    // Relative for anything recent, absolute once "6 d ago" stops being useful.
    function arrived(iso) {
        if (!iso) return '';
        const then = new Date(iso);
        const seconds = (Date.now() - then.getTime()) / 1000;

        if (seconds < 60) return 'just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
        if (seconds < 604800) return Math.floor(seconds / 86400) + ' d ago';

        return String(iso).substring(0, 10);
    }

    function load() {
        const params = { page: page };

        // The endpoint reads a bare `unread` boolean, so send it only when the
        // filter is actually on.
        if ($('#f-unread').val()) params.unread = 1;

        $.getJSON('/api/v1/notifications', params)
            .done(function (response) {
                render(response.data.items, response.data.meta);
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#notification-rows').html('<tr><td colspan="4" class="text-center text-muted py-4">Could not load notifications.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#notification-rows').html('<tr><td colspan="4" class="text-center text-muted py-4">No notifications match.</td></tr>');
        } else {
            $('#notification-rows').html(items.map(function (n) {
                const unread = !n.read_at;
                const link = linkFor(n);

                /*
                 * Read rows fade, unread rows stay at full contrast and carry a
                 * "New" badge. The badge matters as much as the weight does:
                 * colour and boldness alone are not a signal everyone can see.
                 */
                return '<tr class="' + (unread ? '' : 'text-muted') + '">'
                    + '<td><span class="badge badge-status text-bg-' + typeClass(n.type) + '">'
                    + CRM.escape(typeLabel(n.type)) + '</span></td>'
                    + '<td>'
                    + '<div class="' + (unread ? 'fw-semibold' : '') + '">'
                    + CRM.escape(n.title)
                    + (unread ? ' <span class="badge rounded-pill text-bg-primary">New</span>' : '')
                    + '</div>'
                    + (n.body ? '<div class="small text-muted">' + CRM.escape(n.body) + '</div>' : '')
                    + (link
                        ? '<a class="small text-decoration-none" href="' + link.href + '">'
                          + (link.section === 'campaigns' ? 'View campaign' : 'View lead')
                          + ' <i class="bi bi-arrow-right-short"></i></a>'
                        : '')
                    + '</td>'
                    // The exact timestamp stays reachable on hover - "3 d ago"
                    // is the right default but the wrong thing to argue with.
                    + '<td class="small text-end text-muted" title="' + CRM.escape(n.created_at) + '">'
                    + CRM.escape(arrived(n.created_at)) + '</td>'
                    + '<td class="text-end">'
                    + (unread
                        ? '<button class="btn btn-sm btn-outline-secondary mark-read" data-id="' + CRM.escape(n.id) + '">Mark read</button>'
                        : '<span class="small">Read</span>')
                    + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#notification-meta').text(
            meta.total + ' notification' + (meta.total === 1 ? '' : 's')
            + ' - page ' + meta.current_page + ' of ' + meta.last_page
        );
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);

        // The list response carries the badge count, so the summary and the
        // "mark all" button need no extra round trip.
        const unreadCount = meta.unread_count;
        $('#unread-summary').text(unreadCount ? unreadCount + ' unread' : 'Nothing unread.');
        $('#mark-all').prop('disabled', !unreadCount);
    }

    // The bell lives in the layout, so tell it the count moved rather than
    // leaving it stale until its next poll.
    function refreshBell() {
        if (window.crmRefreshBell) window.crmRefreshBell();
    }

    $('#notification-rows').on('click', '.mark-read', function () {
        const button = $(this).prop('disabled', true);

        $.ajax({ url: '/api/v1/notifications/' + button.data('id') + '/read', method: 'POST' })
            .done(function () {
                refreshBell();
                load();
            })
            .fail(function (xhr) {
                button.prop('disabled', false);
                CRM.alert(CRM.errorFrom(xhr));
            });
    });

    $('#mark-all').on('click', function () {
        const button = $(this).prop('disabled', true);

        $.ajax({ url: '/api/v1/notifications/read-all', method: 'POST' })
            .done(function () {
                CRM.alert('All notifications marked read.', 'success');
                refreshBell();
                load();
            })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
            })
            .always(function () {
                button.prop('disabled', false);
            });
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#f-unread').on('change', function () { page = 1; load(); });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
