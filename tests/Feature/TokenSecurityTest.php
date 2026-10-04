<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Contracts\AtomicStateStore;
use Admin9\OidcServer\Services\PassportKeys;
use Admin9\OidcServer\Services\TokenConsumption;
use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\PassportTestCase;
use Defuse\Crypto\Crypto;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;

class TokenSecurityTest extends PassportTestCase
{
    public function test_public_and_revoked_clients_cannot_introspect(): void
    {
        $public = $this->client(true);
        $this->postJson('/oauth/introspect', ['client_id' => $public->id])->assertUnauthorized();
        $private = $this->client();
        $private->forceFill(['revoked' => true])->save();
        foreach (['introspect', 'revoke'] as $endpoint) {
            $this->postJson('/oauth/'.$endpoint, [
                'client_id' => $private->id, 'client_secret' => 'test-secret',
            ])->assertUnauthorized();
        }
    }

    public function test_malformed_basic_does_not_fall_back_to_valid_body_credentials(): void
    {
        $client = $this->client();
        foreach (['Basic !!!', 'Bearer invalid', 'Basic '.base64_encode('missing-colon')] as $header) {
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret',
            ], ['Authorization' => $header])->assertUnauthorized();
        }
        $this->postJson('/oauth/introspect', [], [
            'Authorization' => 'Basic '.base64_encode(urlencode((string) $client->id).':test-secret'),
        ])->assertExactJson(['active' => false]);
    }

    public function test_invalid_input_shapes_fail_closed(): void
    {
        $this->postJson('/oauth/introspect', ['client_id' => ['id']])->assertUnauthorized();
        $client = $this->client();
        $this->postJson('/oauth/introspect', [
            'client_id' => $client->id, 'client_secret' => ['secret'],
        ])->assertUnauthorized();
        $this->postJson('/oauth/introspect', [
            'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => ['token'],
        ])->assertExactJson(['active' => false]);
    }

    #[DataProvider('invalidRefreshParameters')]
    public function test_invalid_refresh_parameters_preserve_valid_credentials(array $parameters): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $payload = app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']);
        $request = ['grant_type' => 'refresh_token', 'client_id' => $client->id] + $parameters;
        $this->postJson('/oauth/token', $request + ['client_secret' => 'wrong-secret'])
            ->assertUnauthorized()->assertJsonPath('error', 'invalid_client');
        $this->postJson('/oauth/token', $request + ['client_secret' => 'test-secret'])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_request')->assertJsonMissingPath('access_token');
        $this->assertSame(1, Passport::token()->count());
        $this->assertSame(1, Passport::refreshToken()->count());
        $this->assertFalse(Passport::token()->first()->revoked);
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
        $this->assertTrue(app(TokenConsumption::class)->isAvailable('refresh', $payload));
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']])
            ->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
    }

    public static function invalidRefreshParameters(): array
    {
        return [
            'missing' => [[]],
            'null' => [['refresh_token' => null]],
            'empty' => [['refresh_token' => '']],
            'whitespace' => [['refresh_token' => " \t\n "]],
            'array' => [['refresh_token' => ['token']]],
            'number' => [['refresh_token' => 123]],
            'true' => [['refresh_token' => true]],
            'false' => [['refresh_token' => false]],
        ];
    }

    public function test_nonempty_invalid_refresh_tokens_keep_invalid_grant_semantics(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $payload = app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']);
        $expired = Crypto::encryptWithPassword(json_encode(array_replace($payload, ['expire_time' => time() - 10])), app(PassportKeys::class)->encryptionKey());
        $credentials = ['grant_type' => 'refresh_token', 'client_secret' => 'test-secret'];
        foreach ([
            ['client_id' => $client->id, 'refresh_token' => 'malformed'],
            ['client_id' => $client->id, 'refresh_token' => $expired],
            ['client_id' => $this->client()->id, 'refresh_token' => $tokens['refresh_token']],
        ] as $parameters) {
            $this->postJson('/oauth/token', $credentials + $parameters)
                ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        }
        $this->assertSame(1, Passport::token()->count());
        $this->assertSame(1, Passport::refreshToken()->count());
        $this->assertFalse(Passport::token()->first()->revoked);
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
        $this->assertTrue(app(TokenConsumption::class)->isAvailable('refresh', $payload));
        $body = $credentials + ['client_id' => $client->id, 'refresh_token' => $tokens['refresh_token']];
        $rotated = $this->postJson('/oauth/token', $body)->assertOk()->json();
        $this->postJson('/oauth/token', $body)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $newPayload = app(TokenVerifier::class)->encryptedPayload($rotated['refresh_token']);
        Passport::refreshToken()->findOrFail($newPayload['refresh_token_id'])->forceFill(['revoked' => true])->save();
        $this->postJson('/oauth/token', array_replace($body, ['refresh_token' => $rotated['refresh_token']]))
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(2, Passport::token()->count());
        $this->assertSame(2, Passport::refreshToken()->count());
    }

    public function test_real_tokens_support_missing_wrong_and_unknown_hints_and_actual_revocation(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        foreach (['access_token', 'refresh_token'] as $type) {
            foreach ([null, '', 'access_token', 'refresh_token', 'unknown'] as $hint) {
                $this->postJson('/oauth/introspect', [
                    'client_id' => $client->id, 'client_secret' => 'test-secret',
                    'token' => $tokens[$type], 'token_type_hint' => $hint,
                ])->assertOk()->assertJsonPath('active', true);
            }
        }
        $this->postJson('/oauth/revoke', [
            'client_id' => $client->id, 'client_secret' => 'test-secret',
            'token' => $tokens['refresh_token'], 'token_type_hint' => 'access_token',
        ])->assertOk();
        foreach (['access_token', 'refresh_token'] as $type) {
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $tokens[$type],
            ])->assertExactJson(['active' => false]);
        }
        $refreshResponse = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => 'test-secret',
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->assertContains($refreshResponse->getStatusCode(), [400, 401]);
        $refreshResponse->assertJsonMissingPath('access_token');
    }

    public function test_ownership_applies_to_both_token_types_and_revocation(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $tokens = $this->issueTokens($owner);
        foreach (['access_token', 'refresh_token'] as $type) {
            $this->postJson('/oauth/introspect', [
                'client_id' => $other->id, 'client_secret' => 'test-secret', 'token' => $tokens[$type],
            ])->assertExactJson(['active' => false]);
            $this->postJson('/oauth/revoke', [
                'client_id' => $other->id, 'client_secret' => 'test-secret', 'token' => $tokens[$type],
            ])->assertOk();
        }
        config(['oidc-server.introspection_allowed_clients' => [(string) $other->id => [(string) $owner->id]]]);
        $this->postJson('/oauth/introspect', [
            'client_id' => $other->id, 'client_secret' => 'test-secret', 'token' => $tokens['access_token'],
        ])->assertJsonPath('active', true);
        $this->assertFalse(Passport::token()->first()->revoked);
    }

    public function test_bare_ids_and_modified_jwts_cannot_introspect_or_revoke(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        [$header, $payload, $signature] = explode('.', $tokens['access_token']);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['sub'] = 'different';
        $forged = $header.'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$signature;
        foreach ([Passport::token()->first()->id, Passport::refreshToken()->first()->id, $forged] as $value) {
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret',
                'token' => $value, 'token_type_hint' => 'refresh_token',
            ])->assertExactJson(['active' => false]);
            $this->postJson('/oauth/revoke', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $value,
            ])->assertOk();
        }
        $this->assertFalse(Passport::token()->first()->revoked);
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
    }

    public function test_public_client_can_revoke_only_by_presenting_its_real_token(): void
    {
        $client = $this->client(true);
        $tokens = $this->issueTokens($client);
        $this->postJson('/oauth/revoke', [
            'client_id' => $client->id, 'token' => Passport::refreshToken()->first()->id,
        ])->assertOk();
        $this->assertFalse(Passport::token()->first()->revoked);
        $this->postJson('/oauth/revoke', [
            'client_id' => $client->id, 'token' => $tokens['access_token'], 'token_type_hint' => 'refresh_token',
        ])->assertOk();
        $this->assertTrue(Passport::token()->first()->revoked);
        $this->assertTrue(Passport::refreshToken()->first()->revoked);
    }

    public function test_refresh_survives_access_expiry_but_rejects_expired_revoked_and_mismatched_envelopes(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $access = Passport::token()->first();
        $access->forceFill(['expires_at' => now()->subHour()])->save();
        $credentials = ['client_id' => $client->id, 'client_secret' => 'test-secret'];
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['refresh_token']])
            ->assertJsonPath('active', true);
        $payload = app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']);
        foreach ([
            ['access_token_id' => 'another-token'], ['client_id' => 'another-client'],
            ['expire_time' => time() - 10], ['refresh_token_id' => []],
        ] as $changes) {
            $encrypted = Crypto::encryptWithPassword(json_encode(array_replace($payload, $changes)), app(PassportKeys::class)->encryptionKey());
            $this->postJson('/oauth/introspect', $credentials + ['token' => $encrypted])
                ->assertExactJson(['active' => false]);
        }
        Passport::refreshToken()->first()->forceFill(['revoked' => true])->save();
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['refresh_token']])
            ->assertExactJson(['active' => false]);
    }

    #[DataProvider('unavailableRefreshStates')]
    public function test_unavailable_refresh_state_is_inactive_but_still_revocable(string $state): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $payload = app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']);
        $store = app(AtomicStateStore::class);
        $key = 'refresh:'.hash('sha256', $payload['refresh_token_id']);
        switch ($state) {
            case 'missing':
                unset($store->values[$key]);
                break;
            case 'consumed':
                app(TokenConsumption::class)->consume('refresh', $payload);
                break;
            case 'expired':
                $store->values[$key][1] = now()->timestamp;
                break;
            case 'mismatched':
                $store->values[$key][0] = 'different-fingerprint';
                break;
        }
        $originalState = $store->values;
        $credentials = ['client_id' => $client->id, 'client_secret' => 'test-secret'];
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['refresh_token']])
            ->assertOk()->assertExactJson(['active' => false]);
        $this->assertSame($originalState, $store->values);
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['access_token']])
            ->assertOk()->assertJsonPath('active', true);
        $this->postJson('/oauth/token', $credentials + ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(1, Passport::token()->count());
        $this->postJson('/oauth/revoke', $credentials + ['token' => $tokens['refresh_token']])->assertOk();
        $this->assertTrue(Passport::token()->first()->revoked);
        $this->assertTrue(Passport::refreshToken()->first()->revoked);
    }

    public static function unavailableRefreshStates(): array
    {
        return ['missing' => ['missing'], 'consumed' => ['consumed'], 'expired' => ['expired'], 'mismatched' => ['mismatched']];
    }

    public function test_introspection_does_not_consume_a_usable_refresh_token(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $credentials = ['client_id' => $client->id, 'client_secret' => 'test-secret'];
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['refresh_token']])
                ->assertOk()->assertJsonPath('active', true);
        }
        $this->postJson('/oauth/token', $credentials + ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']])
            ->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'id_token']);
    }

    public function test_unavailable_atomic_store_blocks_refresh_introspection_but_not_revocation(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $store = $this->createMock(AtomicStateStore::class);
        $store->expects($this->once())->method('read')->willThrowException(new \RuntimeException('Atomic store unavailable.'));
        $store->expects($this->never())->method('create');
        $store->expects($this->never())->method('replace');
        app()->instance(AtomicStateStore::class, $store);
        $credentials = ['client_id' => $client->id, 'client_secret' => 'test-secret'];
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['refresh_token']])
            ->assertStatus(500)->assertJsonMissingPath('active');
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['access_token']])
            ->assertOk()->assertJsonPath('active', true);
        $this->postJson('/oauth/revoke', $credentials + ['token' => $tokens['refresh_token']])->assertOk();
        $this->assertTrue(Passport::token()->first()->revoked);
        $this->assertTrue(Passport::refreshToken()->first()->revoked);
    }

    public function test_email_scope_is_required_for_username(): void
    {
        $client = $this->client();
        foreach ([['openid'], ['openid', 'email']] as $scopes) {
            $token = $this->accessToken($client, $scopes);
            $response = $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $this->accessJwt($token),
            ])->assertJsonPath('active', true);
            in_array('email', $scopes, true)
                ? $response->assertJsonPath('username', $token->user->email)
                : $response->assertJsonMissingPath('username');
        }
    }

    #[DataProvider('clientAuthenticationMethods')]
    public function test_passport_13_custom_hasher_authenticates_introspection_and_revocation(bool $basic): void
    {
        if (property_exists(Passport::class, 'hashesClientSecrets')) {
            $this->markTestSkipped('Passport 12 uses its own client secret verification.');
        }

        Hash::extend('test-pepper', fn () => new class(['rounds' => 4]) extends BcryptHasher {
            public function make($value, array $options = [])
            {
                return parent::make($value.'test-only-pepper', $options);
            }

            public function check($value, $hashedValue, array $options = [])
            {
                return parent::check($value.'test-only-pepper', $hashedValue, $options);
            }
        });
        config(['hashing.driver' => 'test-pepper']);
        $this->app->forgetInstance('hash.driver');

        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $credentials = $basic ? [] : ['client_id' => $client->id, 'client_secret' => 'test-secret'];
        $headers = $basic ? ['Authorization' => 'Basic '.base64_encode($client->id.':test-secret')] : [];
        $invalidCredentials = $basic ? [] : ['client_id' => $client->id, 'client_secret' => 'wrong-secret'];
        $invalidHeaders = $basic ? ['Authorization' => 'Basic '.base64_encode($client->id.':wrong-secret')] : [];

        foreach (['introspect', 'revoke'] as $endpoint) {
            $this->postJson('/oauth/'.$endpoint, $invalidCredentials + ['token' => $tokens['access_token']], $invalidHeaders)
                ->assertUnauthorized();
        }
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['access_token']], $headers)
            ->assertOk()->assertJsonPath('active', true);
        $this->postJson('/oauth/revoke', $credentials + ['token' => $tokens['access_token']], $headers)->assertOk();
        $this->assertTrue(Passport::token()->first()->revoked);
        $this->assertTrue(Passport::refreshToken()->first()->revoked);
        $this->postJson('/oauth/introspect', $credentials + ['token' => $tokens['access_token']], $headers)
            ->assertExactJson(['active' => false]);
    }

    public static function clientAuthenticationMethods(): array
    {
        return ['body credentials' => [false], 'basic credentials' => [true]];
    }

    public function test_passport_12_hashed_secrets_remain_supported(): void
    {
        if (! property_exists(Passport::class, 'hashesClientSecrets')) {
            $this->markTestSkipped('Passport 13 always hashes client secrets.');
        }
        $original = Passport::$hashesClientSecrets;
        Passport::$hashesClientSecrets = true;
        try {
            $client = $this->client();
            $tokens = $this->issueTokens($client);
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $tokens['access_token'],
            ])->assertJsonPath('active', true);
        } finally {
            Passport::$hashesClientSecrets = $original;
        }
    }
}
