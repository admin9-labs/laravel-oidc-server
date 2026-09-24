<?php

declare(strict_types=1);

use Admin9\OidcServer\Http\Controllers\OidcController;
use Admin9\OidcServer\Http\Controllers\AuthorizationController;
use Admin9\OidcServer\Http\Middleware\EnforceAuthorizationPolicy;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

/*
|--------------------------------------------------------------------------
| OIDC Server Web Routes (require session / CSRF)
|--------------------------------------------------------------------------
*/

$authorizationMiddleware = config('oidc-server.routes.authorization_middleware', []);
$guard = config('passport.guard');

// Passport Authorization (user-facing, requires session)
Route::middleware($authorizationMiddleware)->group(function () use ($guard) {
    Route::get('oauth/authorize', [AuthorizationController::class, 'authorize'])
        ->middleware(EnforceAuthorizationPolicy::class)->name('passport.authorizations.authorize');

    Route::middleware([$guard ? 'auth:'.$guard : 'auth', EnforceAuthorizationPolicy::class])->group(function () {
        Route::post('oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->name('passport.authorizations.approve');
        Route::delete('oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
    });
});

// Protocol requests may originate at an RP without an OP CSRF token.
// They can only log out directly with a verified hint for the current user.
Route::match(['GET', 'POST'], 'oauth/logout', [OidcController::class, 'logout'])
    ->withoutMiddleware(array_filter([
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        class_exists(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ? \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class : null,
    ]))->name('oidc.logout');
Route::post('oauth/logout/confirm', [OidcController::class, 'confirmLogout'])->name('oidc.logout.confirm');
