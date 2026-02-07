<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Events\OidcTokenIssued;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
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

        $userModel = config('oidc-server.user_model', config('auth.providers.users.model'));
        $user = $userModel::find($accessToken->getUserIdentifier());

        if (! $user || ! $user instanceof OidcUserInterface) {
            return [];
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
     * Resolve the nonce from the current request.
     * Override this method to resolve the nonce from a different source.
     */
    protected function resolveNonce(): ?string
    {
        return request()->input('nonce');
    }
}
