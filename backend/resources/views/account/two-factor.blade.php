{{--
    Two-factor enrolment for the signed-in administrator (SEC-AUTH-07, T-09).

    A server-rendered form rather than the AJAX-to-/api/v1 pattern the rest of
    the Web CRM uses (ADR-A). The reason is the secret: the enrolment secret and
    the recovery codes are password-equivalents that exist in readable form
    exactly once, and posting them through the browser's XHR layer would put
    them in every debugging proxy, browser devtools history and jQuery error
    report along the way. A POST-redirect-GET keeps them to one response body.
--}}
@extends('layouts.app')
@section('title', 'Two-factor authentication')

@section('content')
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 mb-3">Two-factor authentication</h2>

                @if (session('status'))
                    <div class="alert alert-success py-2 small">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
                @endif

                @unless ($mayEnrol)
                    {{-- Not an error: 2FA is offered to Admin and Super Admin,
                         the accounts that can read every lead and change
                         provider credentials. Say so plainly rather than 403. --}}
                    <p class="small text-muted mb-0">
                        Two-factor authentication is available to administrator accounts.
                    </p>
                @else
                    @if ($enabled)
                        <p class="small mb-3">
                            <span class="badge text-bg-success">On</span>
                            Enabled {{ $user->two_factor_confirmed_at?->diffForHumans() }}.
                            <span class="text-muted">{{ $remaining }} recovery code(s) remaining.</span>
                        </p>

                        <form method="POST" action="{{ route('web.account.two-factor.recovery') }}" class="mb-4">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                Issue new recovery codes
                            </button>
                            <span class="small text-muted ms-2">The current set stops working.</span>
                        </form>

                        <hr>

                        {{-- The password is not ceremony: without it, an
                             unattended signed-in browser is enough to strip the
                             second factor off the account. --}}
                        <form method="POST" action="{{ route('web.account.two-factor.disable') }}" novalidate>
                            @csrf
                            <label class="form-label small" for="password">Confirm your password to turn it off</label>
                            <div class="input-group input-group-sm" style="max-width: 340px;">
                                <input type="password" class="form-control" id="password" name="password"
                                       autocomplete="current-password" required>
                                <button type="submit" class="btn btn-outline-danger">Turn off</button>
                            </div>
                        </form>
                    @elseif ($secret)
                        <p class="small mb-2">
                            Add this account to your authenticator app, then enter the code it shows.
                        </p>

                        <p class="small text-muted mb-1">Setup key</p>
                        <p class="font-monospace bg-body-tertiary p-2 rounded small">{{ $secret }}</p>

                        <p class="small text-muted mb-1">Or open this URI on the device</p>
                        <p class="font-monospace bg-body-tertiary p-2 rounded small text-break">{{ $otpauthUri }}</p>

                        <form method="POST" action="{{ route('web.account.two-factor.confirm') }}" novalidate>
                            @csrf
                            <label class="form-label small" for="code">Code from your app</label>
                            <div class="input-group input-group-sm" style="max-width: 280px;">
                                <input type="text" class="form-control" id="code" name="code"
                                       inputmode="numeric" autocomplete="one-time-code" required autofocus>
                                <button type="submit" class="btn btn-primary">Confirm</button>
                            </div>
                        </form>
                    @else
                        <p class="small text-muted mb-3">
                            <span class="badge text-bg-secondary">Off</span>
                            Recommended for administrator accounts (SEC-AUTH-07).
                        </p>

                        <form method="POST" action="{{ route('web.account.two-factor.begin') }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm">Set up two-factor authentication</button>
                        </form>
                    @endif
                @endunless
            </div>
        </div>
    </div>

    @if ($recoveryCodes)
        <div class="col-lg-5">
            <div class="card border-warning">
                <div class="card-body">
                    <h2 class="h6 mb-2">Recovery codes</h2>
                    {{-- Shown once. Only hashes are stored, so this page
                         genuinely cannot render them again - which is the
                         point, and worth saying out loud. --}}
                    <p class="small text-muted">
                        Each code works once. They are stored hashed, so this is the only time they can be shown.
                        Print them or keep them somewhere that is not the device running your authenticator.
                    </p>
                    <ul class="list-unstyled font-monospace small mb-0">
                        @foreach ($recoveryCodes as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
