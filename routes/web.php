<?php

declare(strict_types=1);

use Admin9\OidcServer\Http\Controllers\OidcController;
use Admin9\OidcServer\Http\Controllers\AuthorizationController;
use Admin9\OidcServer\Http\Middleware\EnforceAuthorizationPolicy;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OIDC Server Web Routes (require session / CSRF)
|--------------------------------------------------------------------------
*/

$authorizationMiddleware = config('oidc-server.routes.authorization_middleware', []);
$authorizationMiddleware = array_values(array_filter($authorizationMiddleware, fn ($middleware): bool =>
    $middleware !== 'auth' && ! str_starts_with($middleware, 'auth:') && $middleware !== \Illuminate\Auth\Middleware\Authenticate::class));

Route::middleware(array_merge($authorizationMiddleware, [EnforceAuthorizationPolicy::class]))->group(function () {
    Route::get('oauth/authorize', [AuthorizationController::class, 'authorize'])->block()->name('passport.authorizations.authorize');
    Route::post('oauth/authorize', [AuthorizationController::class, 'approve'])->block()->name('passport.authorizations.approve');
    Route::delete('oauth/authorize', [AuthorizationController::class, 'deny'])->block()->name('passport.authorizations.deny');
    Route::get('oauth/authorize/continue', [AuthorizationController::class, 'resume'])->block()->name('oidc.authorizations.continue');
});

// Protocol requests may originate at an RP without an OP CSRF token.
// They can only log out directly with a verified hint for the current user.
Route::match(['GET', 'POST'], 'oauth/logout', [OidcController::class, 'logout'])
    ->withoutMiddleware(array_filter([
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        class_exists(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
            ? \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class : null,
    ]))->block()->name('oidc.logout');
Route::post('oauth/logout/confirm', [OidcController::class, 'confirmLogout'])->block()->name('oidc.logout.confirm');
