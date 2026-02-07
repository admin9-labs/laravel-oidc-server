<?php

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;

class ClaimsResolutionTest extends TestCase
{
    public function test_claims_service_returns_supported_claims(): void
    {
        $claimsService = app(\Admin9\OidcServer\Services\ClaimsService::class);

        $claims = $claimsService->getSupportedClaims();

        $this->assertContains('sub', $claims);
        $this->assertContains('iss', $claims);
        $this->assertContains('aud', $claims);
        $this->assertContains('exp', $claims);
        $this->assertContains('iat', $claims);
        $this->assertContains('auth_time', $claims);
        $this->assertContains('name', $claims);
        $this->assertContains('email', $claims);
        $this->assertContains('email_verified', $claims);
    }

    public function test_claims_service_includes_all_scope_claims(): void
    {
        $claimsService = app(\Admin9\OidcServer\Services\ClaimsService::class);

        $claims = $claimsService->getSupportedClaims();

        // From profile scope
        $this->assertContains('nickname', $claims);
        $this->assertContains('picture', $claims);
        $this->assertContains('updated_at', $claims);

        // No duplicates
        $this->assertEquals(count($claims), count(array_unique($claims)));
    }

    public function test_has_oidc_claims_trait_resolves_sub(): void
    {
        $user = new TestUser;
        $user->id = 42;

        $this->assertEquals('42', $user->getOidcSubject());
    }

    public function test_has_oidc_claims_trait_resolves_default_claims(): void
    {
        config([
            'oidc-server.default_claims_map' => [
                'name' => 'name',
                'email' => 'email',
            ],
        ]);

        $user = new TestUser;
        $user->id = 1;
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        $claims = $user->getOidcClaims(['profile', 'email']);

        $this->assertEquals('John Doe', $claims['name']);
        $this->assertEquals('john@example.com', $claims['email']);
    }

    public function test_has_oidc_claims_trait_uses_claims_resolver(): void
    {
        config([
            'oidc-server.claims_resolver' => [
                'name' => fn ($user) => strtoupper($user->name),
            ],
            'oidc-server.default_claims_map' => [
                'name' => 'name',
            ],
        ]);

        $user = new TestUser;
        $user->id = 1;
        $user->name = 'John Doe';

        $claims = $user->getOidcClaims(['profile']);

        // claims_resolver takes priority over default_claims_map
        $this->assertEquals('JOHN DOE', $claims['name']);
    }

    public function test_has_oidc_claims_trait_returns_null_for_unknown_claims(): void
    {
        config([
            'oidc-server.scopes' => [
                'custom' => [
                    'description' => 'Custom scope',
                    'claims' => ['nonexistent_claim'],
                ],
            ],
            'oidc-server.claims_resolver' => [],
            'oidc-server.default_claims_map' => [],
        ]);

        $user = new TestUser;
        $user->id = 1;

        $claims = $user->getOidcClaims(['custom']);

        // Unknown claims should not be included (null values are omitted)
        $this->assertArrayNotHasKey('nonexistent_claim', $claims);
    }
}

/**
 * Minimal test user model implementing OidcUserInterface.
 */
class TestUser extends \Illuminate\Foundation\Auth\User implements \Admin9\OidcServer\Contracts\OidcUserInterface
{
    use \Admin9\OidcServer\Concerns\HasOidcClaims;

    protected $guarded = [];
}
