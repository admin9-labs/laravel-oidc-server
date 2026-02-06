<?php

namespace Admin9\OidcServer\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OidcLogoutInitiated
{
    use Dispatchable;

    public function __construct(
        public readonly string|int|null $userId = null,
        public readonly ?string $clientId = null,
    ) {}
}
