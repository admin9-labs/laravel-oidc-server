<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $admin ? 'Admin sign in' : 'Member reauthentication' }}</title></head>
<body>
<main>
    <h1>{{ $admin ? 'Admin sign in' : 'Member reauthentication' }}</h1>
    <form method="post" action="{{ $admin ? '/admin/login' : '/member/reauthenticate' }}">
        @csrf
        @if ($challenge)
            <input type="hidden" name="transaction" value="{{ $challenge->transactionId }}">
            <input type="hidden" name="challenge" value="{{ $challenge->token }}">
        @endif
        <p><label>Email <input name="email" type="email" required autocomplete="username"></label></p>
        <p><label>Password <input name="password" type="password" required autocomplete="current-password"></label></p>
        @unless ($admin)
            <p><label>Authenticator code <input name="code" inputmode="numeric" pattern="[0-9]{6}" required autocomplete="one-time-code"></label></p>
        @endunless
        <button type="submit">{{ $admin ? 'Sign in' : 'Verify authentication' }}</button>
    </form>
</main>
</body>
</html>
