<?php

declare(strict_types=1);

use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Admin9\OidcServer\Contracts\ReauthenticationService;
use Admin9\OidcServer\Tests\Integration\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::post('/fixture/hold-session', function (Request $request) {
        // A pre-rotation request saves its old snapshot after its session lock expires.
        usleep(12000000);
        $request->session()->put('fixture_late_write', true);

        return response()->json(['saved_old_snapshot' => true]);
    })->block();
    Route::get('/', fn () => view('fixture::status'));
    Route::get('/admin/login', fn () => view('fixture::login', ['admin' => true, 'challenge' => null]));
    Route::post('/admin/login', function (Request $request) {
        abort_unless(Auth::guard('admin')->attempt($request->only('email', 'password')), 401);
        $request->session()->regenerate(true);

        return redirect('/');
    })->middleware('throttle:30,1')->block();
    Route::get('/member/reauthenticate', function (Request $request) {
        $challenge = app(ReauthenticationService::class)->challenge($request, $request->query('transaction'), $request->query('challenge'));

        return response()->view('fixture::login', ['admin' => false, 'challenge' => $challenge])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    })->block()->name('fixture.reauthenticate');
    Route::post('/member/reauthenticate', function (Request $request) {
        $service = app(ReauthenticationService::class);
        $challenge = $service->challenge($request, $request->input('transaction'), $request->input('challenge'));
        $provider = Auth::guard($challenge->guard)->getProvider();
        $user = $provider->retrieveByCredentials($request->only('email', 'password'));
        abort_unless($user && $provider->validateCredentials($user, $request->only('password')), 401);
        // No guard login or recorder call until the second factor is verified.
        abort_unless(is_string($request->input('code')) && Totp::valid(config('oidc_integration.totp_secret'), $request->input('code')), 401);
        Auth::guard($challenge->guard)->login($user);
        $request->session()->regenerate(true);
        app(AuthenticationRecorder::class)->markAuthenticated($challenge->guard, $user, challenge: $challenge);

        $response = $service->complete($request, $challenge);
        \Admin9\OidcServer\Tests\Integration\Faults::hit('challenge-completed', $challenge->transactionId);

        return $response;
    })->middleware('throttle:30,1')->block();
});
