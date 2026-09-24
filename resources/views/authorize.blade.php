<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Authorization Request') }}</title>
    @include('oidc-server::partials.styles')
</head>
<body>
    <main>
        <h1>{{ __('Authorization Request') }}</h1>
        <p><strong class="client">{{ $client->name }}</strong> {{ __('is requesting access to your account.') }}</p>
        @if (count($scopes) > 0)
            <p>{{ __('This application will be able to:') }}</p>
            <ul>
                @foreach ($scopes as $scope)
                    <li>{{ $scope->description }}</li>
                @endforeach
            </ul>
        @endif
        <div class="actions">
            <form method="post" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit">{{ __('Authorize') }}</button>
            </form>
            <form method="post" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="secondary">{{ __('Deny') }}</button>
            </form>
        </div>
    </main>
</body>
</html>
