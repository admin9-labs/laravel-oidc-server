<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Sign out') }}</title>
    @include('oidc-server::partials.styles')
</head>
<body>
    <main>
        <h1>{{ __('Sign out') }}</h1>
        <p>{{ __('Are you sure you want to sign out?') }}</p>
        <div class="actions">
            <form method="post" action="{{ route('oidc.logout.confirm') }}">
                @csrf
                <input type="hidden" name="confirmation" value="{{ $challenge }}">
                <button type="submit">{{ __('Sign out') }}</button>
            </form>
            <a class="cancel" href="{{ url('/') }}">{{ __('Cancel') }}</a>
        </div>
    </main>
</body>
</html>
