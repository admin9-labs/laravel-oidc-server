<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Bridge\AuthCodeGrant;
use Admin9\OidcServer\Bridge\RefreshTokenGrant;
use Laravel\Passport\Bridge;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;

class OidcAuthorizationServer extends AuthorizationServer
{
    public function __construct(PassportKeys $keys, TokenResponseType $response)
    {
        $clients = app(FreshClientRepository::class);
        parent::__construct(app()->makeWith(Bridge\ClientRepository::class, ['clients' => $clients]), app(Bridge\AccessTokenRepository::class),
            app()->makeWith(Bridge\ScopeRepository::class, ['clients' => $clients]), new CryptKey($keys->key('private')->contents(), null, false),
            $keys->encryptionKey(), $response);
        $this->setDefaultScope(Passport::$defaultScope);
        // Every refresh is one-time, regardless of the host's native OAuth setting.
        $this->revokeRefreshTokens(true);
        $code = new AuthCodeGrant(app(Bridge\AuthCodeRepository::class), app(Bridge\RefreshTokenRepository::class), new \DateInterval('PT10M'));
        $refresh = new RefreshTokenGrant(app(Bridge\RefreshTokenRepository::class));
        foreach ([$code, $refresh] as $grant) {
            $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
            $this->enableGrantType($grant, Passport::tokensExpireIn());
        }
        $clientCredentialsTTL = method_exists(Passport::class, 'clientCredentialsTokensExpireIn')
            ? Passport::clientCredentialsTokensExpireIn() : null;
        $this->enableGrantType(new ClientCredentialsGrant, $clientCredentialsTTL ?? Passport::tokensExpireIn());
    }
}
