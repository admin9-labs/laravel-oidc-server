<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Unit;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;
use Admin9\OidcServer\Services\ClaimsService;
use Admin9\OidcServer\Services\IdTokenService;
use Admin9\OidcServer\Tests\TestCase;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class IdTokenServiceTest extends TestCase
{
    protected IdTokenService $idTokenService;

    protected Configuration $jwtConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('passport:keys', ['--force' => true]);

        $this->idTokenService = new IdTokenService(app(ClaimsService::class));

        $this->jwtConfig = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::file(storage_path('oauth-private.key')),
            InMemory::file(storage_path('oauth-public.key'))
        );
    }

    protected function createMockAccessToken(
        array $scopeNames = ['openid'],
        string $userId = '1'
    ): AccessTokenEntityInterface {
        $scopes = [];
        foreach ($scopeNames as $name) {
            $scope = $this->createMock(ScopeEntityInterface::class);
            $scope->method('getIdentifier')->willReturn($name);
            $scopes[] = $scope;
        }

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client-id');

        $accessToken = $this->createMock(AccessTokenEntityInterface::class);
        $accessToken->method('getScopes')->willReturn($scopes);
        $accessToken->method('getClient')->willReturn($client);
        $accessToken->method('getUserIdentifier')->willReturn($userId);
        $accessToken->method('getExpiryDateTime')
            ->willReturn(new \DateTimeImmutable('+1 hour'));

        return $accessToken;
    }

    protected function createTestUser(): IdTokenTestUser
    {
        $user = new IdTokenTestUser();
        $user->id = 42;
        $user->name = 'Jane Doe';
        $user->email = 'jane@example.com';

        return $user;
    }

    protected function parseToken(string $jwt): \Lcobucci\JWT\Token\Plain
    {
        return $this->jwtConfig->parser()->parse($jwt);
    }

    public function test_generate_token_returns_valid_jwt_string(): void
    {
        $accessToken = $this->createMockAccessToken();
        $user = $this->createTestUser();
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client-id');

        $jwt = $this->idTokenService->generateToken($accessToken, $user, $client);

        $this->assertIsString($jwt);
        $this->assertCount(3, explode('.', $jwt));

        $parsed = $this->parseToken($jwt);
        $this->assertInstanceOf(\Lcobucci\JWT\Token\Plain::class, $parsed);
    }

    public function test_jwt_contains_correct_iss_aud_sub_claims(): void
    {
        config(['oidc-server.issuer' => 'https://auth.example.com']);

        $accessToken = $this->createMockAccessToken();
        $user = $this->createTestUser();
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('my-client');

        $jwt = $this->idTokenService->generateToken($accessToken, $user, $client);
        $parsed = $this->parseToken($jwt);

        $this->assertTrue($parsed->hasBeenIssuedBy('https://auth.example.com'));
        $this->assertTrue($parsed->isPermittedFor('my-client'));
        $this->assertTrue($parsed->isRelatedTo('42'));
    }

    public function test_nonce_is_included_when_provided(): void
    {
        $accessToken = $this->createMockAccessToken();
        $user = $this->createTestUser();
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client-id');

        $jwt = $this->idTokenService->generateToken($accessToken, $user, $client, 'abc123');
        $parsed = $this->parseToken($jwt);

        $this->assertTrue($parsed->claims()->has('nonce'));
        $this->assertSame('abc123', $parsed->claims()->get('nonce'));
    }

    public function test_nonce_is_not_included_when_null(): void
    {
        $accessToken = $this->createMockAccessToken();
        $user = $this->createTestUser();
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client-id');

        $jwt = $this->idTokenService->generateToken($accessToken, $user, $client, null);
        $parsed = $this->parseToken($jwt);

        $this->assertFalse($parsed->claims()->has('nonce'));
    }

    public function test_claims_from_scopes_are_included_in_token(): void
    {
        config([
            'oidc-server.default_claims_map' => [
                'name' => 'name',
                'email' => 'email',
            ],
        ]);

        $accessToken = $this->createMockAccessToken(['openid', 'profile', 'email']);
        $user = $this->createTestUser();
        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client-id');

        $jwt = $this->idTokenService->generateToken($accessToken, $user, $client);
        $parsed = $this->parseToken($jwt);

        $this->assertSame('Jane Doe', $parsed->claims()->get('name'));
        $this->assertSame('jane@example.com', $parsed->claims()->get('email'));
        $this->assertTrue($parsed->claims()->has('auth_time'));
    }
}

class IdTokenTestUser extends \Illuminate\Foundation\Auth\User implements OidcUserInterface
{
    use HasOidcClaims;

    protected $guarded = [];
}
