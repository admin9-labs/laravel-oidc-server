<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Http\Controllers;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Events\OidcLogoutInitiated;
use Admin9\OidcServer\Events\OidcUserInfoRequested;
use Admin9\OidcServer\Services\ClaimsService;
use Admin9\OidcServer\Services\PassportKeys;
use Admin9\OidcServer\Services\TokenVerifier;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

class OidcController extends Controller
{
    public function __construct(
        protected ClaimsService $claimsService
    ) {}

    /**
     * OpenID Connect Discovery Document
     *
     * @see https://openid.net/specs/openid-connect-discovery-1_0.html
     */
    public function discovery(): JsonResponse
    {
        $issuer = rtrim(config('oidc-server.issuer'), '/');

        $discovery = [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'end_session_endpoint' => $issuer.'/oauth/logout',
            'introspection_endpoint' => $issuer.'/oauth/introspect',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'post_logout_redirect_uris_supported' => config('oidc-server.post_logout_redirect_uris_supported'),
            'response_types_supported' => ['code'],
            'subject_types_supported' => config('oidc-server.subject_types_supported'),
            'id_token_signing_alg_values_supported' => config('oidc-server.id_token_signing_alg_values_supported'),
            'scopes_supported' => array_keys(config('oidc-server.scopes')),
            'token_endpoint_auth_methods_supported' => config('oidc-server.token_endpoint_auth_methods_supported'),
            'claims_supported' => $this->claimsService->getSupportedClaims(),
            'code_challenge_methods_supported' => ['S256'],
            'grant_types_supported' => config('oidc-server.grant_types_supported'),
            'introspection_endpoint_auth_methods_supported' => config('oidc-server.token_endpoint_auth_methods_supported'),
            'revocation_endpoint_auth_methods_supported' => config('oidc-server.token_endpoint_auth_methods_supported'),
        ];

        return response()->json($discovery)
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * JSON Web Key Set (JWKS) endpoint
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7517
     */
    public function jwks(): JsonResponse
    {
        try {
            $publicKey = app(PassportKeys::class)->key('public')->contents();
        } catch (\Throwable) {
            return response()->json([
                'error' => 'Public key not found',
                'error_description' => 'The OAuth public key has not been generated.',
            ], 500);
        }

        $keyResource = openssl_pkey_get_public($publicKey);

        if ($keyResource === false) {
            return response()->json([
                'error' => 'Invalid public key',
                'error_description' => 'Unable to parse the public key.',
            ], 500);
        }

        $keyDetails = openssl_pkey_get_details($keyResource);

        $jwk = [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->generateKeyId($publicKey),
            'n' => $this->base64UrlEncode($keyDetails['rsa']['n']),
            'e' => $this->base64UrlEncode($keyDetails['rsa']['e']),
        ];

        return response()->json(['keys' => [$jwk]])
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * UserInfo endpoint
     *
     * @see https://openid.net/specs/openid-connect-core-1_0.html#UserInfo
     */
    public function userinfo(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'The access token is invalid or expired.',
            ], 401);
        }

        $token = $user->token();
        $scopes = $token ? ($token->scopes ?? ['openid']) : ['openid'];

        OidcUserInfoRequested::dispatch($user->getKey(), $scopes);

        if ($user instanceof OidcUserInterface) {
            $claims = $this->claimsService->resolveForUser($user, $scopes);
        } else {
            $claims = ['sub' => (string) $user->getKey()];
        }

