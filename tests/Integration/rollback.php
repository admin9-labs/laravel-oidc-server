<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\Integration\HostApplication;
use Admin9\OidcServer\Tests\Integration\HttpScenario;
use GuzzleHttp\Client;
use Laravel\Passport\Passport;

$runtime = getenv('OIDC_TEST_RUNTIME') ?: throw new RuntimeException('Set OIDC_TEST_RUNTIME.');
HostApplication::create();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
});
$settings = config('oidc_integration');
$legacy = json_decode(file_get_contents($runtime.'/legacy-refresh.json'), true, 512, JSON_THROW_ON_ERROR);
$payload = app(TokenVerifier::class)->encryptedPayload($legacy['refresh_token']);
HttpScenario::check(! Passport::refreshToken()->findOrFail($payload['refresh_token_id'])->revoked, 'Rollback sample must still be unrevoked.');
HttpScenario::check(is_file($runtime.'/issuance-gate.closed'), 'Close issuance before starting rollback rehearsal.');
$http = new Client(['base_uri' => 'http://127.0.0.1:18994', 'http_errors' => false, 'allow_redirects' => false]);
$before = [Passport::authCode()->count(), Passport::token()->count(), Passport::refreshToken()->count()];
$authorization = ['client_id' => $settings['client_id'], 'response_type' => 'code', 'redirect_uri' => $settings['rp_callback'], 'scope' => 'openid',
    'max_age' => 60, 'prompt' => 'none', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('x', 64), true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
$body = ['grant_type' => 'refresh_token', 'client_id' => $settings['client_id'], 'refresh_token' => $legacy['refresh_token']];
HttpScenario::check($http->get('/oauth/authorize', ['query' => $authorization])->getStatusCode() === 503, 'Rollback authorization gate did not block.');
HttpScenario::check($http->post('/oauth/token', ['form_params' => $body])->getStatusCode() === 503, 'Rollback refresh gate did not block.');
HttpScenario::check($before === [Passport::authCode()->count(), Passport::token()->count(), Passport::refreshToken()->count()], 'Closed gate persisted credentials.');
HttpScenario::check(! Passport::refreshToken()->findOrFail($payload['refresh_token_id'])->revoked, 'The gate silently revoked the sample.');

// A controlled negative check proves the actual old package would accept that sample without the gate.
unlink($runtime.'/issuance-gate.closed');
try {
    $response = $http->get('/oauth/authorize', ['query' => $authorization]);
    HttpScenario::check($response->getStatusCode() === 302, 'The old package did not handle the authorization request.');
    parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $error);
    HttpScenario::check(($error['error'] ?? null) === 'invalid_request', 'The old package unexpectedly restored max_age capability.');
    $response = $http->post('/oauth/token', ['form_params' => $body]);
    $tokens = json_decode((string) $response->getBody(), true);
    HttpScenario::check($response->getStatusCode() === 200 && isset($tokens['access_token']), 'The old package did not accept the valid legacy refresh control.');
    HttpScenario::check(! isset($tokens['id_token']), 'The old no-context refresh unexpectedly issued an ID Token.');
    file_put_contents($runtime.'/rollback-output-refresh.json', json_encode(['refresh_token' => $tokens['refresh_token']], JSON_THROW_ON_ERROR));
    chmod($runtime.'/rollback-output-refresh.json', 0600);
} finally {
    touch($runtime.'/issuance-gate.closed');
}
file_put_contents($runtime.'/rollback-results.json', json_encode(['passed' => true, 'baseline' => '48b01c5',
    'closed_gate_blocks_authorization_and_refresh' => true, 'closed_gate_preserves_database' => true,
    'old_refresh_works_without_gate' => true, 'old_version_rejects_max_age' => true, 'gate_left_closed' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Actual baseline rollback gate and legacy-refresh negative control: passed. Gate remains closed.\n";
