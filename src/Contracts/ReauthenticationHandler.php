<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Contracts;

use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

interface ReauthenticationHandler
{
    public function redirect(Request $request, ReauthenticationChallenge $challenge): RedirectResponse;
}
