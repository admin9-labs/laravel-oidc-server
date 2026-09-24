<?php

declare(strict_types=1);

namespace Admin9\OidcServer;

use Admin9\OidcServer\Http\Controllers\AuthorizationController;
use Admin9\OidcServer\Http\Middleware\EnforceAuthorizationPolicy;
use Admin9\OidcServer\Models\OidcClient;
use Admin9\OidcServer\Models\Passport12OidcClient;
use Admin9\OidcServer\Services\ClaimsService;
use Admin9\OidcServer\Services\IdTokenService;
use Admin9\OidcServer\Services\TokenResponseType;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OidcServerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('oidc-server')
            ->hasConfigFile('oidc-server')
            ->hasViews('oidc-server');
    }

    public function packageRegistered(): void
    {
        // Registration must precede Passport's boot(), including a custom route prefix.
        if (config('oidc-server.ignore_passport_routes', true)) {
            Passport::ignoreRoutes();
        }
        $this->app->bind(\Laravel\Passport\Http\Controllers\AuthorizationController::class, AuthorizationController::class);
        $this->app->when(AuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard')));
        $this->app->singleton(ClaimsService::class);
        $this->app->singleton(IdTokenService::class);
        $this->app->singleton(TokenResponseType::class);
    }

    public function packageBooted(): void
    {
        if (config('oidc-server.configure_passport', true)) {
            $this->configurePassport();
        }

        if (config('oidc-server.routes.enabled', true)) {
            $this->registerRoutes();
        }

        // Explicitly retaining Passport routes must not create an unprotected alias.
        $this->app->booted(function (): void {
            $controllers = [
                \Laravel\Passport\Http\Controllers\AuthorizationController::class,
                \Laravel\Passport\Http\Controllers\ApproveAuthorizationController::class,
                \Laravel\Passport\Http\Controllers\DenyAuthorizationController::class,
                \Laravel\Passport\Http\Controllers\AccessTokenController::class,
            ];
            foreach (Route::getRoutes() as $route) {
                $controller = explode('@', $route->getActionName())[0];
                if (in_array($controller, $controllers, true)
                    && ! in_array(EnforceAuthorizationPolicy::class, $route->middleware(), true)) {
                    $route->middleware(EnforceAuthorizationPolicy::class);
                }
            }
        });
    }

    protected function configurePassport(): void
    {
        // Authorization view
        Passport::authorizationView(config('oidc-server.authorization_view', 'oidc-server::authorize'));

        // Client model
        $clientModel = config('oidc-server.client_model');
        if ($clientModel === OidcClient::class &&
            (new \ReflectionMethod(Client::class, 'skipsAuthorization'))->getNumberOfRequiredParameters() === 0) {
            $clientModel = Passport12OidcClient::class;
        }
        if ($clientModel) {
            Passport::useClientModel($clientModel);
        }

        // Scopes from config
        $scopes = [];
        foreach (config('oidc-server.scopes', []) as $key => $scope) {
            $scopes[$key] = $scope['description'] ?? $key;
        }
        Passport::tokensCan($scopes);

        // Default scopes
        Passport::setDefaultScope(config('oidc-server.default_scopes', ['openid']));

        // Token lifetimes
        Passport::tokensExpireIn(
            CarbonInterval::seconds(config('oidc-server.tokens.access_token_ttl', 900))
        );
        Passport::refreshTokensExpireIn(
            CarbonInterval::seconds(config('oidc-server.tokens.refresh_token_ttl', 604800))
        );
        Passport::personalAccessTokensExpireIn(
            CarbonInterval::seconds(config('oidc-server.tokens.access_token_ttl', 900))
        );

        // Custom token response type with id_token injection
        $tokenResponse = $this->app->make(TokenResponseType::class);
        Passport::$authorizationServerResponseType = $tokenResponse;
        $this->app->afterResolving(AuthorizationServer::class, function (AuthorizationServer $server): void {
            $grant = new \Admin9\OidcServer\Bridge\AuthCodeGrant(
                $this->app->make(\Laravel\Passport\Bridge\AuthCodeRepository::class),
                $this->app->make(\Laravel\Passport\Bridge\RefreshTokenRepository::class),
                new \DateInterval('PT10M')
            );
            $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
            $server->enableGrantType($grant, Passport::tokensExpireIn());
        });
    }

    protected function registerRoutes(): void
    {
        Route::middleware('web')
            ->group(__DIR__.'/../routes/web.php');

        Route::group([], __DIR__.'/../routes/api.php');
    }
}
