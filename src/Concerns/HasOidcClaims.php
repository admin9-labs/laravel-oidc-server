<?php

namespace Admin9\OidcServer\Concerns;

trait HasOidcClaims
{
    /**
     * Get the OIDC subject identifier for this user.
     */
    public function getOidcSubject(): string
    {
        return (string) $this->getKey();
    }

    /**
     * Get OIDC claims for the given scopes.
     *
     * @param  array<string>  $scopes
     * @return array<string, mixed>
     */
    public function getOidcClaims(array $scopes): array
    {
        $claims = [];
        $scopeConfig = config('oidc-server.scopes');

        foreach ($scopes as $scope) {
            if (! isset($scopeConfig[$scope])) {
                continue;
            }

            foreach ($scopeConfig[$scope]['claims'] as $claim) {
                $value = $this->resolveOidcClaim($claim);
                if ($value !== null) {
                    $claims[$claim] = $value;
                }
            }
        }

        return $claims;
    }

    /**
     * Resolve a single OIDC claim value.
     *
     * Override this method in your User model to handle custom claims.
     */
    protected function resolveOidcClaim(string $claim): mixed
    {
        // Check config-based claims_resolver first
        $resolvers = config('oidc-server.claims_resolver', []);
        if (isset($resolvers[$claim])) {
            $resolver = $resolvers[$claim];

            if (is_callable($resolver)) {
                return $resolver($this, $claim);
            }

            // String = model attribute name
            if (is_string($resolver)) {
                return $this->{$resolver};
            }
        }

        // Default claim resolution via config map
        if ($claim === 'sub') {
            return $this->getOidcSubject();
        }

        $defaultMap = config('oidc-server.default_claims_map', []);
        if (isset($defaultMap[$claim])) {
            $mapper = $defaultMap[$claim];

            if (is_callable($mapper)) {
                return $mapper($this);
            }

            // String = model attribute name
            if (is_string($mapper)) {
                return $this->{$mapper};
            }
        }

        return null;
    }
}
