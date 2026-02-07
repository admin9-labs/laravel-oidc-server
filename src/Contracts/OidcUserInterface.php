<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Contracts;

interface OidcUserInterface
{
    /**
     * Get the OIDC subject identifier for this user.
     */
    public function getOidcSubject(): string;

    /**
     * Get OIDC claims for the given scopes.
     *
     * @param  array<string>  $scopes
     * @return array<string, mixed>
     */
    public function getOidcClaims(array $scopes): array;
}
