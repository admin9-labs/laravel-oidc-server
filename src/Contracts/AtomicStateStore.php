<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Contracts;

interface AtomicStateStore
{
    public function create(string $key, string $value, int $seconds): bool;

    public function read(string $key): ?string;

    /** Missing state must fail; it must never be recreated by this operation. */
    public function replace(string $key, string $expected, string $value, int $seconds): bool;
}
