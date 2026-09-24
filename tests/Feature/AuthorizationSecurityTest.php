<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\PassportKeys;
use Admin9\OidcServer\Tests\PassportTestCase;
use Defuse\Crypto\Crypto;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;

class AuthorizationSecurityTest extends PassportTestCase
{
    public function test_default_client_requires_consent_on_both_passport_versions(): void
    {
        $client = $this->client();
        $user = $this->user();
        $skips = (new \ReflectionMethod($client, 'skipsAuthorization'))->getNumberOfRequiredParameters() === 0
            ? $client->skipsAuthorization() : $client->skipsAuthorization($user, []);
        $this->assertFalse($skips);
        Auth::guard('web')->login($user);
        $this->authorize($client)->assertOk()->assertSee('Authorize')->assertSee('Deny');
    }

    public function test_legacy_tokens_and_refreshed_tokens_are_not_consent_records(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $this->authorize($client)->assertOk();
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token'],
        ])->assertOk();
        $this->authorize($client)->assertOk();
        $response = $this->authorize($client, ['prompt' => 'none'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('error', $query);
        $this->assertArrayNotHasKey('code', $query);
    }

    public function test_pending_approval_cannot_be_reused_by_another_user_or_without_auth_token(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $this->authorize($client)->assertOk();
        $this->post('/oauth/authorize', [])->assertStatus(400);
        $this->authorize($client)->assertOk();
        $token = session('authToken');
        Auth::guard('web')->login($this->user());
        $this->post('/oauth/authorize', ['auth_token' => $token])->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_legacy_pending_authorization_must_be_restarted_after_upgrade(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $this->authorize($client)->assertOk();
        session()->forget('oidc.authorization_pending');
        $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_discovery_and_endpoints_enforce_code_and_s256_despite_old_config(): void
    {
        config([
            'oidc-server.response_types_supported' => ['code', 'token'],
            'oidc-server.code_challenge_methods_supported' => ['S256', 'plain'],
        ]);
        $this->getJson('/.well-known/openid-configuration')
            ->assertJsonPath('response_types_supported', ['code'])
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
        $client = $this->client(true);
        Auth::guard('web')->login($this->user());
        foreach (['plain', null, 'unsupported'] as $method) {
            $this->authorize($client, ['code_challenge_method' => $method])->assertStatus(400);
        }
        $original = Passport::$implicitGrantEnabled;
        Passport::$implicitGrantEnabled = true;
        try {
            $this->authorize($client, ['response_type' => 'token'])->assertStatus(400);
        } finally {
            Passport::$implicitGrantEnabled = $original;
        }
        $this->issueTokens($client);
    }

    public function test_old_plain_authorization_codes_cannot_be_redeemed_after_upgrade(): void
    {
        $client = $this->client(true);
        $user = $this->user();
        $id = bin2hex(random_bytes(40));
        Passport::authCode()->forceCreate([
            'id' => $id, 'user_id' => $user->id, 'client_id' => $client->id,
            'scopes' => json_encode(['openid']), 'revoked' => false, 'expires_at' => now()->addMinutes(5),
        ]);
        $code = Crypto::encryptWithPassword(json_encode([
            'auth_code_id' => $id, 'client_id' => (string) $client->id,
            'user_id' => (string) $user->id, 'redirect_uri' => 'https://rp.example/callback',
            'expire_time' => time() + 300, 'scopes' => ['openid'],
            'code_challenge' => str_repeat('a', 64), 'code_challenge_method' => 'plain',
        ]), app(PassportKeys::class)->encryptionKey());
        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'redirect_uri' => 'https://rp.example/callback', 'code' => $code,
            'code_verifier' => str_repeat('a', 64),
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, Passport::token()->count());
    }

    public function test_rendered_consent_uses_no_external_script_and_escapes_client_name(): void
    {
        $client = $this->client();
        $client->forceFill(['name' => '<script>alert(1)</script>'])->save();
        Auth::guard('web')->login($this->user());
        $this->authorize($client)->assertOk()
            ->assertDontSee('<script', false)
            ->assertDontSee('cdn.tailwindcss.com', false)
            ->assertSee('&lt;script&gt;', false);
    }
}
