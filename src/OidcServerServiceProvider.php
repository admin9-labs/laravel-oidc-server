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
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
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
        $this->app->bind(\Admin9\OidcServer\Contracts\AtomicStateStore::class, \Admin9\OidcServer\Services\RedisAtomicStateStore::class);
        $this->app->bind(\Admin9\OidcServer\Contracts\AuthenticationRecorder::class, \Admin9\OidcServer\Services\SessionAuthenticationRecorder::class);
        // Registration must precede Passport's boot(), including a custom route prefix.
        if (config('oidc-server.ignore_passport_routes', true)) {
            Passport::ignoreRoutes();
        }
        $this->app->bind(\Admin9\OidcServer\Contracts\ReauthenticationService::class, \Admin9\OidcServer\Services\SessionReauthenticationService::class);
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

        // Retained Passport URLs are aliases of the package's controllers and policy.
        $this->app->booted(function (): void {
            $controllers = [
                \Laravel\Passport\Http\Controllers\AuthorizationController::class => AuthorizationController::class,
                \Laravel\Passport\Http\Controllers\ApproveAuthorizationController::class => AuthorizationController::class,
                \Laravel\Passport\Http\Controllers\DenyAuthorizationController::class => AuthorizationController::class,
                \Laravel\Passport\Http\Controllers\AccessTokenController::class => \Admin9\OidcServer\Http\Controllers\AccessTokenController::class,
            ];
            foreach (Route::getRoutes() as $route) {
                [$controller, $method] = array_pad(explode('@', $route->getActionName()), 2, null);
                if (isset($controllers[$controller])) {
                    $action = $route->getAction();
                    $action['uses'] = $action['controller'] = $controllers[$controller].'@'.$method;
                    $route->setAction($action);
                    $route->middleware(EnforceAuthorizationPolicy::class);
                    if ($method !== 'issueToken') {
                        // Package orchestration owns guest and reauthentication behavior.
                        $route->withoutMiddleware(array_filter(['auth', 'auth:'.config('passport.guard')]));
                        $route->block();
                    }
                }
            }
        });
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event): void {
            if (request()->hasSession()) {
                app(\Admin9\OidcServer\Contracts\AuthenticationRecorder::class)->forget($event->guard);
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
    }

    protected function registerRoutes(): void
    {
        Route::middleware('web')
            ->group(__DIR__.'/../routes/web.php');

        Route::group([], __DIR__.'/../routes/api.php');
    }
}
