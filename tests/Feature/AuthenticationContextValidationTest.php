<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\AuthorizationContext;
use Admin9\OidcServer\Services\PassportKeys;
use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\PassportTestCase;
use Defuse\Crypto\Crypto;
use Laravel\Passport\Passport;

class AuthenticationContextValidationTest extends PassportTestCase
{
    public function test_version_three_requires_exact_keys_types_and_identity(): void
    {
        $user = $this->user();
        $valid = ['v' => 3, 'nonce' => null, 'identity' => ['web', get_class($user), (string) $user->id],
            'client_id' => 'client', 'iss' => config('oidc-server.issuer'), 'sub' => $user->getOidcSubject(),
            'authentication' => ['auth_time' => time(), 'generation' => str_repeat('a', 64)]];
        $validate = fn ($context) => app(AuthorizationContext::class)->validPayload(['oidc' => $context, 'client_id' => 'client', 'user_id' => (string) $user->id]);
        $this->assertTrue($validate($valid));
        foreach (array_keys($valid) as $key) {
            $context = $valid;
            unset($context[$key]);
            $this->assertFalse($validate($context), 'Missing '.$key);
        }
        foreach ([null, '3', 3.0, true, 1, 2, 4] as $version) {
            $this->assertFalse($validate(array_replace($valid, ['v' => $version])));
        }
        foreach ([null, [], ['auth_time' => null, 'generation' => str_repeat('a', 64)],
            ['auth_time' => (string) time(), 'generation' => str_repeat('a', 64)],
            ['auth_time' => time() + 60, 'generation' => str_repeat('a', 64)],
            ['auth_time' => -1, 'generation' => str_repeat('a', 64)],
            ['auth_time' => time(), 'generation' => null], ['auth_time' => time(), 'generation' => ''],
            ['auth_time' => time(), 'generation' => str_repeat('a', 64), 'max_age' => 60],
        ] as $authentication) {
            $this->assertFalse($validate(array_replace($valid, ['authentication' => $authentication])));
        }
        foreach ([['max_age' => 60], ['challenge_id' => 'unused'], ['nonce' => []], ['sub' => 'other'],
            ['identity' => [1 => 'web', 2 => get_class($user), 3 => (string) $user->id]],
            ['identity' => ['web', get_class($user), $user->id]],
        ] as $change) {
            $this->assertFalse($validate(array_replace($valid, $change)));
        }
        $alias = $valid;
        $alias['identity'][2] = '0'.$user->id;
        $this->assertFalse(app(AuthorizationContext::class)->validPayload([
            'oidc' => $alias, 'client_id' => 'client', 'user_id' => $alias['identity'][2],
        ]));
        $user->delete();
        $this->assertFalse($validate($valid));
    }

    public function test_invalid_refresh_context_does_not_consume_or_revoke_the_original(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $payload = app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']);
        foreach ([null, ['auth_time' => null, 'generation' => str_repeat('a', 64)],
            ['auth_time' => time(), 'generation' => null]] as $authentication) {
            $bad = $payload;
            $bad['oidc']['authentication'] = $authentication;
            $refresh = Crypto::encryptWithPassword(json_encode($bad), app(PassportKeys::class)->encryptionKey());
            $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
                'client_secret' => 'test-secret', 'refresh_token' => $refresh])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
            $this->assertSame(1, Passport::token()->count());
            $this->assertFalse(Passport::refreshToken()->first()->revoked);
        }
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']])->assertOk();
    }

    public function test_invalid_refresh_scope_preserves_the_valid_credential_for_a_correct_retry(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        Passport::tokensCan(['openid' => 'OpenID', 'profile' => 'Profile', 'email' => 'Email', 'extra' => 'Not granted']);
        $body = ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']];
        foreach (['unknown', 'extra'] as $scope) {
            $this->postJson('/oauth/token', $body + ['scope' => $scope])->assertStatus(400)->assertJsonPath('error', 'invalid_scope');
            $this->assertSame(1, Passport::token()->count());
            $this->assertFalse(Passport::refreshToken()->first()->revoked);
        }
        $this->postJson('/oauth/token', $body + ['scope' => 'openid'])->assertOk()->assertJsonStructure(['id_token']);
    }
}
