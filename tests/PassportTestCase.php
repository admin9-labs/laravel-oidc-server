<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests;

use Admin9\OidcServer\Tests\Support\OidcUser;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKey;

abstract class PassportTestCase extends TestCase
{
    protected static ?array $testKeys = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        if (self::$testKeys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$testKeys = [$private, openssl_pkey_get_details($key)['key']];
        }
        $app['config']->set('passport.private_key', self::$testKeys[0]);
        $app['config']->set('passport.public_key', self::$testKeys[1]);
        $app['config']->set('passport.guard', 'web');
        $app['config']->set('auth.providers.users.model', OidcUser::class);
        $app['config']->set('oidc-server.user_model', OidcUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/laravel/passport/database/migrations');
    }

    protected function client(bool $public = false): Client
    {
        $repository = app(ClientRepository::class);
        $client = method_exists($repository, 'createAuthorizationCodeGrantClient')
            ? $repository->createAuthorizationCodeGrantClient('Test RP', ['https://rp.example/callback'], ! $public)
            : $repository->create(null, 'Test RP', 'https://rp.example/callback', null, false, false, ! $public);
        $client->forceFill(['secret' => $public ? null : 'test-secret'])->save();

        return $client;
    }

    protected function user(): OidcUser
    {
        return OidcUser::forceCreate([
            'name' => 'Example user', 'email' => uniqid('user').'@example.com', 'password' => 'unused',
        ]);
    }

    protected function accessToken(Client $client, array $scopes = ['openid']): Token
    {
        return Passport::token()->forceCreate([
            'id' => bin2hex(random_bytes(40)), 'user_id' => $this->user()->id,
            'client_id' => $client->id, 'scopes' => $scopes,
            'revoked' => false, 'expires_at' => now()->addHour(),
        ]);
    }

    protected function accessJwt(Token $token): string
    {
        $redirects = property_exists(Passport::class, 'hashesClientSecrets') ? '' : [];
        $client = new \Laravel\Passport\Bridge\Client((string) $token->client_id, 'Test RP', $redirects, true);
        $scopes = array_map(fn ($scope) => new \Laravel\Passport\Bridge\Scope($scope), $token->scopes);
        $access = new \Laravel\Passport\Bridge\AccessToken((string) $token->user_id, $scopes, $client);
        $access->setIdentifier($token->id);
        $access->setExpiryDateTime(\DateTimeImmutable::createFromInterface($token->expires_at));
        $access->setPrivateKey(new CryptKey(config('passport.private_key'), null, false));

        return method_exists($access, 'toString') ? $access->toString() : (string) $access;
    }

    protected function idHint(Client $client, OidcUser $user, array $overrides = []): string
    {
        $config = Configuration::forAsymmetricSigner(new Sha256,
            InMemory::plainText(config('passport.private_key')),
            InMemory::plainText(config('passport.public_key')));
        $builder = $config->builder()
            ->issuedBy($overrides['iss'] ?? config('oidc-server.issuer'))
            ->permittedFor(...($overrides['aud'] ?? [(string) $client->id]))
            ->relatedTo($overrides['sub'] ?? $user->getOidcSubject())
            ->issuedAt($overrides['iat'] ?? new \DateTimeImmutable('-1 second'))
            ->expiresAt($overrides['exp'] ?? new \DateTimeImmutable('+1 hour'));
        foreach ($overrides['claims'] ?? [] as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }
        foreach ($overrides['headers'] ?? [] as $name => $value) {
            $builder = $builder->withHeader($name, $value);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    protected function authorize(Client $client, array $parameters = []): \Illuminate\Testing\TestResponse
    {
        return $this->get('/oauth/authorize?'.http_build_query(array_merge([
            'client_id' => $client->id, 'redirect_uri' => 'https://rp.example/callback',
            'response_type' => 'code', 'scope' => 'openid profile email', 'state' => 'test-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $parameters)));
    }

    protected function issueTokens(Client $client, ?OidcUser $user = null): array
    {
        Auth::guard('web')->login($user ?? $this->user());
        $this->authorize($client)->assertOk();
        $response = $this->post('/oauth/authorize', ['auth_token' => session('authToken')]);
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $response = $this->postJson('/oauth/token', array_merge([
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'redirect_uri' => 'https://rp.example/callback', 'code' => $query['code'],
            'code_verifier' => str_repeat('a', 64),
        ], $client->confidential() ? ['client_secret' => 'test-secret'] : []));
        $response->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);

        return $response->json();
    }

    protected function confirmLogout(array $parameters = []): \Illuminate\Testing\TestResponse
    {
        $this->get('/oauth/logout?'.http_build_query($parameters))->assertOk();

        return $this->post('/oauth/logout/confirm', [
            'confirmation' => session('oidc.logout_pending.challenge'),
            '_token' => csrf_token(),
        ]);
    }
}
