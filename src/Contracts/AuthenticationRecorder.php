<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Contracts;

use Admin9\OidcServer\Authentication\AuthenticationRecord;
use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

interface AuthenticationRecorder
{
    public function markAuthenticated(string $guard, Authenticatable $user, ?DateTimeImmutable $authenticatedAt = null, ?ReauthenticationChallenge $challenge = null): AuthenticationRecord;

    public function current(string $guard): ?AuthenticationRecord;

    public function forget(string $guard): void;
}
