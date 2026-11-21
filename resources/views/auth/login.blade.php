<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Operator login — Knots &amp; Perl</title>
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>
<main class="admin-main" style="max-width:520px;margin:8vh auto">
    <h1>Operator login</h1>
    @if($setup)
        <div class="alert alert-error">Set ADMIN_PASSWORD_HASH in .env to enable the dashboard</div>
    @endif
    @if($errors->any())
        <div class="alert alert-error">{{ $errors->first('password') }}</div>
    @endif
    <form method="POST" action="{{ route('login.attempt') }}">
        @csrf
        <label>Password
            <input type="password" name="password" required autofocus autocomplete="current-password">
        </label>
        <button type="submit">Log in</button>
    </form>
</main>
</body>
</html>
