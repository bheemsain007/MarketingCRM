<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #1f2937; }</style>
</head>
<body class="d-flex align-items-center min-vh-100">
<div class="container" style="max-width: 400px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 mb-1">{{ config('app.name') }}</h1>
            <p class="text-muted small mb-4">Sign in to continue</p>

            @if (session('status'))
                <div class="alert alert-success py-2 small">{{ session('status') }}</div>
            @endif

            {{--
                One error bag, not per-field. The service refuses to say whether
                an account exists, so splitting the message across fields would
                give that away by implication (SEC-AUTH-02).
            --}}
            @if ($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('web.login.attempt') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label class="form-label small" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>

                <div class="mb-3">
                    <label class="form-label small" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password"
                           required autocomplete="current-password">
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
                    <label class="form-check-label small" for="remember">Keep me signed in</label>
                </div>

                <button type="submit" class="btn btn-primary w-100">Sign in</button>
            </form>

            {{-- The only route back in for somebody who has forgotten their
                 password: no administrator can set one for them (SEC-AUTH-06). --}}
            <p class="text-center small mt-3 mb-0">
                <a href="{{ route('web.password.request') }}" class="text-decoration-none">Forgotten your password?</a>
            </p>
        </div>
    </div>
    <p class="text-center text-secondary small mt-3 mb-0">
        Signing in opens a work session — your active time is recorded.
    </p>
</div>
</body>
</html>
