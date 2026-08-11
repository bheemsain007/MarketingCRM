{{--
    The signed-in user's own account (T-46) - SEC-AUTH-04.

    Outside the permission system on purpose: every role must be able to change
    its own password, and a Viewer locked out of that would have no way to
    respond to a password they think is compromised.

    The write goes to POST /api/v1/auth/change-password like everything else, so
    the current-password check, the strength rules and the audit entry are the
    same ones the Flutter app will hit.
--}}
@extends('layouts.app')
@section('title', 'My account')

@section('content')
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 mb-3">Profile</h2>

                <dl class="row small mb-0">
                    <dt class="col-4 text-muted fw-normal">Name</dt>
                    <dd class="col-8">{{ $user->name }}</dd>

                    <dt class="col-4 text-muted fw-normal">Email</dt>
                    <dd class="col-8">{{ $user->email }}</dd>

                    <dt class="col-4 text-muted fw-normal">Roles</dt>
                    <dd class="col-8">{{ $user->roles->pluck('name')->join(', ') ?: '—' }}</dd>

                    <dt class="col-4 text-muted fw-normal">Team</dt>
                    <dd class="col-8">{{ $user->team?->name ?? '—' }}</dd>
                </dl>

                {{-- Read-only. Name, email, role and team are administrative
                     fields - a user editing their own role would be privilege
                     escalation, and there is no self-service user admin
                     endpoint to change the rest through (T-46). --}}
                <p class="text-muted small mt-3 mb-0">
                    Ask an administrator to change any of these.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h6 mb-3">Change password</h2>

                <form id="password-form" novalidate>
                    <div class="mb-3">
                        <label class="form-label small mb-1" for="f-current_password">Current password</label>
                        <input type="password" id="f-current_password" class="form-control form-control-sm"
                               autocomplete="current-password" required>
                        <div class="invalid-feedback"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1" for="f-password">New password</label>
                        <input type="password" id="f-password" class="form-control form-control-sm"
                               autocomplete="new-password" required>
                        {{-- Mirrors ChangePasswordRequest. The server is the
                             authority; this is here so the rule is visible
                             before the round trip, not after it. --}}
                        <div class="form-text small">At least 8 characters, with letters and numbers.</div>
                        <div class="invalid-feedback"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1" for="f-password_confirmation">Confirm new password</label>
                        <input type="password" id="f-password_confirmation" class="form-control form-control-sm"
                               autocomplete="new-password" required>
                        <div class="invalid-feedback"></div>
                    </div>

                    <button type="submit" id="save-password" class="btn btn-sm btn-primary">Change password</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="h6 mb-2">Sign out everywhere</h2>
                <p class="text-muted small mb-3">
                    Ends every signed-in device, including this browser, and closes your work session.
                    Use this if you think someone else has your password.
                </p>

                {{-- The browser logout is a real form post so it carries CSRF
                     and goes through the same route the header uses. The
                     script submits it after the API call, because
                     /auth/logout-all clears tokens but cannot clear this
                     browser's session cookie - without the second step the
                     button would claim more than it did. --}}
                <form method="POST" action="{{ route('web.logout') }}" id="web-logout-form" class="d-none">
                    @csrf
                </form>

                <button id="logout-everywhere" class="btn btn-sm btn-outline-danger">Sign out everywhere</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    function clearErrors() {
        $('#password-form .is-invalid').removeClass('is-invalid');
        $('#password-form .invalid-feedback').text('');
        $('#crm-alert').addClass('d-none');
    }

    $('#password-form').on('submit', function (event) {
        event.preventDefault();
        clearErrors();
        $('#save-password').prop('disabled', true);

        $.ajax({
            url: '/api/v1/auth/change-password',
            method: 'POST',
            data: {
                current_password: $('#f-current_password').val(),
                password: $('#f-password').val(),
                // The API's `confirmed` rule looks for exactly this name.
                password_confirmation: $('#f-password_confirmation').val()
            }
        })
            .done(function (response) {
                $('#password-form')[0].reset();
                // The message is the API's, which says what else happened -
                // other devices were signed out.
                CRM.alert(response.message, 'success');
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
            })
            .always(function () {
                $('#save-password').prop('disabled', false);
            });
    });

    $('#logout-everywhere').on('click', function () {
        if (!window.confirm('Sign out of every device, including this one?')) return;

        $(this).prop('disabled', true);

        // Tokens first, then this browser's session. Ordered that way so a
        // failure leaves the user still signed in here to try again, rather
        // than signed out of the one place they could retry from.
        $.ajax({ url: '/api/v1/auth/logout-all', method: 'POST' })
            .done(function () { $('#web-logout-form').trigger('submit'); })
            .fail(function (xhr) {
                CRM.alert(CRM.errorFrom(xhr));
                $('#logout-everywhere').prop('disabled', false);
            });
    });
});
</script>
@endpush
