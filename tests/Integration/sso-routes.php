<?php

declare(strict_types=1);

use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Admin9\OidcServer\Contracts\ReauthenticationService;
use Admin9\OidcServer\Tests\Integration\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

Route::middleware('web')->group(function (): void {
    // Test-only mode selects a deliberately silent upstream to exercise stale SSO rejection.
    Route::get('/sso/mode/{mode}', function (Request $request, string $mode) {
        abort_unless(in_array($mode, ['silent', 'fresh', 'password'], true), 400);
        $request->session()->put('fixture_sso_mode', $mode);

        return response()->json(['mode' => $mode]);
    })->block();

    Route::get('/sso/start', function (Request $request) {
        $transaction = $request->query('transaction');
        $challenge = $transaction === null ? null : app(ReauthenticationService::class)
            ->challenge($request, $transaction, $request->query('challenge'));
        $pending = ['state' => bin2hex(random_bytes(24)), 'nonce' => bin2hex(random_bytes(24)),
            'verifier' => bin2hex(random_bytes(32)), 'transaction' => $transaction,
            'challenge' => $challenge?->token, 'expires_at' => time() + 300];
        $request->session()->put('fixture_sso_pending', $pending);
        $parameters = ['client_id' => 'fixture-host', 'redirect_uri' => config('oidc_integration.issuer').'/sso/callback',
            'response_type' => 'code', 'scope' => 'openid', 'state' => $pending['state'], 'nonce' => $pending['nonce'],
            'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '=')];
        $parameters['prompt'] = $challenge !== null && $request->session()->get('fixture_sso_mode') === 'silent' ? 'none' : 'login';

        return redirect('http://127.0.0.1:18995/auth?'.http_build_query($parameters))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    })->block()->name('fixture.sso');

    Route::get('/sso/callback', function (Request $request) {
        // Correlation and one-time consumption precede exchange, guard login and recording.
        $pending = $request->session()->pull('fixture_sso_pending');
        abort_unless(is_array($pending) && $pending['expires_at'] > time()
            && is_string($request->query('state')) && hash_equals($pending['state'], $request->query('state')), 400);
        $service = app(ReauthenticationService::class);
        $challenge = $pending['transaction'] === null ? null
            : $service->challenge($request, $pending['transaction'], $pending['challenge']);
        $process = new Process(['node', __DIR__.'/sso-verify.mjs']);
        $process->setInput(json_encode(['callback' => $request->fullUrl()] + $pending, JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->run();
        if (! $process->isSuccessful()) {
            error_log($process->getErrorOutput());
        }
        abort_unless($process->isSuccessful(), 400, 'Upstream assertion verification failed.');
        $verified = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $user = Member::where('email', 'member@fixture.test')->firstOrFail(); // Fixed issuer/sub mapping in this fixture only.
        Auth::guard('member')->login($user);
        $request->session()->regenerate(true);
        $evidence = $verified + ['challenge_issued_at' => $challenge?->issuedAt, 'cryptographically_verified' => true];
        try {
            $record = app(AuthenticationRecorder::class)->markAuthenticated('member', $user,
                new DateTimeImmutable('@'.$verified['auth_time']), $challenge);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            file_put_contents(getenv('OIDC_TEST_RUNTIME').'/sso-events.jsonl', json_encode($evidence + ['accepted' => false], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            throw $exception;
        }
        file_put_contents(getenv('OIDC_TEST_RUNTIME').'/sso-events.jsonl', json_encode($evidence + ['accepted' => true], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        return $challenge === null ? response()->json(['sso_authenticated' => true, 'auth_time' => $record->authTime])
            : $service->complete($request, $challenge);
    })->block();
});
