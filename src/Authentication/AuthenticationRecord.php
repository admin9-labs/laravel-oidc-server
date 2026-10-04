<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Authentication;

final readonly class AuthenticationRecord
{
    public function __construct(
        public string $guard,
        public string $model,
        public string $userId,
        public int $authTime,
        public string $generation,
    ) {}

    public function identity(): array
    {
        return [$this->guard, $this->model, $this->userId];
    }

    public function toArray(): array
    {
        return ['guard' => $this->guard, 'model' => $this->model, 'user_id' => $this->userId,
            'auth_time' => $this->authTime, 'generation' => $this->generation];
    }

    public static function fromArray(array $record): self
    {
        return new self($record['guard'], $record['model'], $record['user_id'], $record['auth_time'], $record['generation']);
    }
}
