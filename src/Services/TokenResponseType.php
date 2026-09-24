<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Events\OidcTokenIssued;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

class TokenResponseType extends BearerTokenResponse
{
    protected IdTokenService $idTokenService;

    public function __construct(IdTokenService $idTokenService)
    {
        $this->idTokenService = $idTokenService;
    }

    /**
     * Add ID Token to the response if 'openid' scope is present.
     */
    protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(function ($scope) {
            return $scope->getIdentifier();
        }, $accessToken->getScopes());

        if (! in_array('openid', $scopes)) {
            return [];
        }

        $context = app(AuthorizationContext::class)->forToken($accessToken);
        // Legacy refresh tokens can still renew OAuth access, but cannot prove
        // the original OIDC transaction and identity.
        if ($context === null) {
            return [];
        }

        $userModel = config('oidc-server.user_model');
        if ($userModel === null) {
            $guard = config('passport.guard') ?? config('auth.defaults.guard');
            $provider = config('auth.guards.'.$guard.'.provider');
            $userModel = config('auth.providers.'.$provider.'.model');
        }

        $user = $userModel::find($accessToken->getUserIdentifier());

        if (! $user || ! $user instanceof OidcUserInterface) {
            return [];
        }
        if (($context['identity'][1] ?? null) !== get_class($user)
            || ($context['sub'] ?? null) !== $user->getOidcSubject()
            || ($context['iss'] ?? null) !== config('oidc-server.issuer', config('app.url'))) {
            throw OAuthServerException::invalidGrant('The original OIDC identity has changed. Restart authorization.');
        }

        $nonce = $this->resolveNonce();

        $idToken = $this->idTokenService->generateToken(
            $accessToken,
            $user,
            $accessToken->getClient(),
            $nonce
        );

        OidcTokenIssued::dispatch($user->getKey(), $accessToken->getClient()->getIdentifier(), $scopes);

        return [
            'id_token' => $idToken,
        ];
    }

    /**
     * Resolve nonce only from the authenticated authorization-code envelope.
     */
    protected function resolveNonce(): ?string
    {
        return request()->input('grant_type') === 'authorization_code'
            ? (app(AuthorizationContext::class)->forToken($this->accessToken)['nonce'] ?? null)
            : null;
    }

    // Preserve the OIDC identity context through every refresh rotation.
    protected function encrypt($unencryptedData): string
    {
        $payload = json_decode($unencryptedData, true, 512, JSON_THROW_ON_ERROR);
        $context = app(AuthorizationContext::class)->forToken($this->accessToken);
        if ($context !== null) {
            $payload['oidc'] = $context;
        }

        return parent::encrypt(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
