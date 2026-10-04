<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Authentication;

/** A value locator; all authority is reloaded from the server-side transaction. */
final readonly class ReauthenticationChallenge
{
    public function __construct(
        public string $transactionId,
        public string $token,
        public string $guard,
        public ?array $expectedIdentity,
        public int $issuedAt,
        public int $expiresAt,
    ) {}
}
