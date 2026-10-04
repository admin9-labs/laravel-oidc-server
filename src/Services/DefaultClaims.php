<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

class DefaultClaims
{
    public static function emailVerified(object $user): bool
    {
        return $user->email_verified_at !== null;
    }

    public static function updatedAt(object $user): ?int
    {
        return $user->updated_at?->timestamp;
    }
}
