<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Models;

use Laravel\Passport\Client;

class Passport12OidcClient extends Client
{
    public function skipsAuthorization(): bool
    {
        return $this->firstParty();
    }
}
