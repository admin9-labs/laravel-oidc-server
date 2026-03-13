<?php

use Admin9\OidcServer\Http\Controllers\OidcController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

/*
|--------------------------------------------------------------------------
| OIDC Server API Routes (no session / no CSRF)
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

// UserInfo (protected by Bearer token)
Route::middleware($userinfoMiddleware)->group(function () {
    Route::match(['get', 'post'], 'oauth/userinfo', [OidcController::class, 'userinfo'])->name('oidc.userinfo');
});

// Token, Introspect, Revoke (machine-to-machine)
Route::middleware($tokenMiddleware)->group(function () {
    Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])->name('passport.token');
    Route::post('oauth/introspect', [OidcController::class, 'introspect'])->name('oidc.introspect');
    Route::post('oauth/revoke', [OidcController::class, 'revoke'])->name('oidc.revoke');
});
