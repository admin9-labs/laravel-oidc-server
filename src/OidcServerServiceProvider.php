<?php

namespace Admin9\OidcServer;

use Admin9\OidcServer\Services\ClaimsService;
use Admin9\OidcServer\Services\IdTokenService;
use Admin9\OidcServer\Services\TokenResponseType;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OidcServerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('oidc-server')
            ->hasConfigFile('oidc')
            ->hasViews('oidc-server');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ClaimsService::class);
        $this->app->singleton(IdTokenService::class);
        $this->app->singleton(TokenResponseType::class);
    }

    public function packageBooted(): void
    {
        // Prevent Passport from registering its own routes
        if (config('oidc.ignore_passport_routes', true)) {
            Passport::ignoreRoutes();
        }

        if (config('oidc.configure_passport', true)) {
            $this->configurePassport();
        }

        if (config('oidc.routes.enabled', true)) {
            $this->registerRoutes();
        }
    }

    protected function configurePassport(): void
    {
        // Authorization view
        Passport::authorizationView(config('oidc.authorization_view', 'oidc-server::authorize'));

        // Client model
        $clientModel = config('oidc.client_model');
        if ($clientModel) {
            Passport::useClientModel($clientModel);
        }

        // Scopes from config
        $scopes = [];
        foreach (config('oidc.scopes', []) as $key => $scope) {
            $scopes[$key] = $scope['description'] ?? $key;
        }
        Passport::tokensCan($scopes);

        // Default scopes
        Passport::defaultScopes(config('oidc.default_scopes', ['openid']));

        // Token lifetimes
        Passport::tokensExpireIn(
            CarbonInterval::seconds(config('oidc.tokens.access_token_ttl', 900))
        );
        Passport::refreshTokensExpireIn(
            CarbonInterval::seconds(config('oidc.tokens.refresh_token_ttl', 604800))
        );
        Passport::personalAccessTokensExpireIn(
            CarbonInterval::seconds(config('oidc.tokens.access_token_ttl', 900))
        );

        // Custom token response type with id_token injection
        $tokenResponse = $this->app->make(TokenResponseType::class);
        Passport::useAuthorizationServerResponseType($tokenResponse);
    }

    protected function registerRoutes(): void
    {
        Route::middleware('web')
            ->group(__DIR__.'/../routes/web.php');
    }
}
