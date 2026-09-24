<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;

class ClaimsService
{
    /**
     * Resolve claims for a user based on granted scopes.
     *
     * @param  array<string>  $scopes
     * @return array<string, mixed>
     */
    public function resolveForUser(OidcUserInterface $user, array $scopes): array
    {
        $claims = [
            'sub' => $user->getOidcSubject(),
        ];

        $claims = array_merge($claims, $user->getOidcClaims($scopes));
        unset($claims['auth_time']);

        return $claims;
    }

    /**
     * Get all supported claims from config.
     *
     * @return array<string>
     */
    public function getSupportedClaims(): array
    {
        $claims = ['sub', 'iss', 'aud', 'exp', 'iat', 'nonce'];

        foreach (config('oidc-server.scopes') as $scope) {
            if (isset($scope['claims'])) {
                $claims = array_merge($claims, $scope['claims']);
            }
        }

        return array_values(array_diff(array_unique($claims), ['auth_time']));
    }
}
