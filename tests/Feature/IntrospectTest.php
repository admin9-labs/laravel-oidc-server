<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;
use DateTimeImmutable;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use League\OAuth2\Server\CryptKey;

class IntrospectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/passport/database/migrations');
        $this->artisan('passport:keys', ['--force' => true]);
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::forceCreate(array_merge([
            'name' => 'Test Client',
            'secret' => 'test-secret',
            'redirect_uris' => 'https://app.example.com/callback',
            'grant_types' => 'authorization_code',
            'revoked' => false,
        ], $attributes));
    }

    protected function createAccessToken(
        Client $client,
        array $attributes = [],
        array $scopes = ['openid', 'profile']
    ): Token {
        return Token::forceCreate(array_merge([
            'id' => 'token-'.str_replace('.', '', uniqid('', true)),
            'user_id' => 1,
            'client_id' => $client->id,
            'name' => 'Test Token',
            'scopes' => $scopes,
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addHour(),
        ], $attributes));
    }

    protected function createJwtForAccessToken(Token $token): string
    {
        $client = new \Laravel\Passport\Bridge\Client(
            (string) $token->client_id,
            'Test Client',
            [],
            true
        );
        $scopes = array_map(
            fn (string $scope): \Laravel\Passport\Bridge\Scope => new \Laravel\Passport\Bridge\Scope($scope),
            $token->scopes ?? []
        );
        $accessToken = new \Laravel\Passport\Bridge\AccessToken((string) $token->user_id, $scopes, $client);

        $accessToken->setIdentifier($token->id);
        $accessToken->setExpiryDateTime(DateTimeImmutable::createFromInterface($token->expires_at));
        $accessToken->setPrivateKey(new CryptKey(storage_path('oauth-private.key'), null, false));

        return $accessToken->toString();
    }

    public function test_introspect_requires_client_authentication(): void
    {
        $response = $this->postJson('/oauth/introspect', [
            'token' => 'some-token',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'invalid_client',
        ]);
    }

    public function test_introspect_with_invalid_client_credentials(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/oauth/introspect', [
            'client_id' => $client->id,
            'client_secret' => 'wrong-secret',
            'token' => 'some-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_introspect_with_basic_auth(): void
    {
        $client = $this->createClient();
        $credentials = base64_encode($client->id.':test-secret');

        $response = $this->postJson('/oauth/introspect', [
            'token' => 'nonexistent-token',
        ], [
            'Authorization' => 'Basic '.$credentials,
        ]);

        // Should authenticate successfully but return inactive token
        $response->assertOk();
        $response->assertJson(['active' => false]);
    }

    public function test_introspect_returns_inactive_for_missing_token(): void
    {
        $client = $this->createClient();
        $credentials = base64_encode($client->id.':test-secret');

        $response = $this->postJson('/oauth/introspect', [], [
            'Authorization' => 'Basic '.$credentials,
        ]);

        $response->assertOk();
        $response->assertJson(['active' => false]);
    }

    public function test_introspect_returns_inactive_for_nonexistent_token(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/oauth/introspect', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
            'token' => 'nonexistent-token-id',
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
        $response->assertJson(['active' => false]);
    }

    public function test_introspect_returns_active_for_valid_passport_jwt_access_token(): void
    {
        $client = $this->createClient();
        $accessToken = $this->createAccessToken($client);
        $jwt = $this->createJwtForAccessToken($accessToken);

        $response = $this->postJson('/oauth/introspect', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
            'token' => $jwt,
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
        $response->assertJson([
            'active' => true,
            'scope' => 'openid profile',
            'client_id' => $client->id,
            'token_type' => 'Bearer',
            'sub' => '1',
            'aud' => $client->id,
            'iss' => 'https://example.com',
        ]);
        $response->assertJsonPath('exp', $accessToken->expires_at->timestamp);
        $response->assertJsonPath('iat', $accessToken->created_at->timestamp);
    }

    public function test_introspect_returns_active_for_raw_access_token_id(): void
    {
        $client = $this->createClient();
        $accessToken = $this->createAccessToken($client);

        $response = $this->postJson('/oauth/introspect', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
            'token' => $accessToken->id,
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
        $response->assertJson([
            'active' => true,
            'client_id' => $client->id,
        ]);
    }

    public function test_introspect_returns_inactive_for_malformed_jwt_access_token(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/oauth/introspect', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
            'token' => 'not.a.jwt',
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
        $response->assertJson(['active' => false]);
    }

    public function test_introspect_returns_inactive_for_revoked_jwt_access_token(): void
    {
        $client = $this->createClient();
        $accessToken = $this->createAccessToken($client, ['revoked' => true]);
        $jwt = $this->createJwtForAccessToken($accessToken);

        $response = $this->postJson('/oauth/introspect', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
            'token' => $jwt,
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
        $response->assertJson(['active' => false]);
    }

    public function test_introspect_returns_inactive_for_expired_jwt_access_token(): void
    {
        $client = $this->createClient();
        $accessToken = $this->createAccessToken($client, ['expires_at' => now()->subMinute()]);
        $jwt = $this->createJwtForAccessToken($accessToken);

        $response = $this->postJson('/oauth/introspect', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
            'token' => $jwt,
            'token_type_hint' => 'access_token',
        ]);

        $response->assertOk();
        $response->assertJson(['active' => false]);
    }
}
