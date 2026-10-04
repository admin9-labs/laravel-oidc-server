<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;

class AuthorizationMiddlewareTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.member_web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('passport.guard', 'member_web');
        $app['config']->set('oidc-server.routes.authorization_middleware', ['auth:member_web']);
    }

    public function test_native_auth_middleware_does_not_intercept_package_authorization(): void
    {
        $this->artisan('passport:keys', ['--force' => true]);

        $this->getJson('/oauth/authorize')->assertStatus(400);
        $this->postJson('/oauth/authorize')->assertStatus(400);
        $this->deleteJson('/oauth/authorize')->assertStatus(400);
        $this->getJson('/.well-known/openid-configuration')->assertOk();
        $this->getJson('/.well-known/jwks.json')->assertOk()->assertJsonStructure(['keys']);
        $this->get('/oauth/logout')->assertOk();
    }
}
