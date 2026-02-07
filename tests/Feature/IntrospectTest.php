<?php

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;
use Laravel\Passport\Client;

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
}
