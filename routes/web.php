<?php

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

$discoveryMiddleware = config('oidc-server.routes.discovery_middleware', []);

// Passport Authorization (user-facing, requires session)
Route::middleware($discoveryMiddleware)->group(function () {
    Route::get('oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');
    Route::post('oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->name('passport.authorizations.approve');
    Route::delete('oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
});

// Logout (user-facing, requires session)
Route::get('oauth/logout', [OidcController::class, 'logout'])->name('oidc.logout');
