<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Integration;

class Admin extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'admins';
    protected $guarded = [];
}
