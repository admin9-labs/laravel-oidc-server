<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\PassportKeys;
use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\PassportTestCase;
use Defuse\Crypto\Crypto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

class AuthorizationContextTest extends PassportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->post('/test-login', function (\Illuminate\Http\Request $request) {
            if (! Auth::guard('web')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
                return response('', 401);
            }

            return response('', 204);
        });
        Route::middleware('web')->get('/login', fn () => 'login')->name('login');
    }

    protected function code(Client $client, array $parameters = []): string
    {
        $this->authorize($client, $parameters)->assertOk();
        $response = $this->post('/oauth/authorize', [
            'auth_token' => session('authToken'), 'nonce' => 'approval-injection', 'max_age' => 999999,
        ])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['code'];
    }

    protected function exchange(Client $client, string $code, array $parameters = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/oauth/token', array_merge([
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'code' => $code,
            'redirect_uri' => 'https://rp.example/callback', 'code_verifier' => str_repeat('a', 64),
        ], $parameters));
    }

    protected function refresh(Client $client, string $token, array $parameters = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/oauth/token', array_merge([
            'grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $token,
        ], $parameters));
    }

    protected function claims(string $jwt): \Lcobucci\JWT\Token\DataSet
    {
        $token = app(TokenVerifier::class)->signedJwt($jwt);
        $this->assertNotNull($token);

        return $token->claims();
    }

    protected function rewriteEnvelope(string $value, callable $change): string
    {
        $payload = app(TokenVerifier::class)->encryptedPayload($value);
        $payload = $change($payload);

        return Crypto::encryptWithPassword(json_encode($payload, JSON_THROW_ON_ERROR), app(PassportKeys::class)->encryptionKey());
    }

    public function test_nonce_is_bound_to_each_code_and_cannot_be_injected_at_redemption(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $codes = [];
        foreach ([null, '0', '', '  original + / 中文  ', 'second-tab'] as $nonce) {
            $codes[] = [$nonce, $this->code($client, ['nonce' => $nonce])];
        }
        // Redeem out of order, without the browser session or its current user.
        Auth::guard('web')->logout();
        session()->flush();
        foreach (array_reverse($codes) as [$nonce, $code]) {
            $response = $this->exchange($client, $code, ['nonce' => 'attacker-value'])->assertOk();
            $claims = $this->claims($response->json('id_token'));
            $this->assertSame($nonce !== null, $claims->has('nonce'));
            $this->assertSame($nonce, $claims->get('nonce'));
        }
    }

    public function test_new_authorization_replaces_pending_tab_without_mixing_nonce(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $this->authorize($client, ['nonce' => 'first'])->assertOk();
        $old = session('authToken');
        $this->authorize($client, ['nonce' => 'second'])->assertOk();
        $this->post('/oauth/authorize', ['auth_token' => $old])->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
        $code = $this->code($client, ['nonce' => 'third']);
        $this->assertSame('third', $this->claims($this->exchange($client, $code)->assertOk()->json('id_token'))->get('nonce'));
    }

    public function test_initial_and_refreshed_id_tokens_omit_authentication_time(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $response = $this->exchange($client, $this->code($client, ['nonce' => 'original']), ['auth_time' => time()])->assertOk();
        $this->assertFalse($this->claims($response->json('id_token'))->has('auth_time'));
        $context = app(TokenVerifier::class)->encryptedPayload($response->json('refresh_token'))['oidc'];
        $this->assertSame(2, $context['v']);
        $this->assertEqualsCanonicalizing(['v', 'nonce', 'identity', 'client_id', 'iss', 'sub'], array_keys($context));
        for ($i = 0; $i < 2; $i++) {
            $oldRefresh = $response->json('refresh_token');
            $response = $this->refresh($client, $oldRefresh, ['nonce' => 'injected', 'auth_time' => time()])->assertOk();
            $claims = $this->claims($response->json('id_token'));
            $this->assertFalse($claims->has('auth_time'));
            $this->assertFalse($claims->has('nonce'));
            $this->assertSame($context, app(TokenVerifier::class)->encryptedPayload($response->json('refresh_token'))['oidc']);
            $legacy = property_exists(Passport::class, 'hashesClientSecrets');
            $this->refresh($client, $oldRefresh)->assertStatus($legacy ? 401 : 400)
                ->assertJsonPath('error', $legacy ? 'invalid_request' : 'invalid_grant');
        }
    }

    public function test_max_age_presence_is_rejected_without_changing_the_session(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $this->authorize($client, ['nonce' => 'pending'])->assertOk();
        $pending = session('oidc.authorization_pending');
        foreach (['0', '000', '60', '', '-1', '1.5', '1e3', str_repeat('9', 30), ['bad']] as $age) {
            $response = $this->authorize($client, ['max_age' => $age, 'prompt' => 'login', 'state' => ' state + / 中文 '])->assertRedirect();
            $this->assertStringStartsWith('https://rp.example/callback?', $response->headers->get('Location'));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame('invalid_request', $query['error']);
            $this->assertStringContainsString('max_age', $query['error_description']);
            $this->assertSame(' state + / 中文 ', $query['state']);
            $this->assertAuthenticated('web');
            $this->assertSame($pending, session('oidc.authorization_pending'));
        }
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_unsupported_freshness_does_not_start_guest_login(): void
    {
        $client = $this->client();
        foreach (['none', 'login'] as $prompt) {
            $response = $this->authorize($client, ['max_age' => '0', 'prompt' => $prompt])->assertRedirect();
            $this->assertStringStartsWith('https://rp.example/callback?', $response->headers->get('Location'));
            $this->assertStringContainsString('error=invalid_request', $response->headers->get('Location'));
        }
        $this->assertFalse(session()->has('url.intended'));
        $this->assertFalse(session()->has('authToken'));
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_essential_auth_time_and_malformed_claims_are_rejected(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        foreach ([
            '{"id_token":{"auth_time":{"essential":true}}}',
            '{"userinfo":{"auth_time":{"essential":true}}}',
            '{', '[]', 'null', '"claims"', '', ['invalid'],
            '{"id_token":[]}', '{"userinfo":null}', '{"id_token":{"email":true}}',
            '{"id_token":{"auth_time":{"essential":"true"}}}',
        ] as $claims) {
            $response = $this->authorize($client, ['claims' => $claims])->assertRedirect();
            $this->assertStringContainsString('error=invalid_request', $response->headers->get('Location'));
            $this->assertStringContainsString('state=test-state', $response->headers->get('Location'));
            $this->assertAuthenticated('web');
        }
        $this->assertFalse(session()->has('authToken'));
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_other_valid_claims_requests_do_not_enable_auth_time(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        foreach (['{}', '{"id_token":{"email":null,"auth_time":{"essential":false}}}',
            '{"userinfo":{"name":{"essential":true}}}'] as $claims) {
            $response = $this->exchange($client, $this->code($client, ['claims' => $claims]))->assertOk();
            $this->assertFalse($this->claims($response->json('id_token'))->has('auth_time'));
        }
    }

    public function test_invalid_parameters_and_unregistered_redirect_do_not_logout(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        foreach ([['nonce' => ['bad']], ['nonce' => "\xff"], ['prompt' => 'none login'], ['prompt' => ['login']]] as $parameters) {
            $response = $this->authorize($client, $parameters)->assertRedirect();
            $this->assertStringContainsString('error=invalid_request', $response->headers->get('Location'));
            $this->assertAuthenticated('web');
        }
        foreach ([['max_age' => 0], ['claims' => '{"id_token":{"auth_time":{"essential":true}}}']] as $parameters) {
            $response = $this->authorize($client, $parameters + ['redirect_uri' => 'https://attacker.example']);
            $this->assertContains($response->getStatusCode(), [400, 401]);
            $this->assertFalse($response->headers->has('Location'));
            $this->assertAuthenticated('web');
        }
    }

    public function test_wrong_client_pkce_code_replay_and_ciphertext_tampering_still_fail(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $code = $this->code($client, ['nonce' => 'bound']);
        $this->exchange($this->client(), $code)->assertStatus(400);
        $this->exchange($client, $code, ['code_verifier' => str_repeat('b', 64)])->assertStatus(400);
        $this->exchange($client, substr($code, 0, -4).'0000')->assertStatus(400);
        $expired = $this->rewriteEnvelope($code, fn ($payload) => array_replace($payload, ['expire_time' => time() - 1]));
        $this->exchange($client, $expired)->assertStatus(400);
        $this->exchange($client, $code)->assertOk();
        $this->exchange($client, $code)->assertStatus(400);
        $this->assertSame(1, Passport::token()->count());
    }

    public function test_old_oidc_codes_restart_but_oauth_only_codes_and_legacy_refresh_still_work(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $strip = function (array $payload): array { unset($payload['oidc']); return $payload; };
        $oldCode = $this->rewriteEnvelope($this->code($client), $strip);
        $this->exchange($client, $oldCode, ['nonce' => 'cannot-recover'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, Passport::token()->count());
        $oauthCode = $this->rewriteEnvelope($this->code($client, ['scope' => 'profile']), $strip);
        $this->exchange($client, $oauthCode)->assertOk()->assertJsonMissingPath('id_token');
        $tokens = $this->exchange($client, $this->code($client))->assertOk();
        $oldRefresh = $this->rewriteEnvelope($tokens->json('refresh_token'), $strip);
        $response = $this->refresh($client, $oldRefresh, ['nonce' => 'injection'])->assertOk()->assertJsonMissingPath('id_token');
        $this->refresh($client, $response->json('refresh_token'))->assertOk()->assertJsonMissingPath('id_token');
    }

    public function test_malformed_context_is_rejected_before_creating_tokens(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $code = $this->code($client);
        foreach ([['v' => 1], ['v' => 99], ['auth_time' => time()], ['max_age' => 60], ['nonce' => ['wrong']], ['identity' => ['web', 'model', 'another-user']]] as $change) {
            $malformed = $this->rewriteEnvelope($code, fn ($payload) => array_replace($payload, ['oidc' => array_replace($payload['oidc'], $change)]));
            $this->exchange($client, $malformed)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        }
        $this->assertSame(0, Passport::token()->count());
    }

    public function test_candidate_v1_refresh_and_pending_consent_require_new_authorization(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $tokens = $this->exchange($client, $this->code($client))->assertOk();
        foreach ([1, 99] as $version) {
            $old = $this->rewriteEnvelope($tokens->json('refresh_token'), function ($payload) use ($version) {
                $payload['oidc']['v'] = $version;

                return $payload;
            });
            $this->refresh($client, $old)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        }
        $this->assertSame(1, Passport::token()->count());
        $this->assertFalse(Passport::refreshToken()->first()->revoked);

        $this->authorize($client)->assertOk();
        session()->put('oidc.authorization_pending.context.v', 1);
        $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertStatus(400);
        $this->assertSame(1, Passport::authCode()->count());
    }

    public function test_custom_claims_cannot_override_protocol_binding(): void
    {
        config(['oidc-server.scopes.openid.claims' => ['sub', 'nonce', 'auth_time'], 'oidc-server.claims_resolver' => [
            'nonce' => fn () => 'overridden', 'auth_time' => fn () => 1,
        ]]);
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $response = $this->exchange($client, $this->code($client, ['nonce' => 'original']))->assertOk();
        $claims = $this->claims($response->json('id_token'));
        $this->assertSame('original', $claims->get('nonce'));
        $this->assertFalse($claims->has('auth_time'));
    }

    public function test_remember_cookie_and_subsequent_login_cannot_add_auth_time(): void
    {
        $client = $this->client();
        $user = $this->user();
        $user->forceFill(['password' => bcrypt('correct-password'), 'remember_token' => 'remembered'])->save();
        $name = Auth::guard('web')->getRecallerName();
        Route::middleware('web')->get('/remember-check', fn () => ['id' => Auth::guard('web')->id()]);
        session()->flush();
        Auth::forgetGuards();
        $this->withCookie($name, $user->id.'|remembered|'.$user->password)
            ->get('/remember-check')->assertOk()->assertJsonPath('id', $user->id);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $response = $this->exchange($client, $this->code($client))->assertOk();
        $this->assertFalse($this->claims($response->json('id_token'))->has('auth_time'));
        $this->post('/test-login', ['email' => $user->email, 'password' => 'correct-password', 'remember' => true])->assertNoContent();
        $response = $this->exchange($client, $this->code($client))->assertOk();
        $this->assertFalse($this->claims($response->json('id_token'))->has('auth_time'));
    }

    public function test_explicit_trusted_client_skipping_consent_keeps_context(): void
    {
        $original = Passport::clientModel();
        Passport::useClientModel(ContextTrustedClient::class);
        try {
            $client = $this->client();
            Auth::guard('web')->login($this->user());
            $response = $this->authorize($client, ['nonce' => 'trusted-client'])->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame('trusted-client', $this->claims($this->exchange($client, $query['code'])->assertOk()->json('id_token'))->get('nonce'));
        } finally {
            Passport::useClientModel($original);
        }
    }

    public function test_real_csrf_still_protects_approval_with_context(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $this->authorize($client, ['nonce' => 'csrf-bound'])->assertOk();
        $authToken = session('authToken');
        $csrf = csrf_token();
        $this->app['env'] = 'production';
        try {
            $this->post('/oauth/authorize', ['auth_token' => $authToken])->assertStatus(419);
            $this->assertSame(0, Passport::authCode()->count());
            $response = $this->post('/oauth/authorize', ['auth_token' => $authToken, '_token' => $csrf])->assertRedirect();
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertSame('csrf-bound', $this->claims($this->exchange($client, $query['code'])->assertOk()->json('id_token'))->get('nonce'));
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_native_prompt_login_returns_through_the_intended_url(): void
    {
        config(['auth.guards.second_web' => ['driver' => 'session', 'provider' => 'users']]);
        $client = $this->client();
        $user = $this->user();
        $user->forceFill(['password' => bcrypt('correct-password')])->save();
        Auth::guard('web')->login($user);
        Auth::guard('second_web')->login($user);
        $otherGuardKey = Auth::guard('second_web')->getName();
        $this->assertTrue(session()->has($otherGuardKey));
        session()->put('auth.password_confirmed_at', time());
        $this->authorize($client, ['prompt' => 'login', 'nonce' => 'exact nonce'])->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertFalse(session()->has($otherGuardKey));
        $this->assertFalse(session()->has('auth.password_confirmed_at'));
        $this->assertTrue(session('promptedForLogin'));
        $intended = session('url.intended');
        $this->post('/test-login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        $this->post('/test-login', ['email' => $user->email, 'password' => 'correct-password'])->assertNoContent();
        $this->get($intended)->assertOk();
        $approved = $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $query);
        $claims = $this->claims($this->exchange($client, $query['code'])->assertOk()->json('id_token'));
        $this->assertSame('exact nonce', $claims->get('nonce'));
        $this->assertFalse($claims->has('auth_time'));
    }

    public function test_native_guest_authorization_and_prompt_none_contracts(): void
    {
        $client = $this->client();
        $response = $this->authorize($client, ['prompt' => 'none'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(property_exists(Passport::class, 'hashesClientSecrets') ? 'access_denied' : 'login_required', $query['error']);
        $this->assertFalse(session()->has('url.intended'));
        $this->authorize($client, ['nonce' => 'guest'])->assertRedirect('/login');
        $user = $this->user();
        $user->forceFill(['password' => bcrypt('correct-password')])->save();
        $this->post('/test-login', ['email' => $user->email, 'password' => 'correct-password'])->assertNoContent();
        $this->get(session('url.intended'))->assertOk();
    }

    public function test_duplicate_context_parameters_are_rejected(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        foreach (['nonce=a&nonce=b', 'max_age=0&max_age=999', 'prompt=none&prompt=login',
            'max_age=0&max.age=999999', 'max_age=0&max+age=999999',
            'nonce=original&nonce%00=overridden', 'prompt=none&prompt%00=consent',
            'nonce=original&+nonce=overridden', 'nonce[]=array', 'max_age', 'max.age=0',
            'claims={}&claims={}', 'claims%00={}', 'claims[]=invalid'] as $duplicate) {
            $response = $this->get('/oauth/authorize?client_id='.$client->id.'&response_type=code&'.$duplicate)->assertRedirect();
            $this->assertStringStartsWith('https://rp.example/callback?', $response->headers->get('Location'));
            $this->assertStringContainsString('error=invalid_request', $response->headers->get('Location'));
        }
        $this->assertAuthenticated('web');
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_freshness_error_preserves_registered_query_and_exact_state(): void
    {
        $client = $this->client();
        $redirect = 'https://rp.example/callback?tenant=one';
        $client->forceFill(property_exists(Passport::class, 'hashesClientSecrets')
            ? ['redirect' => $redirect] : ['redirect_uris' => [$redirect]])->save();
        $response = $this->authorize($client, ['redirect_uri' => $redirect, 'max_age' => '', 'state' => '0'])->assertRedirect();
        $this->assertStringStartsWith($redirect.'&', $response->headers->get('Location'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('one', $query['tenant']);
        $this->assertSame('0', $query['state']);
        $this->assertSame('invalid_request', $query['error']);
        $this->assertFalse(session()->has('authToken'));
    }

    public function test_issuer_and_provider_changes_require_new_authorization(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $tokens = $this->exchange($client, $this->code($client))->assertOk();
        $oldIssuer = config('oidc-server.issuer');
        config(['oidc-server.issuer' => 'https://different.example']);
        $this->refresh($client, $tokens->json('refresh_token'))->assertStatus(400);
        config(['oidc-server.issuer' => $oldIssuer, 'passport.guard' => 'different']);
        $this->refresh($client, $tokens->json('refresh_token'))->assertStatus(400);
        config(['passport.guard' => 'web', 'oidc-server.user_model' => '\\'.\Admin9\OidcServer\Tests\Support\OidcUser::class]);
        $this->refresh($client, $tokens->json('refresh_token'))->assertOk();
    }

    public function test_head_does_not_reauthenticate_or_replace_pending_consent(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user());
        $this->authorize($client, ['nonce' => 'pending'])->assertOk();
        $pending = session('oidc.authorization_pending');
        $this->call('HEAD', '/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'response_type' => 'code', 'scope' => 'openid',
            'redirect_uri' => 'https://rp.example/callback', 'max_age' => 0, 'prompt' => 'login',
        ]))->assertOk();
        $this->assertAuthenticated('web');
        $this->assertSame($pending, session('oidc.authorization_pending'));
    }
}

class ContextTrustedClient extends Client
{
    protected $table = 'oauth_clients';

    public function skipsAuthorization(...$arguments): bool
    {
        return true;
    }
}
