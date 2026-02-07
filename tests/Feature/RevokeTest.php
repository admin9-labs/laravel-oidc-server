<?php

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;

class RevokeTest extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->artisan('passport:install', ['--no-interaction' => true]);
    }

    protected function createClient(array $attributes = []): Client
    {
        return Client::forceCreate(array_merge([
            'name' => 'Test Client',
            'secret' => bcrypt('test-secret'),
            'redirect' => 'https://app.example.com/callback',
            'personal_access_client' => false,
            'password_client' => false,
            'revoked' => false,
            'public_client' => false,
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
