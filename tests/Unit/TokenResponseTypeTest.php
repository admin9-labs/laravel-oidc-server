<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Unit;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;
use Admin9\OidcServer\Events\OidcTokenIssued;
use Admin9\OidcServer\Services\IdTokenService;
use Admin9\OidcServer\Services\TokenResponseType;
use Admin9\OidcServer\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

class TokenResponseTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/passport/database/migrations');
        $this->artisan('passport:keys', ['--force' => true]);
    }

    protected function callGetExtraParams(
        TokenResponseType $responseType,
        AccessTokenEntityInterface $accessToken
    ): array {
        $method = new \ReflectionMethod(TokenResponseType::class, 'getExtraParams');
        $method->setAccessible(true);

        return $method->invoke($responseType, $accessToken);
    }

    protected function createScopeMock(string $name): ScopeEntityInterface
    {
        $scope = $this->createMock(ScopeEntityInterface::class);
        $scope->method('getIdentifier')->willReturn($name);

        return $scope;
    }

    public function test_returns_empty_array_when_openid_scope_not_present(): void
    {
        $idTokenService = $this->createMock(IdTokenService::class);
        $idTokenService->expects($this->never())->method('generateToken');

        $responseType = new TokenResponseType($idTokenService);

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client');

        $accessToken = $this->createMock(AccessTokenEntityInterface::class);
        $accessToken->method('getScopes')->willReturn([
            $this->createScopeMock('profile'),
            $this->createScopeMock('email'),
        ]);
        $accessToken->method('getClient')->willReturn($client);

        $result = $this->callGetExtraParams($responseType, $accessToken);

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_user_not_found(): void
    {
        $idTokenService = $this->createMock(IdTokenService::class);
        $responseType = new TokenResponseType($idTokenService);

        config(['oidc-server.user_model' => TokenResponseTestUser::class]);

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client');

        $accessToken = $this->createMock(AccessTokenEntityInterface::class);
        $accessToken->method('getScopes')->willReturn([
            $this->createScopeMock('openid'),
        ]);
        $accessToken->method('getUserIdentifier')->willReturn('9999');
        $accessToken->method('getClient')->willReturn($client);

        $result = $this->callGetExtraParams($responseType, $accessToken);

        $this->assertSame([], $result);
    }

    public function test_returns_id_token_when_openid_scope_present(): void
    {
        Event::fake([OidcTokenIssued::class]);

        $user = TokenResponseTestUser::forceCreate([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        config(['oidc-server.user_model' => TokenResponseTestUser::class]);

        $client = $this->createMock(ClientEntityInterface::class);
        $client->method('getIdentifier')->willReturn('test-client');

        $accessToken = $this->createMock(AccessTokenEntityInterface::class);
        $accessToken->method('getScopes')->willReturn([
            $this->createScopeMock('openid'),
        ]);
        $accessToken->method('getUserIdentifier')->willReturn((string) $user->id);
        $accessToken->method('getClient')->willReturn($client);
        $accessToken->method('getExpiryDateTime')
            ->willReturn(new \DateTimeImmutable('+1 hour'));

        $idTokenService = $this->createMock(IdTokenService::class);
        $idTokenService->expects($this->once())
            ->method('generateToken')
            ->willReturn('mock.id.token');

        $responseType = new TokenResponseType($idTokenService);

        $result = $this->callGetExtraParams($responseType, $accessToken);

        $this->assertArrayHasKey('id_token', $result);
        $this->assertSame('mock.id.token', $result['id_token']);

        Event::assertDispatched(OidcTokenIssued::class, function ($event) use ($user) {
            return $event->userId === $user->getKey()
                && $event->clientId === 'test-client'
                && $event->scopes === ['openid'];
        });
    }
}

class TokenResponseTestUser extends \Illuminate\Foundation\Auth\User implements OidcUserInterface
{
    use HasOidcClaims;

    protected $table = 'users';

    protected $guarded = [];
}
