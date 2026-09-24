<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class IdTokenService
{
    protected ?Configuration $jwtConfig = null;

    protected ClaimsService $claimsService;

    public function __construct(ClaimsService $claimsService)
    {
        $this->claimsService = $claimsService;
    }

    protected function getJwtConfig(): Configuration
    {
        if ($this->jwtConfig === null) {
            $this->jwtConfig = Configuration::forAsymmetricSigner(
                new Sha256,
                app(PassportKeys::class)->key('private'),
                app(PassportKeys::class)->key('public')
            );
        }

        return $this->jwtConfig;
    }

    /**
     * Generate an ID Token for the given access token and user.
     */
    public function generateToken(
        AccessTokenEntityInterface $accessToken,
        OidcUserInterface $user,
        ClientEntityInterface $client,
        ?string $nonce = null
    ): string {
        $issuer = config('oidc-server.issuer', config('app.url'));
        $now = new \DateTimeImmutable;
        $jwtConfig = $this->getJwtConfig();

        $builder = $jwtConfig->builder()
            ->issuedBy($issuer)
            ->permittedFor($client->getIdentifier())
            ->issuedAt($now)
            ->expiresAt($accessToken->getExpiryDateTime())
            ->relatedTo($user->getOidcSubject());

        if ($nonce !== null) {
            $builder = $builder->withClaim('nonce', $nonce);
        }

        // Add claims based on scopes
        $scopes = array_map(function (ScopeEntityInterface $scope) {
            return $scope->getIdentifier();
        }, $accessToken->getScopes());

        $claims = $this->claimsService->resolveForUser($user, $scopes);
        foreach ($claims as $key => $value) {
            if (! in_array($key, ['sub', 'iss', 'aud', 'exp', 'iat', 'nbf', 'jti', 'nonce', 'auth_time'], true)) {
                $builder = $builder->withClaim($key, $value);
            }
        }

        return $builder->getToken(
            $jwtConfig->signer(),
            $jwtConfig->signingKey()
        )->toString();
    }
}
