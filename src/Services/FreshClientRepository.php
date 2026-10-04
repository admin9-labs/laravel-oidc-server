<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Laravel\Passport\Client;
use Laravel\Passport\Passport;

class FreshClientRepository extends \Laravel\Passport\ClientRepository
{
    // Passport 13 memoizes find(); each freshness checkpoint must see persisted changes.
    public function find($id): ?Client
    {
        return Passport::client()->newQuery()->find($id);
    }
}
