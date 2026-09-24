<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client as BaseClient;

class OidcClient extends BaseClient
{
    /**
     * Determine if the client should skip the authorization prompt.
     *
     * Client ownership is not proof that the user has consented.
     *
     * @param  \Laravel\Passport\Scope[]  $scopes
     */
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return false;
    }
}
