<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\PassportTestCase;
use Laravel\Passport\Passport;

class RetainedPassportRoutesTest extends PassportTestCase
{
    protected function setUp(): void
    {
        Passport::$registersRoutes = true;
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        $app['config']->set('oidc-server.ignore_passport_routes', false);

        return parent::getPackageProviders($app);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('passport.path', 'legacy');
        $app['config']->set('oidc-server.ignore_passport_routes', false);
    }

    public function test_retained_authorization_routes_share_consent_and_pkce_policy(): void
    {
        $client = $this->client(true);
        $this->issueTokens($client);
        $parameters = [
            'client_id' => $client->id, 'redirect_uri' => 'https://rp.example/callback',
            'response_type' => 'code', 'scope' => 'openid',
            'nonce' => 'legacy-alias-nonce',
            'code_challenge' => str_repeat('a', 64), 'code_challenge_method' => 'plain',
        ];
        $this->get('/legacy/authorize?'.http_build_query($parameters))->assertStatus(400);
        $parameters['code_challenge_method'] = 'S256';
        $parameters['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '=');
        $this->get('/legacy/authorize?'.http_build_query($parameters + ['prompt' => 'none']))
            ->assertRedirect();
        $this->assertSame(1, Passport::authCode()->count());
        $this->get('/legacy/authorize?'.http_build_query($parameters))->assertOk();
        $approved = $this->post('/legacy/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $query);
        $tokens = $this->postJson('/legacy/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'redirect_uri' => 'https://rp.example/callback', 'code' => $query['code'],
            'code_verifier' => str_repeat('a', 64),
        ])->assertOk()->assertJsonStructure(['access_token', 'id_token']);
        $claims = app(\Admin9\OidcServer\Services\TokenVerifier::class)->signedJwt($tokens->json('id_token'))->claims();
        $this->assertSame('legacy-alias-nonce', $claims->get('nonce'));
        $this->assertFalse($claims->has('auth_time'));
    }

    public function test_retained_token_endpoint_rejects_pre_upgrade_plain_codes(): void
    {
        $key = app(\Admin9\OidcServer\Services\PassportKeys::class)->encryptionKey();
        $code = \Defuse\Crypto\Crypto::encryptWithPassword(json_encode([
            'code_challenge' => str_repeat('a', 64), 'code_challenge_method' => 'plain',
        ]), $key);
        $this->postJson('/legacy/token', ['grant_type' => 'authorization_code', 'code' => $code])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }
}
