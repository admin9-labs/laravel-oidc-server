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

    public function test_authorization_can_require_login_without_protecting_metadata(): void
    {
        $this->artisan('passport:keys', ['--force' => true]);

        $this->getJson('/oauth/authorize')->assertUnauthorized();
        $this->postJson('/oauth/authorize')->assertUnauthorized();
        $this->deleteJson('/oauth/authorize')->assertUnauthorized();
        $this->getJson('/.well-known/openid-configuration')->assertOk();
        $this->getJson('/.well-known/jwks.json')->assertOk()->assertJsonStructure(['keys']);
        $this->get('/oauth/logout')->assertRedirect('/');
    }
}
