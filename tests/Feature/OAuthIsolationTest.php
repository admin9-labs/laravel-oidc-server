<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\OidcAuthorizationServer;
use Admin9\OidcServer\Tests\PassportTestCase;
use Admin9\OidcServer\Tests\Support\FreshnessPrototype;
use Defuse\Crypto\Crypto;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;

class OAuthIsolationTest extends PassportTestCase
{
    public function test_host_controller_and_native_server_can_issue_and_refresh_independently_of_package_entry(): void
    {
        app(OidcAuthorizationServer::class);
        $this->assertNotInstanceOf(OidcAuthorizationServer::class, app(AuthorizationServer::class));
        $nativeKey = bin2hex(random_bytes(32));
        $native = FreshnessPrototype::server(false, $nativeKey);
        app()->when(IsolatedHostTokenController::class)->needs(AuthorizationServer::class)->give(fn () => $native);
        Route::post('/host-oauth/token', [IsolatedHostTokenController::class, 'issueToken']);
        $client = $this->client();
        $authorization = $native->validateAuthorizationRequest((new ServerRequest('GET', '/host-oauth/authorize'))->withQueryParams([
            'response_type' => 'code', 'client_id' => (string) $client->id, 'redirect_uri' => 'https://rp.example/callback', 'scope' => 'openid',
        ]));
        $authorization->setUser(new User((string) $this->user()->id));
        $authorization->setAuthorizationApproved(true);
        $response = $native->completeAuthorizationRequest($authorization, new Response);
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $body = ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => 'test-secret',
            'code' => $query['code'], 'redirect_uri' => 'https://rp.example/callback'];
        $this->postJson('/oauth/token', $body)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertSame(0, Passport::token()->count());
        $tokens = $this->postJson('/host-oauth/token', $body)->assertOk()->assertJsonMissingPath('id_token')->json();
        $this->assertArrayNotHasKey('oidc', json_decode(Crypto::decryptWithPassword($tokens['refresh_token'], $nativeKey), true));
        $body = ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']];
        $this->postJson('/oauth/token', $body)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
        $this->postJson('/host-oauth/token', $body)->assertOk()->assertJsonMissingPath('id_token');

        $packageTokens = $this->issueTokens($client);
        $before = Passport::token()->count();
        $nativeAttempt = $this->postJson('/host-oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $packageTokens['refresh_token']]);
        $this->assertNotSame(200, $nativeAttempt->getStatusCode());
        $this->assertSame($before, Passport::token()->count());
        $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $packageTokens['refresh_token']])->assertOk();

        $view = $this->authorize($client)->assertOk();
        $approved = $this->post('/oauth/authorize', $this->consentForm($view))->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $query);
        $body = ['grant_type' => 'authorization_code', 'client_id' => $client->id, 'client_secret' => 'test-secret',
            'code' => $query['code'], 'redirect_uri' => 'https://rp.example/callback', 'code_verifier' => str_repeat('a', 64)];
        $before = Passport::token()->count();
        $this->assertNotSame(200, $this->postJson('/host-oauth/token', $body)->getStatusCode());
        $this->assertSame($before, Passport::token()->count());
        $this->postJson('/oauth/token', $body)->assertOk()->assertJsonStructure(['id_token']);
    }

    public function test_personal_access_factory_and_client_credentials_need_no_browser_record(): void
    {
        $repository = app(ClientRepository::class);
        if (method_exists($repository, 'createPersonalAccessGrantClient')) {
            $repository->createPersonalAccessGrantClient('Host personal access', 'users');
            $client = $repository->createClientCredentialsGrantClient('Service');
        } else {
            $repository->createPersonalAccessClient(null, 'Host personal access', 'https://host.example');
            $client = $repository->create(null, 'Service', 'https://host.example');
        }
        $user = $this->user();
        $personal = $user->createToken('Host token', ['openid']);
        $this->assertNotEmpty($personal->accessToken);
        $this->assertSame((string) $user->id, (string) $personal->token->user_id);
        $client->forceFill(['secret' => 'test-secret'])->save();
        $this->postJson('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'scope' => 'openid'])->assertOk()->assertJsonMissingPath('id_token')->assertJsonMissingPath('refresh_token');
        $this->assertFalse(session()->has('oidc.freshness'));
    }

    public function test_documented_native_password_and_refresh_recipe_is_separate_from_package_policy(): void
    {
        $repository = app(ClientRepository::class);
        $client = method_exists($repository, 'createPasswordGrantClient')
            ? $repository->createPasswordGrantClient('Host password client', 'users', true)
            : $repository->createPasswordGrantClient(null, 'Host password client', 'https://host.example', 'users');
        $client->forceFill(['secret' => 'test-secret'])->save();
        $user = $this->user();
        $user->forceFill(['password' => bcrypt('host-password')])->save();
        $native = FreshnessPrototype::server(false, bin2hex(random_bytes(32)));
        $grant = new \League\OAuth2\Server\Grant\PasswordGrant(app(\Laravel\Passport\Bridge\UserRepository::class), app(\Laravel\Passport\Bridge\RefreshTokenRepository::class));
        $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $native->enableGrantType($grant, Passport::tokensExpireIn());
        app()->when(IsolatedHostTokenController::class)->needs(AuthorizationServer::class)->give(fn () => $native);
        Route::post('/host-oauth/password-token', [IsolatedHostTokenController::class, 'issueToken']);
        $body = ['grant_type' => 'password', 'client_id' => $client->id, 'client_secret' => 'test-secret',
            'username' => $user->email, 'password' => 'host-password', 'scope' => 'openid'];
        $this->postJson('/oauth/token', $body)->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        $tokens = $this->postJson('/host-oauth/password-token', $body)->assertOk()->assertJsonMissingPath('id_token')->json();
        $body = ['grant_type' => 'refresh_token', 'client_id' => $client->id, 'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']];
        $this->postJson('/oauth/token', $body)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
        $this->postJson('/host-oauth/password-token', $body)->assertOk()->assertJsonMissingPath('id_token');
        $this->assertFalse(session()->has('oidc.freshness'));
    }
}

// A host-owned controller class is separate from the retained Passport controller aliases.
class IsolatedHostTokenController extends \Laravel\Passport\Http\Controllers\AccessTokenController {}
