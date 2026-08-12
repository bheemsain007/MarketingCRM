<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your password · {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body { background: #1f2937; }</style>
</head>
<body class="d-flex align-items-center min-vh-100">
<div class="container" style="max-width: 400px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 mb-1">Reset your password</h1>
            <p class="text-muted small mb-4">We will email you a link to choose a new one.</p>

            {{--
                Success is reported the same way whether or not the address
                matched an account - the page must not become a way to ask "does
                this person work here?" (SEC-AUTH-02).
            --}}
            @if (session('status'))
                <div class="alert alert-success py-2 small">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('web.password.email') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label class="form-label small" for="email">Email</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="{{ old('email') }}" required autofocus autocomplete="username">
                </div>

                <button type="submit" class="btn btn-primary w-100">Email me a link</button>
            </form>

            <p class="text-center small mt-3 mb-0">
                <a href="{{ route('web.login') }}" class="text-decoration-none">Back to sign in</a>
            </p>
        </div>
    </div>
    <p class="text-center text-secondary small mt-3 mb-0">
        Reset links expire shortly and can only be used once.
    </p>
</div>
</body>
</html>
