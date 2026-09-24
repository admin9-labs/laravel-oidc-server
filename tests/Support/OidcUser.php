<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Support;

use Admin9\OidcServer\Concerns\HasOidcClaims;
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Illuminate\Foundation\Auth\User;
use Laravel\Passport\HasApiTokens;

class OidcUser extends User implements OidcUserInterface
{
    use HasApiTokens;
    use HasOidcClaims;

    protected $table = 'users';

    protected $guarded = [];

    public function getOidcSubject(): string
    {
        return 'user:'.$this->getKey();
    }
}
