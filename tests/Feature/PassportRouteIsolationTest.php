<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\PassportTestCase;
use Laravel\Passport\Passport;

class PassportRouteIsolationTest extends PassportTestCase
{
    protected function setUp(): void
    {
        Passport::$registersRoutes = true;
        parent::setUp();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('passport.path', 'legacy');
    }

    public function test_default_ignore_routes_removes_custom_prefix_authorization_and_token_aliases(): void
    {
        $this->get('/legacy/authorize')->assertNotFound();
        $this->postJson('/legacy/token', ['grant_type' => 'authorization_code'])->assertNotFound();
        $this->issueTokens($this->client());
    }
}
