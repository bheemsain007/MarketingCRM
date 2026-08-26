{{--
    Web CRM shell (ADR-A, Phase 8).

    Bootstrap and jQuery are loaded from a CDN rather than built with Vite.
    The production target is Hostinger shared hosting with no Node on the
    server (DEPLOYMENT §3A), so a deploy is an upload plus composer - adding a
    build step to that is friction with nothing to show for it. Vite is still
    wired up if these ever need self-hosting; the trade-off is the CSP
    looseness already tracked as T-39.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Every AJAX write reads this. Without it, POST/PATCH/DELETE are 419s. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CRM') · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --crm-sidebar: 232px; }
        body { background: #f4f6f9; }
        .crm-sidebar {
            width: var(--crm-sidebar); position: fixed; top: 0; bottom: 0; left: 0;
            background: #1f2937; color: #cbd5e1; overflow-y: auto;
        }
        .crm-sidebar a {
            color: #cbd5e1; text-decoration: none; display: block;
            padding: .55rem 1rem; border-left: 3px solid transparent; font-size: .93rem;
        }
        .crm-sidebar a:hover { background: #374151; color: #fff; }
        .crm-sidebar a.active { background: #111827; color: #fff; border-left-color: #3b82f6; }
        .crm-main { margin-left: var(--crm-sidebar); }
        @media (max-width: 768px) {
            .crm-sidebar { position: static; width: 100%; height: auto; }
            .crm-main { margin-left: 0; }
        }
        .stat-card .h3 { margin: 0; font-weight: 600; }
        .table-hover tbody tr { cursor: pointer; }
        .badge-status { font-weight: 500; }
    </style>
    @stack('styles')
</head>
<body>
<nav class="crm-sidebar py-3">
    <div class="px-3 pb-3 mb-2 border-bottom border-secondary">
        <div class="text-white fw-semibold">{{ config('app.name') }}</div>
        <small class="text-secondary">{{ auth()->user()?->roles->pluck('name')->join(', ') }}</small>
    </div>

    {{--
        Nav entries are hidden when the user cannot use them. Convenience only:
        the route middleware is what refuses the request (SEC-AUTHZ-02).
    --}}
    <a href="{{ route('web.dashboard') }}" class="{{ request()->routeIs('web.dashboard') ? 'active' : '' }}">
        <i class="bi bi-speedometer2 me-2"></i>Dashboard
    </a>

    @permission('leads.view')
    <a href="{{ route('web.leads') }}" class="{{ request()->routeIs('web.leads*') ? 'active' : '' }}">
        <i class="bi bi-people me-2"></i>Leads
    </a>
    @endpermission

    @permission('leads.assign')
    <a href="{{ route('web.assignments') }}" class="{{ request()->routeIs('web.assignments') ? 'active' : '' }}">
        <i class="bi bi-person-check me-2"></i>Assignments
    </a>
    @endpermission

    @permission('dialer.use')
    <a href="{{ route('web.dialer') }}" class="{{ request()->routeIs('web.dialer') ? 'active' : '' }}">
        <i class="bi bi-telephone-outbound me-2"></i>Dialer
    </a>
    @endpermission

    @permission('follow_ups.view')
    <a href="{{ route('web.follow-ups') }}" class="{{ request()->routeIs('web.follow-ups') ? 'active' : '' }}">
        <i class="bi bi-calendar-check me-2"></i>Follow-ups
    </a>
    @endpermission

    @permission('calls.view')
    <a href="{{ route('web.calls') }}" class="{{ request()->routeIs('web.calls') ? 'active' : '' }}">
        <i class="bi bi-telephone me-2"></i>Call History
    </a>
    @endpermission

    @permission('leads.view')
    <a href="{{ route('web.messages') }}" class="{{ request()->routeIs('web.messages') ? 'active' : '' }}">
        <i class="bi bi-chat-dots me-2"></i>Messages
    </a>
    @endpermission

    @permission('leads.import')
    <a href="{{ route('web.imports') }}" class="{{ request()->routeIs('web.imports') ? 'active' : '' }}">
        <i class="bi bi-upload me-2"></i>Imports
    </a>
    @endpermission

    @permission('leads.archive')
    <a href="{{ route('web.leads.duplicates') }}" class="{{ request()->routeIs('web.leads.duplicates') ? 'active' : '' }}">
        <i class="bi bi-people me-2"></i>Duplicates
    </a>
    @endpermission

    @permission('campaigns.view')
    <a href="{{ route('web.campaigns') }}" class="{{ request()->routeIs('web.campaigns*') ? 'active' : '' }}">
        <i class="bi bi-megaphone me-2"></i>Campaigns
    </a>
    @endpermission

    @permission('payments.view')
    <a href="{{ route('web.payments') }}" class="{{ request()->routeIs('web.payments') ? 'active' : '' }}">
        <i class="bi bi-cash-coin me-2"></i>Payments
    </a>
    @endpermission

    @permission('reports.business')
    <a href="{{ route('web.reports') }}" class="{{ request()->routeIs('web.reports') ? 'active' : '' }}">
        <i class="bi bi-graph-up me-2"></i>Reports
    </a>
    @endpermission

    @permission('reports.telecaller')
    <a href="{{ route('web.reports.telecallers') }}" class="{{ request()->routeIs('web.reports.telecallers') ? 'active' : '' }}">
        <i class="bi bi-people me-2"></i>Telecaller Reports
    </a>
    @endpermission

    @permission('users.view')
    <a href="{{ route('web.users') }}" class="{{ request()->routeIs('web.users') ? 'active' : '' }}">
        <i class="bi bi-person-gear me-2"></i>Users
    </a>
    @endpermission

    @permission('settings.manage')
    <a href="{{ route('web.settings') }}" class="{{ request()->routeIs('web.settings') ? 'active' : '' }}">
        <i class="bi bi-sliders me-2"></i>Settings
    </a>
    @endpermission

    @permission('templates.view')
    <a href="{{ route('web.templates') }}" class="{{ request()->routeIs('web.templates') ? 'active' : '' }}">
        <i class="bi bi-file-earmark-text me-2"></i>Templates
    </a>
    @endpermission

    @permission('dnc.view')
    <a href="{{ route('web.dnc') }}" class="{{ request()->routeIs('web.dnc') ? 'active' : '' }}">
        <i class="bi bi-slash-circle me-2"></i>Do Not Contact
    </a>
    @endpermission

    @permission('dnc.view')
    <a href="{{ route('web.dnc.skips') }}" class="{{ request()->routeIs('web.dnc.skips') ? 'active' : '' }}">
        <i class="bi bi-shield-slash me-2"></i>Suppressed Attempts
    </a>
    @endpermission

    @permission('products.view')
    <a href="{{ route('web.products') }}" class="{{ request()->routeIs('web.products') ? 'active' : '' }}">
        <i class="bi bi-box-seam me-2"></i>Products
    </a>
    @endpermission

    @permission('settings.manage')
    <a href="{{ route('web.tags') }}" class="{{ request()->routeIs('web.tags') ? 'active' : '' }}">
        <i class="bi bi-tags me-2"></i>Tags
    </a>
    @endpermission
</nav>

<div class="crm-main">
    <header class="bg-white border-bottom">
        <div class="d-flex align-items-center justify-content-between px-4 py-2">
            <h1 class="h5 mb-0">@yield('title', 'CRM')</h1>
            <div class="d-flex align-items-center gap-3">
                {{--
                    The bell is the ONLY entry point to the notifications screen
                    - there is deliberately no sidebar entry, because a list of
                    your own alerts is not a section of the CRM, it is a state
                    of it (FR-NOTIF-01). Which is why it lives in the shell.
                --}}
                <a href="{{ route('web.notifications') }}" id="crm-bell"
                   class="position-relative text-muted text-decoration-none lh-1"
                   aria-label="Notifications" title="Notifications">
                    <i class="bi bi-bell fs-5"></i>
                    {{--
                        Starts hidden and stays hidden at zero. The count only
                        arrives by AJAX, so rendering it now would flash a "0"
                        that is not yet an answer - and "0 unread" is a badge
                        asking for attention it does not need.
                    --}}
                    <span id="crm-bell-count"
                          class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger d-none"></span>
                </a>
                <a href="{{ route('web.account') }}" class="text-muted small text-decoration-none">
                    {{ auth()->user()?->name }}
                </a>
                <form method="POST" action="{{ route('web.logout') }}" class="m-0">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Sign out</button>
                </form>
            </div>
        </div>
    </header>

    <main class="p-4">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <div id="crm-alert" class="alert d-none" role="alert"></div>

        @yield('content')
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/*
 * Shared AJAX plumbing for every page.
 *
 * The API envelope is fixed ({success, message, data, errors}), so unwrapping
 * and error reporting belong here once rather than in each page - a page that
 * reads `response.data` directly will break the first time the envelope
 * matters.
 */
$.ajaxSetup({
    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
    // Session cookie, not a bearer token: same-origin requests are stateful.
    xhrFields: { withCredentials: true }
});

const CRM = {
    /** Reads the first useful message out of the standard error envelope. */
    errorFrom(xhr) {
        const body = xhr.responseJSON;
        if (!body) return 'Something went wrong. Please try again.';
        if (body.errors && body.errors.length && body.errors[0].message) {
            return body.errors[0].message;
        }
        return body.message || 'Something went wrong.';
    },

    alert(message, type = 'danger') {
        $('#crm-alert')
            .removeClass('d-none alert-danger alert-success alert-warning')
            .addClass('alert-' + type)
            .text(message);
        if (type === 'success') {
            setTimeout(() => $('#crm-alert').addClass('d-none'), 4000);
        }
    },

    /** Bootstrap colour per lead status, so a list is scannable at a glance. */
    statusClass(status) {
        return ({
            new: 'secondary', contacted: 'info', interested: 'primary',
            follow_up: 'warning', callback: 'warning', proposal: 'primary',
            negotiation: 'primary', decision_pending: 'warning',
            converted: 'success', lost: 'dark', not_interested: 'danger'
        })[status] || 'secondary';
    },

    escape(value) {
        return $('<div>').text(value === null || value === undefined ? '' : value).html();
    }
};

// 401 anywhere means the session went away - send them back to sign in rather
// than leaving a dead page that silently fails every action.
$(document).ajaxError(function (event, xhr) {
    if (xhr.status === 401) window.location = '{{ route('web.login') }}';
});
</script>
<script>
/*
 * The notification bell (FR-NOTIF-01).
 *
 * Here rather than on a page because the point of it is to reach someone who
 * is NOT looking at the notifications screen - a lead assigned to you while
 * you are three levels into the dialer is exactly the case the in-app channel
 * exists to cover (BR-NOTIF-03).
 */
$(function () {
    const bell = $('#crm-bell');
    const badge = $('#crm-bell-count');

    function paint(count) {
        if (!count) {
            badge.addClass('d-none').text('');
            bell.attr('aria-label', 'Notifications');

            return;
        }

        // Capped: past a hundred the exact number stops changing the decision,
        // and a four-digit badge stops fitting on the icon.
        badge.removeClass('d-none').text(count > 99 ? '99+' : count);
        bell.attr('aria-label', 'Notifications (' + count + ' unread)');
    }

    function refresh() {
        // Silent on failure, deliberately. A dropped count is not something
        // the user can act on, and a toast for every flaky poll would teach
        // them to ignore the alert bar that real errors use. Session loss is
        // already handled by the global 401 handler above.
        $.getJSON('/api/v1/notifications/unread-count').done(function (response) {
            paint(response.data.unread_count);
        });
    }

    /*
     * 60s. The query is an indexed COUNT on one user's rows, so a floor full
     * of telecallers costs one cheap query each per minute - and a minute is
     * short enough that work assigned to you surfaces while you are still at
     * your desk, which is the whole point of a badge over an email.
     */
    setInterval(function () {
        if (!document.hidden) refresh();
    }, 60000);

    // A backgrounded tab skips its polls, so catch it up the instant it comes
    // forward rather than showing a stale count for up to a minute.
    $(document).on('visibilitychange', function () {
        if (!document.hidden) refresh();
    });

    // The notifications screen moves the count itself; let it repaint the bell
    // instead of leaving the badge wrong until the next poll.
    window.crmRefreshBell = refresh;

    refresh();
});
</script>
@stack('scripts')
</body>
</html>