        return response()->json($claims)
            ->header('Content-Type', 'application/json');
    }

    /**
     * Token Introspection endpoint (RFC 7662)
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7662
     */
    public function introspect(Request $request): JsonResponse
    {
        $client = $this->authenticateClient($request, true);
        if (! $client) {
            return response()->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401);
        }

        $value = $request->input('token');
        if (! is_string($value) || $value === '') {
            return response()->json(['active' => false]);
        }

        $token = $this->findToken($value, $request->input('token_type_hint'), true);
        $owner = $token instanceof RefreshToken ? $token->accessToken : $token;
        if (! $owner || ! $this->mayIntrospect($client, $owner)) {
            return response()->json(['active' => false]);
        }

        if ($token instanceof RefreshToken) {
            return response()->json([
                'active' => true,
                'token_type' => 'refresh_token',
                'client_id' => $owner->client_id,
                'exp' => $token->expires_at->timestamp,
            ]);
        }

        $scopes = $token->scopes ?? [];
        $response = [
            'active' => true,
            'scope' => implode(' ', $scopes),
            'client_id' => $token->client_id,
            'token_type' => 'Bearer',
            'exp' => $token->expires_at->timestamp,
            'iat' => $token->created_at->timestamp,
            'sub' => (string) $token->user_id,
            'aud' => $token->client_id,
            'iss' => config('oidc-server.issuer', config('app.url')),
        ];
        if (in_array('email', $scopes, true)) {
            $response['username'] = $token->user?->email;
        }

        return response()->json($response);
    }

    public function revoke(Request $request): JsonResponse
    {
        $client = $this->authenticateClient($request);
        if (! $client) {
            return response()->json(['error' => 'invalid_client', 'error_description' => 'Client authentication failed.'], 401);
        }

        $value = $request->input('token');
        if (is_string($value) && $value !== '') {
            $token = $this->findToken($value, $request->input('token_type_hint'), false);
            $access = $token instanceof RefreshToken ? $token->accessToken : $token;
            if ($access && (string) $access->client_id === (string) $client->getKey()) {
                $access->getConnection()->transaction(function () use ($access): void {
                    $access->revoke();
                    Passport::refreshToken()->newQuery()->where('access_token_id', $access->id)
                        ->update(['revoked' => true]);
                });
            }
        }

        // Do not reveal whether a submitted token exists or belongs to another client.
        return response()->json([]);
    }

    public function logout(Request $request): Response
    {
        if ($request->isMethod('HEAD')) {
            return response('', 200);
        }

        $parameters = $this->logoutParameters($request);
        if ($parameters === null) {
            return response()->json(['error' => 'invalid_request'], 400);
        }

        [$client, $matchesUser] = $this->logoutClient($parameters);
        $redirect = $this->logoutRedirect($parameters['post_logout_redirect_uri'], $client);
        if (($parameters['id_token_hint'] !== null || $parameters['client_id'] !== null) && ! $client) {
            $redirect = null;
        }

        if ($matchesUser) {
            return $this->finishLogout($request, $client, $redirect, $parameters['state']);
        }

        $challenge = Str::random(64);
        $request->session()->put('oidc.logout_pending', [
            'challenge' => $challenge,
            'identity' => $this->logoutIdentity(),
            'client_id' => $client?->getKey(),
            'redirect' => $redirect,
            'state' => $parameters['state'],
            'expires_at' => time() + 300,
        ]);

        return response()->view('oidc-server::logout', ['challenge' => $challenge])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function confirmLogout(Request $request): Response
    {
        $pending = $request->session()->pull('oidc.logout_pending');
        $challenge = $request->input('confirmation');
        if (! is_array($pending) || ! is_string($challenge)
            || ! hash_equals($pending['challenge'], $challenge)
            || $pending['expires_at'] < time()
            || $pending['identity'] !== $this->logoutIdentity()) {
            return response()->json(['error' => 'invalid_request'], 400);
        }

        $client = $pending['client_id'] === null ? null : Passport::client()->find($pending['client_id']);
        $redirect = $this->logoutRedirect($pending['redirect'], $client);
        if ($pending['client_id'] !== null && (! $client || $client->revoked)) {
            $redirect = null;
        }

        return $this->finishLogout($request, $client, $redirect, $pending['state']);
    }

    protected function logoutParameters(Request $request): ?array
    {
        // Preserve protocol strings before Laravel's TrimStrings normalization.
        parse_str((string) $request->server('QUERY_STRING', ''), $input);
        if ($request->isMethod('POST')) {
            if (str_starts_with(strtolower($request->header('Content-Type', '')), 'multipart/form-data')) {
                // OIDC uses URL-encoded form serialization. Multipart input may
                // already have been normalized by PHP/Laravel before we can compare it.
                return null;
            }
            $content = $request->getContent();
            if ($request->isJson()) {
                try {
                    $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return null;
                }
                if (! is_array($body)) {
                    return null;
                }
            } elseif ($content !== '') {
                parse_str($content, $body);
            } else {
                $body = $request->request->all();
            }
            $input = $body + $input;
        }
        $parameters = [];
        foreach (['id_token_hint', 'client_id', 'post_logout_redirect_uri', 'state'] as $name) {
            $value = $input[$name] ?? null;
            if ($value !== null && (! is_string($value) || strlen($value) > 16384)) {
                return null;
            }
            $parameters[$name] = $value;
        }

        return $parameters;
    }

    protected function logoutClient(array $parameters): array
    {
        if ($parameters['id_token_hint'] === null) {
            $client = $parameters['client_id'] === null ? null : Passport::client()->find($parameters['client_id']);

            return [$client && ! $client->revoked ? $client : null, false];
        }

        $jwt = app(TokenVerifier::class)->signedJwt($parameters['id_token_hint']);
        if (! $jwt) {
            return [null, false];
        }

        $claims = $jwt->claims();
        $audience = $claims->get('aud');
        if ($jwt->headers()->get('typ') !== 'JWT'
            || $claims->get('iss') !== config('oidc-server.issuer', config('app.url'))
            || ! is_array($audience) || count($audience) !== 1 || ! is_string($audience[0])
            || ! is_string($claims->get('sub')) || $claims->get('sub') === ''
            || ! $claims->get('iat') instanceof \DateTimeImmutable
            || ! $claims->get('exp') instanceof \DateTimeImmutable
            || $claims->has('scopes') || $claims->has('jti')
            || ($parameters['client_id'] !== null && $parameters['client_id'] !== $audience[0])
            || ($claims->has('azp') && $claims->get('azp') !== $audience[0])) {
            return [null, false];
        }

        $client = Passport::client()->find($audience[0]);
        if (! $client || $client->revoked) {
            return [null, false];
        }

        $user = auth()->guard($this->logoutGuard())->user();
        $now = new \DateTimeImmutable;
        $matchesUser = $user instanceof OidcUserInterface
            && $claims->get('sub') === $user->getOidcSubject()
            && ! $claims->has('sid')
            && $claims->get('iat') <= $now && $claims->get('exp') > $now
            && (! $claims->has('nbf') || $claims->get('nbf') <= $now);

        // Expired hints can identify the RP for confirmation, never silently end a session.
        return [$client, $matchesUser];
    }

    protected function logoutGuard(): string
    {
        return config('passport.guard') ?? auth()->getDefaultDriver();
    }

    protected function logoutIdentity(): array
    {
        $guard = $this->logoutGuard();
        $user = auth()->guard($guard)->user();

        return [$guard, $user ? get_class($user) : null, $user ? (string) $user->getAuthIdentifier() : null];
    }

    protected function logoutRedirect(?string $uri, ?Client $client): ?string
    {
        if ($uri === null || $uri === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
            return null;
        }

        if ($client) {
            if ($client->revoked) {
                return null;
            }
            $registered = config('oidc-server.post_logout_redirect_uris', [])[(string) $client->getKey()] ?? null;
            if ($registered === null) {
                $registered = $client->redirect_uris ?? explode(',', (string) $client->redirect);
            }
        } else {
            $registered = config('oidc-server.post_logout_redirect_uris_supported', []);
        }

        return is_array($registered) && in_array($uri, $registered, true) ? $uri : null;
    }

    protected function finishLogout(Request $request, ?Client $client, ?string $redirect, ?string $state): Response
    {
        $guardName = $this->logoutGuard();
        $guard = auth()->guard($guardName);
        OidcLogoutInitiated::dispatch($guard->id(), $client?->getKey());
        $guard->logout();
        $request->session()->forget([
            'authToken', 'authRequest', 'promptedForLogin', 'oidc.logout_pending', 'oidc.authorization_pending',
            'password_hash_'.$guardName, 'auth.password_confirmed_at',
        ]);
        $request->session()->regenerate(true);

        if ($redirect === null) {
            return redirect('/')->header('Cache-Control', 'no-store');
        }
        if ($state !== null && $state !== '') {
            [$base, $fragment] = array_pad(explode('#', $redirect, 2), 2, null);
            $redirect = $base.(str_contains($base, '?') ? '&' : '?').'state='.rawurlencode($state);
            if ($fragment !== null) {
                $redirect .= '#'.$fragment;
            }
        }

        return redirect($redirect)->header('Cache-Control', 'no-store');
    }

    protected function authenticateClient(Request $request, bool $requireConfidential = false): ?Client
    {
        $clientId = $request->input('client_id');
        $secret = $request->input('client_secret');
        if ($request->headers->has('Authorization')) {
            $header = $request->header('Authorization');
            if (! preg_match('/^Basic ([A-Za-z0-9+\/]+=*)$/iD', $header, $matches)
                || ($decoded = base64_decode($matches[1], true)) === false
                || ! str_contains($decoded, ':')) {
                return null;
            }
            [$clientId, $secret] = array_map('urldecode', explode(':', $decoded, 2));
            if ($request->has('client_id') || $request->has('client_secret')) {
                return null;
            }
        }

        if ((! is_string($clientId) && ! is_int($clientId)) || (string) $clientId === '') {
            return null;
        }
        $client = Passport::client()->find($clientId);
        if (! $client || $client->revoked) {
            return null;
        }
        if (! $client->confidential()) {
            return $requireConfidential ? null : $client;
        }
        if (! is_string($secret) || $secret === '') {
            return null;
        }

        if (property_exists(Passport::class, 'hashesClientSecrets')) {
            $valid = Passport::$hashesClientSecrets
                ? password_verify($secret, $client->secret)
                : hash_equals($client->secret, $secret);
        } else {
            $valid = app(Hasher::class)->check($secret, $client->secret);
        }

        return $valid ? $client : null;
    }

    protected function findToken(string $value, mixed $hint, bool $activeOnly): Token|RefreshToken|null
    {
        $verifier = app(TokenVerifier::class);
        // A hint is an optimization, not a restriction, including unknown hints.
        return $hint === 'refresh_token'
            ? ($verifier->refreshToken($value, $activeOnly) ?? $verifier->accessToken($value, $activeOnly))
            : ($verifier->accessToken($value, $activeOnly) ?? $verifier->refreshToken($value, $activeOnly));
    }

    protected function mayIntrospect(Client $client, Token $token): bool
    {
        return (string) $client->getKey() === (string) $token->client_id
            || in_array((string) $token->client_id,
                config('oidc-server.introspection_allowed_clients', [])[(string) $client->getKey()] ?? [], true);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function generateKeyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }
}
