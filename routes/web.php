<?php

use Admin9\OidcServer\Http\Controllers\OidcController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

/*
|--------------------------------------------------------------------------
| OIDC Server Routes
|--------------------------------------------------------------------------
*/

$discoveryMiddleware = config('oidc-server.routes.discovery_middleware', []);
$tokenMiddleware = config('oidc-server.routes.token_middleware', []);
$userinfoMiddleware = config('oidc-server.routes.userinfo_middleware', ['auth:api']);

// Discovery & JWKS (public)
Route::middleware($discoveryMiddleware)->group(function () {
    Route::get('.well-known/openid-configuration', [OidcController::class, 'discovery'])->name('oidc.discovery');
    Route::get('.well-known/jwks.json', [OidcController::class, 'jwks'])->name('oidc.jwks');
});

// Passport Authorization (requires web + auth middleware from Passport)
Route::middleware($discoveryMiddleware)->group(function () {
    Route::get('oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');
    Route::post('oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->name('passport.authorizations.approve');
    Route::delete('oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
});

// UserInfo (protected by auth:api)
Route::middleware($userinfoMiddleware)->group(function () {
    Route::match(['get', 'post'], 'oauth/userinfo', [OidcController::class, 'userinfo'])->name('oidc.userinfo');
});

// Token, Introspect, Revoke, Logout
Route::middleware($tokenMiddleware)->group(function () {
    Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])->name('passport.token');
    Route::post('oauth/introspect', [OidcController::class, 'introspect'])->name('oidc.introspect');
    Route::post('oauth/revoke', [OidcController::class, 'revoke'])->name('oidc.revoke');
    Route::get('oauth/logout', [OidcController::class, 'logout'])->name('oidc.logout');
});
