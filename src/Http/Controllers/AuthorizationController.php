<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Http\Controllers;

use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;

class AuthorizationController extends PassportAuthorizationController
{
    // Existing tokens cannot distinguish explicit consent from the old automatic grants.
    // Keep both hooks compatible with Passport 12 and 13; default to fresh confirmation.
    protected function hasGrantedScopes($user, $client, $scopes): bool
    {
        return false;
    }

    protected function hasValidToken($tokens, $user, $client, $scopes): bool
    {
        return false;
    }
}
