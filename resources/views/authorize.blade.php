@props(['backButton' => true])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Authorization Request') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-md w-full max-w-md p-6">
        <h2 class="text-xl font-semibold text-gray-900">{{ __('Authorization Request') }}</h2>

        <p class="mt-3 text-gray-700">
            <strong class="text-blue-600">{{ $client->name }}</strong>
            {{ __('is requesting access to your account.') }}
        </p>

        @if (count($scopes) > 0)
            <div class="mt-4">
                <p class="mb-2 font-medium text-gray-800">{{ __('This application will be able to:') }}</p>
                <ul class="list-inside list-disc space-y-1 text-sm text-gray-600">
                    @foreach ($scopes as $scope)
                        <li>{{ $scope->description }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex gap-3 mt-6">
            <form method="post" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state }}" />
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}" />
                <input type="hidden" name="auth_token" value="{{ $authToken }}" />
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition">
                    {{ __('Authorize') }}
                </button>
            </form>

            <form method="post" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state }}" />
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}" />
                <input type="hidden" name="auth_token" value="{{ $authToken }}" />
                <button type="submit" class="w-full px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition">
                    {{ __('Deny') }}
                </button>
            </form>
        </div>
    </div>
</body>
</html>
