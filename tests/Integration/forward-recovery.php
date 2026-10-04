<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\Integration\HostApplication;
use Admin9\OidcServer\Tests\Integration\HttpScenario;
use Laravel\Passport\Passport;

$runtime = getenv('OIDC_TEST_RUNTIME') ?: throw new RuntimeException('Set OIDC_TEST_RUNTIME.');
HostApplication::create();
set_exception_handler(function (Throwable $exception) use ($runtime): void {
    touch($runtime.'/issuance-gate.closed');
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
});
$old = json_decode(file_get_contents($runtime.'/rollback-output-refresh.json'), true, 512, JSON_THROW_ON_ERROR);
$payload = app(TokenVerifier::class)->encryptedPayload($old['refresh_token']);
HttpScenario::check(! Passport::refreshToken()->findOrFail($payload['refresh_token_id'])->revoked, 'The forward-recovery sample must be valid and unrevoked.');
HttpScenario::check(is_file($runtime.'/issuance-gate.closed'), 'Keep issuance closed until all 2.0 workers are running.');
$scenario = new HttpScenario($runtime);
foreach (array_unique([$scenario->settings['issuer'], $scenario->settings['peer_origin'] ?? $scenario->settings['issuer']]) as $origin) {
    $discovery = json_decode((string) $scenario->http->get($origin.'/.well-known/openid-configuration')->getBody(), true);
    HttpScenario::check(in_array('auth_time', $discovery['claims_supported'] ?? [], true), 'A target node has not restored authentication freshness support.');
}
$before = [Passport::token()->count(), Passport::refreshToken()->count()];
$body = $scenario->refreshBody($old['refresh_token']);
HttpScenario::check($scenario->http->post('/oauth/token', ['form_params' => $body])->getStatusCode() === 503, 'Forward-cutover gate was not closed.');
unlink($runtime.'/issuance-gate.closed');
$response = $scenario->http->post('/oauth/token', ['form_params' => $body]);
HttpScenario::check($response->getStatusCode() === 400 && json_decode((string) $response->getBody(), true)['error'] === 'invalid_grant', '2.0 accepted the rollback-produced old format.');
HttpScenario::check($before === [Passport::token()->count(), Passport::refreshToken()->count()], 'Old-format failure persisted tokens.');
HttpScenario::check(! Passport::refreshToken()->findOrFail($payload['refresh_token_id'])->revoked, 'Old-format rejection silently revoked the sample.');
$response = $scenario->http->post('/oauth/token', ['form_params' => $scenario->codeBody($scenario->code())]);
HttpScenario::check($response->getStatusCode() === 200, 'New authorization after forward cutover failed.');
$tokens = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
$claims = app(TokenVerifier::class)->signedJwt($tokens['id_token'])->claims();
HttpScenario::check(is_int($claims->get('auth_time')), 'Forward recovery did not restore real authentication time.');
file_put_contents($runtime.'/forward-recovery-results.json', json_encode(['passed' => true, 'old_format' => 'invalid_grant',
    'old_refresh_not_revoked' => true, 'new_authorization_restores_auth_time' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Forward cutover rejects rollback materials and restores authenticated authorization: passed.\n";
