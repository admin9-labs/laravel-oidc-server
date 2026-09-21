<?php

declare(strict_types=1);

use Admin9\OidcServer\Http\Controllers\OidcController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
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
    Route::get('oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');

    Route::middleware($guard ? 'auth:'.$guard : 'auth')->group(function () {
        Route::post('oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->name('passport.authorizations.approve');
        Route::delete('oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
    });
});

// Logout (user-facing, requires session)
Route::get('oauth/logout', [OidcController::class, 'logout'])->name('oidc.logout');
