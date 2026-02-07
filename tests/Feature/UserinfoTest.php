<?php

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;

class UserinfoTest extends TestCase
{
    public function test_userinfo_returns_401_without_auth(): void
    {
        // UserInfo requires auth:api middleware by default
        // Without a valid token, it should return 401
        $response = $this->getJson('/oauth/userinfo');

        $response->assertStatus(401);
    }

    public function test_userinfo_route_exists(): void
    {
        // Verify the route is registered
        $this->assertTrue(
            collect(\Illuminate\Support\Facades\Route::getRoutes())
                ->contains(fn ($route) => $route->getName() === 'oidc.userinfo')
        );
    }

    public function test_userinfo_supports_get_and_post(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes());
        $userinfoRoute = $routes->first(fn ($route) => $route->getName() === 'oidc.userinfo');

        $this->assertNotNull($userinfoRoute);
        $this->assertContains('GET', $userinfoRoute->methods());
        $this->assertContains('POST', $userinfoRoute->methods());
    }
}
