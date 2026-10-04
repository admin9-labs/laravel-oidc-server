<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\OidcAuthorizationServer;
use Admin9\OidcServer\Services\PassportKeys;
use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\PassportTestCase;
use Defuse\Crypto\Crypto;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;

class ManualPassportConfigurationTest extends PassportTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('oidc-server.configure_passport', false);
        $app->booting(function (): void {
            Passport::tokensCan(['openid' => 'OpenID', 'profile' => 'Profile', 'email' => 'Email']);
            Passport::setDefaultScope(['openid']);
            Passport::tokensExpireIn(now()->addMinutes(15));
            Passport::refreshTokensExpireIn(now()->addDay());
        });
    }

    public function test_manual_configuration_retains_protected_grants_and_token_response(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $claims = app(TokenVerifier::class)->signedJwt($tokens['id_token'])->claims();
        $this->assertTrue($claims->has('auth_time'));
        $payload = app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']);
        unset($payload['oidc']);
        $old = Crypto::encryptWithPassword(json_encode($payload), app(PassportKeys::class)->encryptionKey());
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $old])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(1, Passport::token()->count());
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
    }

    #[DataProvider('clientCredentialsLifetimes')]
    public function test_client_credentials_honors_its_lifetime_and_default_fallback(?int $seconds): void
    {
        $supportsDedicatedLifetime = method_exists(Passport::class, 'clientCredentialsTokensExpireIn');
        if ($seconds !== null && ! $supportsDedicatedLifetime) {
            $this->markTestSkipped('This Passport version has no dedicated client credentials lifetime.');
        }
        $original = $supportsDedicatedLifetime ? Passport::$clientCredentialsTokensExpireIn : null;
        try {
            if ($supportsDedicatedLifetime) {
                Passport::$clientCredentialsTokensExpireIn = $seconds === null ? null : new \DateInterval('PT'.$seconds.'S');
            }
            $repository = app(ClientRepository::class);
            $client = method_exists($repository, 'createClientCredentialsGrantClient')
                ? $repository->createClientCredentialsGrantClient('Service')
                : $repository->create(null, 'Service', 'https://host.example');
            $client->forceFill(['secret' => 'test-secret'])->save();
            $started = time();
            $tokens = $this->postJson('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client->id,
                'client_secret' => 'test-secret', 'scope' => 'openid'])
                ->assertOk()->assertJsonMissingPath('id_token')->assertJsonMissingPath('refresh_token')->json();
            $expected = $seconds ?? 900;
            $this->assertEqualsWithDelta($expected, $tokens['expires_in'], 1);
            $expiry = app(TokenVerifier::class)->signedJwt($tokens['access_token'])->claims()->get('exp')->getTimestamp();
            $this->assertGreaterThanOrEqual($started + $expected, $expiry);
            $this->assertLessThanOrEqual(time() + $expected, $expiry);
        } finally {
            if ($supportsDedicatedLifetime) {
                Passport::$clientCredentialsTokensExpireIn = $original;
            }
        }
    }

    public static function clientCredentialsLifetimes(): array
    {
        return ['default lifetime' => [null], 'shorter lifetime' => [60], 'longer lifetime' => [1800]];
    }

    public function test_reused_server_does_not_carry_context_between_requests_or_users(): void
    {
        $server = app(OidcAuthorizationServer::class);
        app()->instance(OidcAuthorizationServer::class, $server);
        $client = $this->client();
        foreach ([$this->user(), $this->user()] as $user) {
            $tokens = $this->issueTokens($client, $user);
            $claims = app(TokenVerifier::class)->signedJwt($tokens['id_token'])->claims();
            $this->assertSame($user->getOidcSubject(), $claims->get('sub'));
            $this->assertFalse($claims->has('nonce'));
            $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
                'client_secret' => 'test-secret', 'refresh_token' => 'invalid'])->assertStatus(400);
            $this->assertSame($server, app(OidcAuthorizationServer::class));
        }
    }
}
