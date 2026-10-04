<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Admin9\OidcServer\Contracts\ReauthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionReauthenticationService implements ReauthenticationService
{
    public function __construct(private AuthorizationTransactions $transactions) {}

    public function challenge(Request $request, string $transactionId, string $challengeToken): ReauthenticationChallenge
    {
        return $this->transactions->challenge($request, $transactionId, $challengeToken);
    }

    public function complete(Request $request, ReauthenticationChallenge $challenge): RedirectResponse
    {
        app(AuthorizationFlow::class)->registration($this->transactions->get($request, $challenge->transactionId));
        $resume = $this->transactions->complete($request, $challenge);

        return redirect()->route('oidc.authorizations.continue', ['transaction' => $challenge->transactionId, 'resume' => $resume])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function cancel(Request $request, ReauthenticationChallenge $challenge): RedirectResponse
    {
        $this->challenge($request, $challenge->transactionId, $challenge->token);
        $tx = $this->transactions->get($request, $challenge->transactionId);
        $flow = app(AuthorizationFlow::class);
        $flow->registration($tx);
        $this->transactions->finish($request, $challenge->transactionId, 'failed');

        return $flow->error($tx['authorization'], 'access_denied');
    }
}
