<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Http\Middleware;

use Admin9\OidcServer\Services\AuthorizationContext;
use Admin9\OidcServer\Services\TokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAuthorizationPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()->getActionMethod();
        if ($action === 'authorize') {
            if ($request->isMethod('HEAD')) {
                return response('', 200);
            }
            if ($request->query('response_type') !== 'code') {
                return response()->json(['error' => 'unsupported_response_type'], 400);
            }

            if ($request->query->has('code_challenge')
                && $request->query('code_challenge_method') !== 'S256') {
                return response()->json(['error' => 'invalid_request', 'error_description' => 'PKCE requires S256.'], 400);
            }
            if ($response = app(AuthorizationContext::class)->prepare($request)) {
                return $response;
            }
        }

        if ($action === 'issueToken') {
            $field = match ($request->input('grant_type')) {
                'authorization_code' => 'code', 'refresh_token' => 'refresh_token', default => null,
            };
            $value = $field ? $request->input($field) : null;
            $payload = is_string($value) ? app(TokenVerifier::class)->encryptedPayload($value) : null;
            // Include codes issued before the upgrade. Passport still validates client,
            // redirect URI, expiry, one-time use and the actual verifier afterwards.
            if ($field === 'code' && $payload && isset($payload['code_challenge'])
                && ($payload['code_challenge_method'] ?? 'plain') !== 'S256') {
                return response()->json(['error' => 'invalid_grant', 'error_description' => 'PKCE requires S256.'], 400);
            }
            if ($field === 'code' && $payload && in_array('openid', $payload['scopes'] ?? [], true) && ! isset($payload['oidc'])) {
                return response()->json(['error' => 'invalid_grant', 'error_description' => 'Restart authorization after the server upgrade.'], 400);
            }
            if ($payload && array_key_exists('oidc', $payload) && ! app(AuthorizationContext::class)->validPayload($payload)) {
                return response()->json(['error' => 'invalid_grant'], 400);
            }
        }

        if (in_array($action, ['approve', 'deny'], true)) {
            $pending = $request->session()->pull('oidc.authorization_pending');
            $token = $request->input('auth_token');
            if (! is_array($pending) || ! is_string($token) || $token === ''
                || ! hash_equals($pending['token'], $token)
                || $request->session()->get('authToken') !== $token
                || $pending['identity'] !== $this->identity()
                || ($pending['context']['v'] ?? null) !== AuthorizationContext::VERSION) {
                return response()->json(['error' => 'invalid_request'], 400);
            }
            $request->attributes->set(AuthorizationContext::ATTRIBUTE, $pending['context']);
        }

        $response = $next($request);
        if ($action === 'authorize'
            && $response->getStatusCode() === 200 && $request->session()->has('authToken')) {
            // Only this policy-checked authorization page can create an approval.
            // Old pending implicit/plain flows and another user's session cannot.
            $request->session()->put('oidc.authorization_pending', [
                'token' => $request->session()->get('authToken'),
                'identity' => $this->identity(),
                'context' => $request->attributes->get(AuthorizationContext::ATTRIBUTE),
            ]);
        }

        return $response;
    }

    protected function identity(): array
    {
        $guard = config('passport.guard') ?? Auth::getDefaultDriver();
        $user = Auth::guard($guard)->user();

        return [$guard, $user ? get_class($user) : null, $user ? (string) $user->getAuthIdentifier() : null];
    }
}
