<?php

namespace Admin9\OidcServer\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OidcUserInfoRequested
{
    use Dispatchable;

    public function __construct(
        public readonly string|int $userId,
        public readonly array $scopes,
    ) {}
}
