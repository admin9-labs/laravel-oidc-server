<?php

namespace Admin9\OidcServer\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as BaseClient;

class OidcClient extends BaseClient
{
    /**
     * Determine if the client should skip the authorization prompt.
     *
     * First-party clients skip the authorization confirmation.
     *
     * @param  \Laravel\Passport\Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->firstParty();
    }
}
