<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Bridge;

use Admin9\OidcServer\Services\AuthorizationContext;
use Admin9\OidcServer\Services\TokenConsumption;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

class RefreshTokenGrant extends \League\OAuth2\Server\Grant\RefreshTokenGrant
{
    protected function decrypt($encryptedData): string
    {
        $json = parent::decrypt($encryptedData);
        app(AuthorizationContext::class)->decodeEnvelope($json, 'refresh');

        return $json;
    }

    protected function validateOldRefreshToken(ServerRequestInterface $request, $clientId): array
    {
        $parameters = (array) $request->getParsedBody();
        $refreshToken = $parameters['refresh_token'] ?? null;
        if (! is_string($refreshToken) || trim($refreshToken) === '') {
            throw OAuthServerException::invalidRequest('refresh_token');
        }
        try {
            $payload = parent::validateOldRefreshToken($request, $clientId);
        } catch (OAuthServerException $exception) {
            // League 8 reports old refresh failures as invalid_request / 401.
            throw OAuthServerException::invalidGrant('Invalid refresh token.');
        }
        app(AuthorizationContext::class)->validateTokenPayload($payload);
        // This hook precedes the parent's scope checks. Reject a bad scope request
        // before consuming an otherwise valid refresh credential.
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, implode(' ', $payload['scopes'])));
        foreach ($scopes as $scope) {
            if (! in_array($scope->getIdentifier(), $payload['scopes'], true)) {
                throw OAuthServerException::invalidScope($scope->getIdentifier());
            }
        }
        app(TokenConsumption::class)->consume('refresh', $payload);

        return $payload;
    }
}
