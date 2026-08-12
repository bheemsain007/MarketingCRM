<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a new password · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #1f2937; }</style>
</head>
<body class="d-flex align-items-center min-vh-100">
<div class="container" style="max-width: 400px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 mb-1">Choose a new password</h1>
            <p class="text-muted small mb-4">Signing in elsewhere will be ended.</p>

            {{-- One bag, like the login form: an expired token and a forged one
                 must not be distinguishable (SEC-AUTH-06). --}}
            @if ($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('web.password.update') }}" novalidate>
                @csrf
                {{-- The token is what authorises this, not the email field. --}}
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="mb-3">
                    <label class="form-label small" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="{{ old('email', $email) }}" required autocomplete="username">
                </div>

                <div class="mb-3">
                    <label class="form-label small" for="password">New password</label>
                    <input type="password" class="form-control" id="password" name="password"
                           required autofocus autocomplete="new-password">
                    <div class="form-text small">At least 8 characters, with letters and numbers.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small" for="password_confirmation">Confirm new password</label>
                    <input type="password" class="form-control" id="password_confirmation"
                           name="password_confirmation" required autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary w-100">Set new password</button>
            </form>
        </div>
    </div>
    <p class="text-center text-secondary small mt-3 mb-0">
        Every other session and mobile sign-in will be signed out.
    </p>
</div>
</body>
</html>
