<?php

namespace Admin9\OidcServer\Tests;

use Admin9\OidcServer\OidcServerServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Passport\PassportServiceProvider::class,
            OidcServerServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://example.com');
        $app['config']->set('oidc.issuer', 'https://example.com');
    }
}
