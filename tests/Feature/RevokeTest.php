<?php

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;
use Laravel\Passport\Client;

class RevokeTest extends TestCase
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

    public function test_revoke_requires_client_authentication(): void
    {
        $response = $this->postJson('/oauth/revoke', [
            'token' => 'some-token',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'invalid_client',
        ]);
    }

    public function test_revoke_with_invalid_client_returns_401(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/oauth/revoke', [
            'client_id' => $client->id,
            'client_secret' => 'wrong-secret',
            'token' => 'some-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_revoke_without_token_returns_200(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/oauth/revoke', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
        ]);

        // RFC 7009: always return 200, even if no token provided
        $response->assertOk();
    }

    public function test_revoke_nonexistent_token_returns_200(): void
    {
        $client = $this->createClient();

        $response = $this->postJson('/oauth/revoke', [
            'client_id' => $client->id,
            'client_secret' => 'test-secret',
            'token' => 'nonexistent-token',
            'token_type_hint' => 'access_token',
        ]);

        // RFC 7009: always return 200, don't leak token status
        $response->assertOk();
    }

    public function test_revoke_supports_basic_auth(): void
    {
        $client = $this->createClient();
        $credentials = base64_encode($client->id.':test-secret');

        $response = $this->postJson('/oauth/revoke', [
            'token' => 'some-token',
        ], [
            'Authorization' => 'Basic '.$credentials,
        ]);

        $response->assertOk();
    }
}
