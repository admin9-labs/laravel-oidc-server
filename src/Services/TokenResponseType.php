<?php

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;
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

        $userModel = config('oidc.user_model', config('auth.providers.users.model'));
        $user = $userModel::find($accessToken->getUserIdentifier());

        if (! $user || ! $user instanceof OidcUserInterface) {
            return [];
        }

        $nonce = request()->input('nonce');

        $idToken = $this->idTokenService->generateToken(
            $accessToken,
            $user,
            $accessToken->getClient(),
            $nonce
        );

        return [
            'id_token' => $idToken,
        ];
    }
}
