<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\PassportKeys;
use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\PassportTestCase;
use Admin9\OidcServer\Tests\Support\FreshnessPrototype;
use Defuse\Crypto\Crypto;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;

class FreshnessExtensionPointsTest extends PassportTestCase
{
    private function authorization(AuthorizationServer $server, $client)
    {
        $authorization = $server->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams([
            'response_type' => 'code', 'client_id' => (string) $client->id,
            'redirect_uri' => 'https://rp.example/callback', 'scope' => 'profile', 'state' => 'exact state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
        $authorization->setUser(new User((string) $this->user()->id));
        $authorization->setAuthorizationApproved(true);

        return $authorization;
    }

    private function code(AuthorizationServer $server, $client): string
    {
        request()->attributes->set('prototype.approved', true);
        request()->attributes->set('prototype.context', ['v' => 3, 'authentication' => [
            'auth_time' => time(), 'generation' => bin2hex(random_bytes(32)),
        ]]);
        $response = $server->completeAuthorizationRequest($this->authorization($server, $client), new Response);
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);

        return $query['code'];
    }

    private function exchange(AuthorizationServer $server, $client, array $body): array
    {
        $response = $server->respondToAccessTokenRequest((new ServerRequest('POST', '/token'))->withParsedBody($body + [
            'client_id' => (string) $client->id, 'client_secret' => 'test-secret',
            'redirect_uri' => 'https://rp.example/callback', 'code_verifier' => str_repeat('a', 64),
        ]), new Response);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function invalidGrant(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected invalid_grant.');
        } catch (OAuthServerException $exception) {
            $response = $exception->generateHttpResponse(new Response);
            $this->assertSame(400, $response->getStatusCode());
            $this->assertSame('invalid_grant', json_decode((string) $response->getBody(), true)['error']);
        }
    }

    public function test_final_authorization_hook_runs_before_code_is_persisted(): void
    {
        $server = FreshnessPrototype::server();
        $authorization = $this->authorization($server, $this->client());
        $this->invalidGrant(fn () => $server->completeAuthorizationRequest($authorization, new Response));
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_code_decryption_hook_rejects_bad_context_before_any_token_is_persisted(): void
    {
        $server = FreshnessPrototype::server();
        $client = $this->client();
        $code = $this->code($server, $client);
        $payload = app(TokenVerifier::class)->encryptedPayload($code);
        unset($payload['oidc']['authentication']);
        $invalid = Crypto::encryptWithPassword(json_encode($payload), app(PassportKeys::class)->encryptionKey());
        $this->invalidGrant(fn () => $this->exchange($server, $client, ['grant_type' => 'authorization_code', 'code' => $invalid]));
        $this->assertSame(0, Passport::token()->count());
        $this->assertSame(0, Passport::refreshToken()->count());
        $this->assertFalse(Passport::authCode()->first()->revoked);
        $this->assertArrayHasKey('access_token', $this->exchange($server, $client, ['grant_type' => 'authorization_code', 'code' => $code]));
    }

    public function test_refresh_hook_precedes_revocation_and_persistence_and_native_server_is_independent(): void
    {
        // Resolve the package-configured server first so its global hooks have run.
        app(AuthorizationServer::class);
        $native = FreshnessPrototype::server(false);
        $client = $this->client();
        $tokens = $this->exchange($native, $client, ['grant_type' => 'authorization_code', 'code' => $this->code($native, $client)]);
        $this->assertArrayNotHasKey('id_token', $tokens);
        $this->assertArrayNotHasKey('oidc', app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']));
        $body = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']];
        $this->invalidGrant(fn () => $this->exchange(FreshnessPrototype::server(), $client, $body));
        $this->assertSame(1, Passport::token()->count());
        $this->assertSame(1, Passport::refreshToken()->count());
        $this->assertFalse(Passport::token()->first()->revoked);
        $this->assertFalse(Passport::refreshToken()->first()->revoked);
        $refreshed = $this->exchange($native, $client, $body);
        $this->assertArrayHasKey('access_token', $refreshed);
        $this->assertArrayNotHasKey('id_token', $refreshed);
    }
}
