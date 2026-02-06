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
        $scopeConfig = config('oidc.scopes');

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
        $resolvers = config('oidc.claims_resolver', []);
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

        // Default claim resolution
        return match ($claim) {
            'sub' => $this->getOidcSubject(),
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'updated_at' => $this->updated_at?->timestamp,
            default => null,
        };
    }
}
