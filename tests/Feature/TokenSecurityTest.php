<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\PassportKeys;
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
