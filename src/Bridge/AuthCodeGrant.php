<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Bridge;

use Admin9\OidcServer\Services\AuthorizationContext;
use Admin9\OidcServer\Services\AuthorizationFlow;
use Admin9\OidcServer\Services\TokenConsumption;
use DateInterval;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant as PassportAuthCodeGrant;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;

class AuthCodeGrant extends PassportAuthCodeGrant
{
    public function completeAuthorizationRequest($authorizationRequest): ResponseTypeInterface
    {
        app(AuthorizationFlow::class)->beforeCode($authorizationRequest);

        return parent::completeAuthorizationRequest($authorizationRequest);
    }

    protected function decrypt($encryptedData): string
    {
        try {
            $json = parent::decrypt($encryptedData);
            $payload = app(AuthorizationContext::class)->decodeEnvelope($json, 'code');
        } catch (\Throwable $exception) {
            throw OAuthServerException::invalidGrant('Invalid authorization code.');
        }
        if (! is_array($payload) || (isset($payload['code_challenge']) && ($payload['code_challenge_method'] ?? null) !== 'S256')) {
            throw OAuthServerException::invalidGrant('Invalid authorization code.');
        }
        app(AuthorizationContext::class)->validateTokenPayload($payload);
        request()->attributes->set('oidc.code_payload', $payload);

        return $json;
    }

    protected function issueAccessToken(DateInterval $accessTokenTTL, ClientEntityInterface $client, $userIdentifier, array $scopes = []): AccessTokenEntityInterface
    {
        // Native client, callback, expiry and PKCE validation have completed here.
        $payload = request()->attributes->get('oidc.code_payload');
        if (! is_array($payload)) {
            throw OAuthServerException::invalidGrant('Missing authorization code context.');
        }
        app(TokenConsumption::class)->consume('code', $payload);

        return parent::issueAccessToken($accessTokenTTL, $client, $userIdentifier, $scopes);
    }

    // The untyped parameter supports League 8 (Passport 12) and League 9.
    protected function encrypt($unencryptedData): string
    {
        $payload = json_decode($unencryptedData, true, 512, JSON_THROW_ON_ERROR);
        $context = request()->attributes->get(AuthorizationContext::ATTRIBUTE);
        $payload['oidc'] = $context;
        app(TokenConsumption::class)->register('code', $payload);

        return parent::encrypt(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
