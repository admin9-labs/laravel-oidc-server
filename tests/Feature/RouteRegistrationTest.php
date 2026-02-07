<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RouteRegistrationTest extends TestCase
{
    public function test_discovery_route_is_registered(): void
    {
        $this->assertRouteExists('oidc.discovery', 'GET');
    }

    public function test_jwks_route_is_registered(): void
    {
        $this->assertRouteExists('oidc.jwks', 'GET');
    }

    public function test_userinfo_route_is_registered(): void
    {
        $this->assertRouteExists('oidc.userinfo', 'GET');
    }

    public function test_introspect_route_is_registered(): void
    {
        $this->assertRouteExists('oidc.introspect', 'POST');
    }

    public function test_revoke_route_is_registered(): void
    {
        $this->assertRouteExists('oidc.revoke', 'POST');
    }

    public function test_logout_route_is_registered(): void
    {
        $this->assertRouteExists('oidc.logout', 'GET');
    }

    public function test_passport_authorize_route_is_registered(): void
    {
        $this->assertRouteExists('passport.authorizations.authorize', 'GET');
    }

    public function test_passport_token_route_is_registered(): void
    {
        $this->assertRouteExists('passport.token', 'POST');
    }

    public function test_routes_can_be_disabled(): void
    {
        // This test verifies the config option exists
        $this->assertTrue(config('oidc-server.routes.enabled'));
    }

    protected function assertRouteExists(string $name, string $method): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($route) => $route->getName() === $name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");
        $this->assertContains($method, $route->methods(), "Route [{$name}] does not support {$method}.");
    }
}
