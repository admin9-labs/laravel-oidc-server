<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\Integration\HostApplication;
use Admin9\OidcServer\Tests\Integration\HttpScenario;
use Illuminate\Support\Facades\Redis;
use Laravel\Passport\Passport;

$runtime = getenv('OIDC_TEST_RUNTIME') ?: throw new RuntimeException('Set OIDC_TEST_RUNTIME.');
HostApplication::create();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
});
$pid = (int) ($argv[1] ?? 0);
$actual = (int) Redis::connection()->info('server')['process_id'];
HttpScenario::check($pid > 1 && $pid === $actual, 'Pass the exact PID of the dedicated fixture Redis process.');
HttpScenario::check(str_starts_with(config('oidc_integration.redis_socket'), '/tmp/oidc-'), 'Only a dedicated /tmp/oidc-* Redis socket is allowed.');
$scenario = new HttpScenario($runtime);
$response = $scenario->http->post('/oauth/token', ['form_params' => $scenario->codeBody($scenario->code())]);
HttpScenario::check($response->getStatusCode() === 200, 'Could not prepare valid refresh token.');
$tokens = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
$refreshPayload = app(TokenVerifier::class)->encryptedPayload($tokens['refresh_token']);
$code = $scenario->code();
$before = [Passport::authCode()->count(), Passport::token()->count(), Passport::refreshToken()->count()];
posix_kill($pid, SIGTERM);
for ($i = 0; $i < 100 && file_exists(config('oidc_integration.redis_socket')); $i++) {
    usleep(10000);
}
HttpScenario::check(! file_exists(config('oidc_integration.redis_socket')), 'Fixture Redis did not stop.');
$statuses = [];
foreach ([$scenario->codeBody($code), $scenario->refreshBody($tokens['refresh_token'])] as $body) {
    $response = $scenario->http->post('/oauth/token', ['form_params' => $body]);
    $statuses[] = $response->getStatusCode();
    HttpScenario::check($response->getStatusCode() >= 500, 'Storage outage did not stop token issuance.');
}
$response = $scenario->http->get('/oauth/authorize', ['query' => ['client_id' => $scenario->settings['client_id'], 'response_type' => 'code',
    'redirect_uri' => $scenario->settings['rp_callback'], 'scope' => 'openid']]);
$statuses[] = $response->getStatusCode();
HttpScenario::check($response->getStatusCode() >= 500, 'Unavailable session lock did not stop authorization.');
HttpScenario::check($before === [Passport::authCode()->count(), Passport::token()->count(), Passport::refreshToken()->count()], 'Storage outage persisted a credential.');
HttpScenario::check(! Passport::refreshToken()->findOrFail($refreshPayload['refresh_token_id'])->revoked, 'Storage outage revoked the valid refresh.');
file_put_contents($runtime.'/storage-outage-results.json', json_encode(['passed' => true, 'statuses' => $statuses,
    'database_unchanged' => true, 'refresh_not_revoked' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Unavailable atomic state and session locks: passed. Dedicated Redis is now stopped.\n";
