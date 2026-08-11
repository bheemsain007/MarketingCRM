{{--
    User administration (T-51, closes T-46) - ROLE-01..07, SEC-AUTHZ-05.

    Two permissions, and the screen shows the difference: `users.manage` draws
    the create form and the enable/disable buttons; `roles.manage` draws the
    role control. An Admin sees this page fully usable except for the one thing
    that would let them promote somebody, which is exactly the split the
    security requirement asks for.

    Hiding a control is never the access control (ROLE-07) - the routes refuse
    the request either way.
--}}
@extends('layouts.app')
@section('title', 'Users')

@section('content')
    @if ($canManage)
        <div class="card mb-3">
            <div class="card-header py-2"><span class="small fw-semibold">Add a user</span></div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="f-name">Name</label>
                        <input type="text" id="f-name" class="form-control form-control-sm" maxlength="150">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="f-email">Email</label>
                        <input type="email" id="f-email" class="form-control form-control-sm">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="f-password">Temporary password</label>
                        <input type="password" id="f-password" class="form-control form-control-sm"
                               autocomplete="new-password">
                        <div class="form-text small">At least 8 characters, letters and numbers.</div>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-md-3 d-grid">
                        <button id="create-user" class="btn btn-sm btn-primary">Create</button>
                    </div>
                </div>

                @unless ($canManageRoles)
                    {{-- Said plainly rather than leaving somebody to wonder why
                         the new account cannot do anything. --}}
                    <p class="text-muted small mb-0 mt-3">
                        New accounts start with no role and can do nothing until a Super Admin assigns one.
                    </p>
                @endunless
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1" for="f-q">Search</label>
                    <input type="search" id="f-q" class="form-control form-control-sm" placeholder="Name or email">
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="f-role">Role</label>
                    <select id="f-role" class="form-select form-select-sm">
                        <option value="">Any</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}">{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-grid">
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
                    <th>Name</th><th>Email</th><th>Roles</th>
                    <th>Team</th><th>Last sign-in</th><th class="text-end">Status</th>
                </tr>
                </thead>
                <tbody id="user-rows">
                <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted" id="user-meta"></span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="page-prev">Previous</button>
                <button class="btn btn-outline-secondary" id="page-next">Next</button>
            </div>
        </div>
    </div>

    @if ($canManageRoles)
        <div class="modal fade" id="roles-modal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h6">Roles</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted" id="roles-subject"></p>
                        @foreach ($roles as $role)
                            <div class="form-check">
                                <input class="form-check-input role-option" type="checkbox"
                                       value="{{ $role->value }}" id="role-{{ $role->value }}">
                                <label class="form-check-label small" for="role-{{ $role->value }}">
                                    {{ $role->label() }}
                                </label>
                            </div>
                        @endforeach
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary" id="save-roles">Save roles</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
$(function () {
    let page = 1;
    let editing = null;
    const canManage = @json($canManage);
    const canManageRoles = @json($canManageRoles);
    const me = {{ auth()->id() }};
    const rolesModal = canManageRoles ? new bootstrap.Modal(document.getElementById('roles-modal')) : null;

    function load() {
        const params = { page: page };
        const q = $('#f-q').val();
        if (q) params.q = q;
        const role = $('#f-role').val();
        if (role) params.role = role;

        $.getJSON('/api/v1/users', params)
            .done(function (response) { render(response.data.items, response.data.meta); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#user-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">Could not load users.</td></tr>');
            });
    }

    function render(items, meta) {
        if (!items.length) {
            $('#user-rows').html('<tr><td colspan="6" class="text-center text-muted py-4">No users match.</td></tr>');
        } else {
            $('#user-rows').html(items.map(function (user) {
                const roles = (user.roles || []).map(function (r) {
                    return '<span class="badge text-bg-light border me-1">' + CRM.escape(r.name || r) + '</span>';
                }).join('') || '<span class="text-muted small">No role</span>';

                return '<tr>'
                    + '<td>' + CRM.escape(user.name)
                    + (user.id === me ? ' <span class="badge text-bg-secondary">you</span>' : '')
                    + '</td>'
                    + '<td class="small">' + CRM.escape(user.email) + '</td>'
                    + '<td>' + roles
                    + (canManageRoles && user.id !== me
                        ? ' <button class="btn btn-link btn-sm p-0 align-baseline edit-roles"'
                          + ' data-id="' + user.id + '" data-name="' + CRM.escape(user.name) + '"'
                          + " data-roles='" + JSON.stringify((user.roles || []).map(r => r.name || r)) + "'>edit</button>"
                        : '')
                    + '</td>'
                    + '<td class="small">' + CRM.escape(user.team ? user.team.name : '—') + '</td>'
                    + '<td class="small text-muted">'
                    + (user.last_login_at ? user.last_login_at.substring(0, 10) : 'Never') + '</td>'
                    + '<td class="text-end">' + statusCell(user) + '</td>'
                    + '</tr>';
            }).join(''));
        }

        $('#user-meta').text(meta.total + ' user' + (meta.total === 1 ? '' : 's')
            + ' · page ' + meta.current_page + ' of ' + meta.last_page);
        $('#page-prev').prop('disabled', meta.current_page <= 1);
        $('#page-next').prop('disabled', meta.current_page >= meta.last_page);
    }

    function statusCell(user) {
        const badge = user.is_active
            ? '<span class="badge text-bg-success">Active</span>'
            : '<span class="badge text-bg-secondary">Disabled</span>';

        // No disable button against your own row - the server refuses it
        // anyway, and offering it invites the mistake.
        if (!canManage || user.id === me) return badge;

        return badge + ' <button class="btn btn-sm btn-outline-secondary ms-1 toggle-active"'
            + ' data-id="' + user.id + '" data-active="' + (user.is_active ? '1' : '') + '">'
            + (user.is_active ? 'Disable' : 'Enable') + '</button>';
    }

    // ------------------------------------------------------------------ create
    $('#create-user').on('click', function () {
        $('#user-rows').closest('.card').find('.is-invalid').removeClass('is-invalid');
        $('.invalid-feedback').text('');

        $.ajax({
            url: '/api/v1/users',
            method: 'POST',
            data: {
                name: $('#f-name').val(),
                email: $('#f-email').val(),
                password: $('#f-password').val()
            }
        })
            .done(function (response) {
                $('#f-name, #f-email, #f-password').val('');
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) {
                const errors = (xhr.responseJSON || {}).errors || [];
                let unattached = [];

                errors.forEach(function (error) {
                    const input = error.field ? $('#f-' + error.field) : $();
                    if (input.length) {
                        input.addClass('is-invalid');
                        input.closest('div').find('.invalid-feedback').text(error.message);
                    } else {
                        unattached.push(error.message);
                    }
                });

                if (!errors.length || unattached.length) {
                    CRM.alert(unattached.length ? unattached.join(' ') : CRM.errorFrom(xhr));
                }
            });
    });

    // ------------------------------------------------------------------ roles
    $('#user-rows').on('click', '.edit-roles', function () {
        editing = $(this).data('id');
        const held = $(this).data('roles') || [];

        $('#roles-subject').text($(this).data('name'));
        $('.role-option').each(function () {
            $(this).prop('checked', held.indexOf($(this).val()) !== -1);
        });

        rolesModal.show();
    });

    $('#save-roles').on('click', function () {
        const roles = $('.role-option:checked').map(function () { return this.value; }).get();

        $.ajax({
            url: '/api/v1/users/' + editing + '/roles',
            method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify({ roles: roles })
        })
            .done(function (response) {
                rolesModal.hide();
                CRM.alert(response.message, 'success');
                load();
            })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    // ------------------------------------------------------------- enable/disable
    $('#user-rows').on('click', '.toggle-active', function () {
        const id = $(this).data('id');
        const disabling = !!$(this).data('active');

        if (disabling && ! window.confirm('Disable this user? They will be signed out of every device.')) {
            return;
        }

        $.ajax({ url: '/api/v1/users/' + id + '/' + (disabling ? 'disable' : 'enable'), method: 'POST' })
            .done(function (response) { CRM.alert(response.message, 'success'); load(); })
            .fail(function (xhr) { CRM.alert(CRM.errorFrom(xhr)); });
    });

    $('#f-apply').on('click', function () { page = 1; load(); });
    $('#f-q').on('keypress', function (e) { if (e.which === 13) { page = 1; load(); } });
    $('#page-prev').on('click', function () { if (page > 1) { page--; load(); } });
    $('#page-next').on('click', function () { page++; load(); });

    load();
});
</script>
@endpush
