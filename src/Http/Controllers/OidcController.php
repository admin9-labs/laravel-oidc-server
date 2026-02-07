<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Http\Controllers;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Events\OidcLogoutInitiated;
use Admin9\OidcServer\Events\OidcUserInfoRequested;
use Admin9\OidcServer\Services\ClaimsService;
use Defuse\Crypto\Crypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

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
            'response_types_supported' => config('oidc-server.response_types_supported'),
            'subject_types_supported' => config('oidc-server.subject_types_supported'),
            'id_token_signing_alg_values_supported' => config('oidc-server.id_token_signing_alg_values_supported'),
            'scopes_supported' => array_keys(config('oidc-server.scopes')),
            'token_endpoint_auth_methods_supported' => config('oidc-server.token_endpoint_auth_methods_supported'),
            'claims_supported' => $this->claimsService->getSupportedClaims(),
            'code_challenge_methods_supported' => config('oidc-server.code_challenge_methods_supported'),
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
        $publicKeyPath = storage_path('oauth-public.key');

        if (! file_exists($publicKeyPath)) {
            return response()->json([
                'error' => 'Public key not found',
                'error_description' => 'The OAuth public key has not been generated.',
            ], 500);
        }

        $publicKey = file_get_contents($publicKeyPath);
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
        $client = $this->authenticateClient($request);
        if (! $client) {
            return response()->json([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.',
            ], 401);
        }

        $token = $request->input('token');
        $tokenTypeHint = $request->input('token_type_hint', 'access_token');

        if ($tokenTypeHint && ! in_array($tokenTypeHint, ['access_token', 'refresh_token'], true)) {
            return response()->json([
                'error' => 'unsupported_token_type',
                'error_description' => 'token_type_hint must be access_token or refresh_token.',
            ], 400);
        }

        if (! $token) {
            return response()->json(['active' => false]);
        }

        $tokenInfo = $this->findToken($token, $tokenTypeHint);

        if (! $tokenInfo) {
            return response()->json(['active' => false]);
        }

        return response()->json($tokenInfo);
    }

    /**
     * Token Revocation endpoint (RFC 7009)
     *
     * @see https://datatracker.ietf.org/doc/html/rfc7009
     */
    public function revoke(Request $request): JsonResponse
    {
        $client = $this->authenticateClient($request);
        if (! $client) {
            Log::warning('OIDC: Revoke client authentication failed');

            return response()->json([
                'error' => 'invalid_client',
                'error_description' => 'Client authentication failed.',
            ], 401);
        }

        $token = $request->input('token');
        $tokenTypeHint = $request->input('token_type_hint', 'access_token');

        if ($tokenTypeHint && ! in_array($tokenTypeHint, ['access_token', 'refresh_token'], true)) {
            return response()->json([
                'error' => 'unsupported_token_type',
                'error_description' => 'token_type_hint must be access_token or refresh_token.',
            ], 400);
        }

        if (! $token) {
            return response()->json([], 200);
        }

        $this->revokeToken($token, $tokenTypeHint, $client);

        return response()->json([], 200);
    }

    /**
     * RP-Initiated Logout endpoint
     *
     * @see https://openid.net/specs/openid-connect-rpinitiated-1_0.html
     */
    public function logout(Request $request)
    {
        $postLogoutRedirectUri = $request->query('post_logout_redirect_uri');
        $idTokenHint = $request->query('id_token_hint');
        $state = $request->query('state');

        $client = null;
        if ($idTokenHint) {
            // Security note: We parse the id_token_hint WITHOUT signature verification.
            // This is intentional per the OIDC RP-Initiated Logout spec — the hint is only
            // used to identify the client for redirect URI validation, not for authentication.
            // The actual security boundary is the post_logout_redirect_uri validation below.
            try {
                $parser = new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder);
                /** @var \Lcobucci\JWT\Token\Plain $token */
                $token = $parser->parse($idTokenHint);
                $clientId = $token->claims()->get('aud');
                if (is_array($clientId)) {
                    $clientId = $clientId[0];
                }
                $client = Client::find($clientId);
            } catch (\Exception $e) {
            }
        }

        OidcLogoutInitiated::dispatch(
            auth()->guard('web')->id(),
            $client?->id,
        );

        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($postLogoutRedirectUri) {
            $isValid = false;

            if ($client) {
                $allowedUris = $client->redirect_uris ?? [];
                $isValid = $this->isValidPostLogoutUri($postLogoutRedirectUri, $allowedUris);
            } else {
                $isValid = $this->isValidPostLogoutUri($postLogoutRedirectUri, [config('app.url')]);
            }

            if ($isValid) {
                $url = $postLogoutRedirectUri;
                if ($state) {
                    $url .= (str_contains($url, '?') ? '&' : '?').'state='.urlencode($state);
                }

                return redirect($url);
            }
        }

        return redirect('/');
    }

    protected function authenticateClient(Request $request): ?Client
    {
        $clientId = null;
        $clientSecret = null;

        if ($request->headers->has('Authorization')) {
            $authHeader = $request->headers->get('Authorization');
            if (str_starts_with($authHeader, 'Basic ')) {
                $decoded = base64_decode(substr($authHeader, 6), true);
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$clientId, $clientSecret] = explode(':', $decoded, 2);
                    $clientId = urldecode($clientId);
                    $clientSecret = urldecode($clientSecret);
                }
            }
        }

        if (! $clientId) {
            $clientId = $request->input('client_id');
            $clientSecret = $request->input('client_secret');
        }

        if (! $clientId) {
            return null;
        }

        $client = Client::find($clientId);

        if (! $client) {
            return null;
        }

        if ($client->confidential() && ! Hash::check($clientSecret, $client->secret)) {
            return null;
        }

        return $client;
    }

    protected function findToken(string $token, string $tokenTypeHint): ?array
    {
        if ($tokenTypeHint === 'access_token' || $tokenTypeHint === '') {
            $accessToken = Token::where('id', $token)->first();

            if ($accessToken && ! $accessToken->revoked) {
                $expiresAt = $accessToken->expires_at;
                $isActive = $expiresAt && $expiresAt->isFuture();

                if ($isActive) {
                    $user = $accessToken->user;

                    return [
                        'active' => true,
                        'scope' => implode(' ', $accessToken->scopes ?? []),
                        'client_id' => $accessToken->client_id,
                        'username' => $user?->email,
                        'token_type' => 'Bearer',
                        'exp' => $expiresAt->timestamp,
                        'iat' => $accessToken->created_at->timestamp,
                        'sub' => (string) $accessToken->user_id,
                        'aud' => $accessToken->client_id,
                        'iss' => config('oidc-server.issuer', config('app.url')),
                    ];
                }
            }
        }

        if ($tokenTypeHint === 'refresh_token') {
            $refreshToken = RefreshToken::where('id', $token)->first();

            if ($refreshToken && ! $refreshToken->revoked) {
                $expiresAt = $refreshToken->expires_at;
                $isActive = $expiresAt && $expiresAt->isFuture();

                if ($isActive) {
                    return [
                        'active' => true,
                        'token_type' => 'refresh_token',
                        'exp' => $expiresAt->timestamp,
                        'client_id' => $refreshToken->access_token?->client_id,
                    ];
                }
            }
        }

        return null;
    }

    protected function revokeToken(string $token, string $tokenTypeHint, Client $client): void
    {
        $revoked = false;

        if ($tokenTypeHint === 'refresh_token' || $tokenTypeHint === '') {
            $tokenId = $this->extractRefreshTokenId($token);
            if ($tokenId) {
                $refreshToken = RefreshToken::where('id', $tokenId)->first();
                if ($refreshToken && $refreshToken->accessToken?->client_id === $client->id) {
                    Log::info('OIDC: Revoking refresh token and its access token', ['id' => $tokenId]);
                    $refreshToken->update(['revoked' => true]);
                    $refreshToken->accessToken?->revoke();
                    $revoked = true;
                }
            }
        }

        if (! $revoked && ($tokenTypeHint === 'access_token' || $tokenTypeHint === '')) {
            $tokenId = $this->extractAccessTokenId($token);
            if ($tokenId) {
                $accessToken = Token::where('id', $tokenId)
                    ->where('client_id', $client->id)
                    ->first();

                if ($accessToken) {
                    Log::info('OIDC: Revoking access token and its refresh tokens', ['id' => $tokenId]);
                    $accessToken->revoke();
                    RefreshToken::where('access_token_id', $accessToken->id)
                        ->update(['revoked' => true]);
                    $revoked = true;
                }
            }
        }

        if (! $revoked) {
            Log::info('OIDC: No token found to revoke with provided credentials/hint');
        }
    }

    protected function extractAccessTokenId(string $token): ?string
    {
        try {
            if (str_contains($token, '.')) {
                $parser = new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder);
                /** @var \Lcobucci\JWT\Token\Plain $jwt */
                $jwt = $parser->parse($token);

                return $jwt->claims()->get('jti');
            }

            return $token;
        } catch (\Exception $e) {
            Log::error('OIDC: Failed to parse access token', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function extractRefreshTokenId(string $token): ?string
    {
        try {
            $key = config('app.key');
            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(substr($key, 7));
            }

            $decrypted = Crypto::decryptWithPassword($token, $key);
            $payload = json_decode($decrypted, true);

            return $payload['refresh_token_id'] ?? null;
        } catch (\Exception $e) {
            if (strlen($token) === 80 && ! str_contains($token, '.')) {
                return $token;
            }

            Log::error('OIDC: Failed to decrypt refresh token', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 5).'...',
            ]);

            return null;
        }
    }

    protected function isValidPostLogoutUri(string $uri, array $allowedUris): bool
    {
        $uriParsed = parse_url($uri);

        if (! isset($uriParsed['scheme'], $uriParsed['host'])) {
            return false;
        }

        foreach ($allowedUris as $allowed) {
            $allowedParsed = parse_url(trim($allowed));

            if (! isset($allowedParsed['scheme'], $allowedParsed['host'])) {
                continue;
            }

            $schemeMatch = strtolower($uriParsed['scheme']) === strtolower($allowedParsed['scheme']);
            $hostMatch = strtolower($uriParsed['host']) === strtolower($allowedParsed['host']);
            $portMatch = ($uriParsed['port'] ?? null) === ($allowedParsed['port'] ?? null);

            if ($schemeMatch && $hostMatch && $portMatch) {
                $allowedPath = rtrim($allowedParsed['path'] ?? '/', '/');
                $uriPath = $uriParsed['path'] ?? '/';

                // Exact match or the URI path starts with the allowed path followed by /
                if ($allowedPath === '' || $uriPath === $allowedPath || str_starts_with($uriPath, $allowedPath.'/')) {
                    return true;
                }
            }
        }

        return false;
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