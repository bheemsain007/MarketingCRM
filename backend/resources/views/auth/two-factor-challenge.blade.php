<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-factor authentication · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #1f2937; }</style>
</head>
<body class="d-flex align-items-center min-vh-100">
<div class="container" style="max-width: 400px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 mb-1">Two-factor authentication</h1>
            <p class="text-muted small mb-4">
                Enter the six-digit code from your authenticator app, or one of your recovery codes.
            </p>

            {{--
                One error bag. The service returns the same message for a wrong
                code, an expired one, a replayed one and a spent recovery code,
                so splitting them across fields would give back by implication
                exactly what the single message withholds (SEC-AUTH-07).
            --}}
            @if ($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('web.two-factor.verify') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label class="form-label small" for="code">Code</label>
                    {{-- inputmode, not type=number: a recovery code is
                         alphanumeric and type=number would silently drop it. --}}
                    <input type="text" class="form-control" id="code" name="code"
                           inputmode="text" autocomplete="one-time-code"
                           required autofocus>
                </div>

                <button type="submit" class="btn btn-primary w-100">Verify</button>
            </form>

            <form method="POST" action="{{ route('web.logout') }}" class="mt-3 text-center">
                @csrf
                <button type="submit" class="btn btn-link btn-sm text-decoration-none">Sign in as someone else</button>
            </form>
        </div>
    </div>
    <p class="text-center text-secondary small mt-3 mb-0">
        Lost your device and your recovery codes? A Super Admin can reset this for you.
    </p>
</div>
</body>
</html>
