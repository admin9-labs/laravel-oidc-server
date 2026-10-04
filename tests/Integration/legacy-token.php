<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Tests\Integration\HostApplication;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Laravel\Passport\Bridge\User;
use League\OAuth2\Server\AuthorizationServer;

HostApplication::create();
$settings = config('oidc_integration');
$server = app(AuthorizationServer::class);
$verifier = str_repeat('x', 64);
$authorization = $server->validateAuthorizationRequest((new ServerRequest('GET', '/native/authorize'))->withQueryParams([
    'client_id' => $settings['client_id'], 'redirect_uri' => $settings['rp_callback'], 'response_type' => 'code', 'scope' => 'openid',
    'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
]));
$authorization->setUser(new User('1'));
$authorization->setAuthorizationApproved(true);
$response = $server->completeAuthorizationRequest($authorization, new Response);
parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
$response = $server->respondToAccessTokenRequest((new ServerRequest('POST', '/native/token'))->withParsedBody([
    'grant_type' => 'authorization_code', 'client_id' => $settings['client_id'], 'code' => $query['code'],
    'redirect_uri' => $settings['rp_callback'], 'code_verifier' => $verifier,
]), new Response);
$tokens = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
$path = getenv('OIDC_TEST_RUNTIME').'/legacy-refresh.json';
file_put_contents($path, json_encode(['refresh_token' => $tokens['refresh_token']], JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo "Created an unrevoked native/legacy refresh fixture.\n";
