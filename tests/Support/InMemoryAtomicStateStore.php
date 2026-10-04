<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Support;

use Admin9\OidcServer\Contracts\AtomicStateStore;

/** Only for deterministic package tests; concurrent acceptance uses real Redis. */
class InMemoryAtomicStateStore implements AtomicStateStore
{
    public array $values = [];

    public function create(string $key, string $value, int $seconds): bool
    {
        if ($this->read($key) !== null) {
            return false;
        }
        $this->values[$key] = [$value, now()->timestamp + $seconds];

        return true;
    }

    public function read(string $key): ?string
    {
        return ($this->values[$key][1] ?? 0) > now()->timestamp ? $this->values[$key][0] : null;
    }

    public function replace(string $key, string $expected, string $value, int $seconds): bool
    {
        if ($this->read($key) !== $expected) {
            return false;
        }
        $this->values[$key] = [$value, now()->timestamp + $seconds];

        return true;
    }
}
