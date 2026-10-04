<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Integration;

use Admin9\OidcServer\OidcServerServiceProvider;
use Orchestra\Testbench\Foundation\Application;

/** Real HTTP fixture, isolated from the application's and user's databases. */
class HostApplication extends Application
{
    protected function getPackageProviders($app): array
    {
        return [\Laravel\Passport\PassportServiceProvider::class, OidcServerServiceProvider::class, HostProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $runtime = getenv('OIDC_TEST_RUNTIME');
        if (! is_string($runtime) || ! is_file($runtime.'/settings.json')) {
            throw new \RuntimeException('Set OIDC_TEST_RUNTIME to an initialized integration fixture.');
        }
        $settings = json_decode(file_get_contents($runtime.'/settings.json'), true, 512, JSON_THROW_ON_ERROR);
        $app->useStoragePath($runtime.'/storage');
        $app['config']->set([
            'app.env' => 'local', 'app.debug' => false, 'app.key' => $settings['app_key'],
            'app.url' => $settings['issuer'], 'oidc-server.issuer' => $settings['issuer'],
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => $runtime.'/database.sqlite',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'database.connections.sqlite.busy_timeout' => 10000,
            'database.redis.client' => 'phpredis', 'database.redis.options.prefix' => 'oidc-integration:',
            'database.redis.default' => ['host' => $settings['redis_socket'], 'port' => 0, 'database' => 0],
            'cache.default' => 'redis', 'cache.stores.redis.connection' => 'default',
            'cache.stores.redis.lock_connection' => 'default',
            'session.driver' => 'redis', 'session.connection' => 'default', 'session.block_store' => 'redis',
            'session.cookie' => 'oidc_integration_session', 'session.secure' => false,
            'session.lifetime' => 120, 'view.compiled' => $runtime.'/storage/framework/views',
            'auth.defaults.guard' => 'member',
            'auth.providers.users' => ['driver' => 'eloquent', 'model' => Member::class],
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => Admin::class],
            'auth.guards.member' => ['driver' => 'session', 'provider' => 'users'],
            'auth.guards.admin' => ['driver' => 'session', 'provider' => 'admins'],
            'auth.guards.api' => ['driver' => 'passport', 'provider' => 'users'],
            'passport.guard' => 'member', 'oidc-server.user_model' => Member::class,
            'passport.private_key' => file_get_contents($runtime.'/private.pem'),
            'passport.public_key' => file_get_contents($runtime.'/public.pem'),
            'oidc_integration' => $settings,
        ]);
    }
}
