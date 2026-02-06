<?php

namespace Admin9\OidcServer\Events;

use Illuminate\Foundation\Events\Dispatchable;

class OidcTokenIssued
{
    use Dispatchable;

    public function __construct(
        public readonly string|int $userId,
        public readonly string $clientId,
        public readonly array $scopes,
    ) {}
}
