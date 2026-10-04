<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Support;

use Admin9\OidcServer\Services\PassportKeys;
use DateInterval;
use Laravel\Passport\Bridge;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Exercises upstream extension points without depending on the package's HTTP policy. */
class FreshnessPrototype
{
    public static function server(bool $protected = true, ?string $encryptionKey = null): AuthorizationServer
    {
        $server = new AuthorizationServer(
            app(Bridge\ClientRepository::class),
            app(Bridge\AccessTokenRepository::class),
            app(Bridge\ScopeRepository::class),
            config('passport.private_key'),
            $encryptionKey ?? app(PassportKeys::class)->encryptionKey(),
            new BearerTokenResponse,
        );
        $code = $protected
            ? new class(app(Bridge\AuthCodeRepository::class), app(Bridge\RefreshTokenRepository::class), new DateInterval('PT10M')) extends AuthCodeGrant
            {
                // Contravariant parameters cover League 8's concrete request and 9's interface.
                public function completeAuthorizationRequest($authorizationRequest): ResponseTypeInterface
                {
                    if (! request()->attributes->get('prototype.approved')) {
                        throw OAuthServerException::invalidGrant('Missing final authorization proof.');
                    }

                    return parent::completeAuthorizationRequest($authorizationRequest);
                }

                protected function decrypt($encryptedData): string
                {
                    $json = parent::decrypt($encryptedData);
                    FreshnessPrototype::validate(json_decode($json, true));

                    return $json;
                }

                protected function encrypt($unencryptedData): string
                {
                    $payload = json_decode($unencryptedData, true);
                    $payload['oidc'] = request()->attributes->get('prototype.context');

                    return parent::encrypt(json_encode($payload, JSON_THROW_ON_ERROR));
                }
            }
            : new AuthCodeGrant(app(Bridge\AuthCodeRepository::class), app(Bridge\RefreshTokenRepository::class), new DateInterval('PT10M'));
        $refresh = $protected
            ? new class(app(Bridge\RefreshTokenRepository::class)) extends RefreshTokenGrant
            {
                protected function validateOldRefreshToken(ServerRequestInterface $request, $clientId): array
                {
                    $payload = parent::validateOldRefreshToken($request, $clientId);
                    FreshnessPrototype::validate($payload);

                    return $payload;
                }
            }
            : new RefreshTokenGrant(app(Bridge\RefreshTokenRepository::class));
        $code->setRefreshTokenTTL(new DateInterval('P1D'));
        $refresh->setRefreshTokenTTL(new DateInterval('P1D'));
        $server->enableGrantType($code, new DateInterval('PT15M'));
        $server->enableGrantType($refresh, new DateInterval('PT15M'));

        return $server;
    }

    public static function validate(array $payload): void
    {
        if (($payload['oidc']['v'] ?? null) !== 3
            || ! is_int($payload['oidc']['authentication']['auth_time'] ?? null)
            || ! is_string($payload['oidc']['authentication']['generation'] ?? null)) {
            throw OAuthServerException::invalidGrant('Missing authentication context.');
        }
    }
}
