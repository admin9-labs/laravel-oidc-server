<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\AtomicStateStore;
use League\OAuth2\Server\Exception\OAuthServerException;

class TokenConsumption
{
    public function __construct(private AtomicStateStore $atomic) {}

    public function register(string $kind, array $payload): void
    {
        if (! $this->atomic->create($this->key($kind, $payload), $this->fingerprint($payload), $this->lifetime($payload))) {
            throw OAuthServerException::serverError('Could not register one-time token state.');
        }
    }

    public function consume(string $kind, array $payload): void
    {
        if (! $this->atomic->replace($this->key($kind, $payload), $this->fingerprint($payload), 'consumed', $this->lifetime($payload))) {
            throw OAuthServerException::invalidGrant('Token state is missing or already consumed. Restart authorization.');
        }
    }

    public function isAvailable(string $kind, array $payload): bool
    {
        return $this->atomic->read($this->key($kind, $payload)) === $this->fingerprint($payload);
    }

    private function key(string $kind, array $payload): string
    {
        return $kind.':'.hash('sha256', (string) $payload[$kind === 'code' ? 'auth_code_id' : 'refresh_token_id']);
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function lifetime(array $payload): int
    {
        return max(1, $payload['expire_time'] - time() + 60);
    }
}
