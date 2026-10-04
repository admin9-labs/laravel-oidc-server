<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Contracts;

use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

interface ReauthenticationService
{
    public function challenge(Request $request, string $transactionId, string $challengeToken): ReauthenticationChallenge;

    public function complete(Request $request, ReauthenticationChallenge $challenge): RedirectResponse;

    public function cancel(Request $request, ReauthenticationChallenge $challenge): RedirectResponse;
}
