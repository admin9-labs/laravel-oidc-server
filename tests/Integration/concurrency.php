<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\Integration\HostApplication;
use Admin9\OidcServer\Tests\Integration\HttpScenario;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Redis;
use Laravel\Passport\Passport;

$runtime = getenv('OIDC_TEST_RUNTIME') ?: throw new RuntimeException('Set OIDC_TEST_RUNTIME.');
HostApplication::create();
set_exception_handler(function (\Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
});
$settings = config('oidc_integration');
$results = ['environment' => ['origins' => array_values(array_unique([$settings['issuer'], $settings['peer_origin'] ?? $settings['issuer']])),
    'php' => PHP_VERSION, 'process_termination' => 'SIGKILL']];
file_put_contents($runtime.'/concurrency-results.json', '{}');
$counts = fn () => [Passport::authCode()->count(), Passport::token()->count(), Passport::refreshToken()->count()];
$record = function (string $name, array $evidence) use (&$results, $runtime): void {
    $results[$name] = ['passed' => true] + $evidence;
    file_put_contents($runtime.'/concurrency-results.json', json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo $name.": passed\n";
};
$winner = function (array $responses, int $status): array {
    $successes = array_values(array_filter($responses, fn ($response) => $response['status'] === $status));
    HttpScenario::check(count($successes) === 1, 'Expected exactly one successful request.');
    foreach ($responses as $response) {
        HttpScenario::check(in_array($response['status'], [$status, 400, 419], true), 'Unexpected competing request status: '.$response['status']);
    }

    return $successes[0];
};
$assertTerminated = function () use ($runtime): void {
    $observed = json_decode(file_get_contents($runtime.'/fault-observed.json'), true, 512, JSON_THROW_ON_ERROR);
    $process = new \Symfony\Component\Process\Process(['ps', '-p', (string) $observed['pid'], '-o', 'stat=']);
    $process->run();
    $status = trim($process->getOutput());
    HttpScenario::check($status === '' || str_contains($status, 'Z'), 'The HTTP worker is still alive; request termination is not process termination.');
};

// All requests below hit a real HTTP kernel in independent PHP worker processes.
$scenario = new HttpScenario($runtime);
$challenge = $scenario->challenge();
$before = $counts();
$responses = $scenario->race('/member/reauthenticate', $challenge);
$completed = $winner($responses, 302);
HttpScenario::check($counts() === $before, 'Challenge race persisted a token.');
$scenario->cookies->extractCookies(new Request('POST', $scenario->settings['issuer'].'/member/reauthenticate'),
    new Response($completed['status'], $completed['headers'], $completed['body']));
$record('challenge-completion-race', ['statuses' => array_column($responses, 'status'), 'database_unchanged' => true]);

$scenario = new HttpScenario($runtime);
$form = $scenario->consent();
$before = $counts();
$responses = $scenario->race('/oauth/authorize', $form);
$approved = $winner($responses, 302);
$after = $counts();
HttpScenario::check($after === [$before[0] + 1, $before[1], $before[2]], 'Approval race did not persist exactly one code.');
parse_str(parse_url((new Response(302, $approved['headers']))->getHeaderLine('Location'), PHP_URL_QUERY), $query);
$code = $query['code'];
$record('approval-race', ['statuses' => array_column($responses, 'status'), 'codes_created' => 1]);

$before = $counts();
$responses = $scenario->race('/oauth/token', $scenario->codeBody($code));
$tokens = json_decode($winner($responses, 200)['body'], true, 512, JSON_THROW_ON_ERROR);
$after = $counts();
HttpScenario::check($after === [$before[0], $before[1] + 1, $before[2] + 1], 'Code race persisted duplicate tokens.');
$record('code-exchange-race', ['statuses' => array_column($responses, 'status'), 'access_tokens_created' => 1, 'refresh_tokens_created' => 1]);

$before = $counts();
$responses = $scenario->race('/oauth/token', $scenario->refreshBody($tokens['refresh_token']));
$rotated = json_decode($winner($responses, 200)['body'], true, 512, JSON_THROW_ON_ERROR);
$after = $counts();
HttpScenario::check($after === [$before[0], $before[1] + 1, $before[2] + 1], 'Refresh race persisted duplicate tokens.');
$record('refresh-race', ['statuses' => array_column($responses, 'status'), 'access_tokens_created' => 1, 'refresh_tokens_created' => 1]);

// A real ten-second Laravel session lock expires while issuance waits for twelve seconds.
$scenario = new HttpScenario($runtime);
$form = $scenario->consent();
$scenario->fault('before-code-save', $form['transaction'], 'delay', 12);
$before = $counts();
$responses = $scenario->race('/oauth/authorize', $form, [0, 10500000]);
$issued = $winner($responses, 302);
$failed = array_values(array_filter($responses, fn ($response) => $response['status'] !== 302))[0];
HttpScenario::check(is_file($runtime.'/fault-observed.json'), 'Lock-expiry delay did not execute.');
HttpScenario::check($failed['finished'] < $issued['finished'], 'The second request did not finish while the first exceeded its lock lease.');
HttpScenario::check($counts()[0] === $before[0] + 1, 'Lease expiry allowed duplicate issuance.');
$record('expired-session-lock-lease', ['statuses' => array_column($responses, 'status'), 'concurrent_rejection_before_first_completed' => true, 'codes_created' => 1]);

$scenario = new HttpScenario($runtime);
$challenge = $scenario->challenge();
$before = $counts();
$responses = $scenario->parallel([
    ['path' => '/fixture/hold-session', 'body' => ['_token' => $challenge['_token']]],
    ['path' => '/member/reauthenticate', 'body' => $challenge, 'delay' => 10500000],
]);
HttpScenario::check($responses[0]['status'] === 200 && $responses[1]['status'] === 302, 'Old/new session overlap did not complete as expected.');
HttpScenario::check($responses[1]['finished'] < $responses[0]['finished'], 'The old session did not save after rotation.');
$oldRetry = $scenario->http->post('/member/reauthenticate', ['form_params' => $challenge]);
HttpScenario::check($oldRetry->getStatusCode() === 400, 'A restored old session snapshot advanced the consumed challenge.');
$form = $scenario->resume($responses[1]);
$approved = $scenario->http->post('/oauth/authorize', ['form_params' => $form]);
HttpScenario::check($approved->getStatusCode() === 302 && $counts()[0] === $before[0] + 1, 'The valid rotated session did not issue exactly one code.');
$record('late-save-from-pre-rotation-session', ['old_snapshot_retry' => 400, 'new_session_issued_once' => true]);

// Losing a positive marker must fail closed even while the encrypted refresh and DB rows are valid.
$payload = app(TokenVerifier::class)->encryptedPayload($rotated['refresh_token']);
$serverIdentity = Redis::connection()->info('server')['run_id'];
$key = 'oidc:freshness:'.hash('sha256', config('oidc-server.issuer')).':'.$serverIdentity.':refresh:'.hash('sha256', $payload['refresh_token_id']);
Redis::connection()->del($key);
$before = $counts();
$response = $scenario->http->post('/oauth/token', ['form_params' => $scenario->refreshBody($rotated['refresh_token'])]);
HttpScenario::check($response->getStatusCode() === 400 && json_decode((string) $response->getBody(), true)['error'] === 'invalid_grant', 'Missing positive state was accepted.');
HttpScenario::check($counts() === $before && ! Passport::refreshToken()->findOrFail($payload['refresh_token_id'])->revoked, 'Missing-state failure mutated tokens.');
$record('missing-refresh-state', ['database_unchanged' => true, 'old_refresh_not_revoked' => true]);

// Abrupt termination after the atomic consumption, before native token persistence.
$scenario = new HttpScenario($runtime);
$code = $scenario->code();
$payload = app(TokenVerifier::class)->encryptedPayload($code);
$scenario->fault('token-consumed', 'code:'.hash('sha256', $payload['auth_code_id']));
$before = $counts();
$scenario->race('/oauth/token', $scenario->codeBody($code), [0]);
HttpScenario::check(is_file($runtime.'/fault-observed.json'), 'Consumption crash did not execute.');
$assertTerminated();
HttpScenario::check($counts() === $before, 'Crash persisted a token after consumption.');
$retry = $scenario->http->post(($settings['peer_origin'] ?? $settings['issuer']).'/oauth/token', ['form_params' => $scenario->codeBody($code)]);
HttpScenario::check($retry->getStatusCode() === 400 && json_decode((string) $retry->getBody(), true)['error'] === 'invalid_grant', 'Retry after crash issued another result.');
$record('process-exit-after-code-consumption', ['database_unchanged' => true, 'retry' => 'invalid_grant']);

// Challenge completion consumes its revision before the session/response is saved.
$scenario = new HttpScenario($runtime);
$challenge = $scenario->challenge();
$scenario->fault('challenge-completed', $challenge['transaction']);
$before = $counts();
$scenario->race('/member/reauthenticate', $challenge, [0]);
HttpScenario::check(is_file($runtime.'/fault-observed.json'), 'Challenge completion crash did not execute.');
$assertTerminated();
$retry = $scenario->http->post(($settings['peer_origin'] ?? $settings['issuer']).'/member/reauthenticate', ['form_params' => $challenge]);
HttpScenario::check(in_array($retry->getStatusCode(), [400, 419], true), 'Old session continued a consumed challenge.');
HttpScenario::check($counts() === $before, 'Challenge crash/retry persisted tokens.');
$record('process-exit-after-challenge-completion', ['retry_status' => $retry->getStatusCode(), 'database_unchanged' => true]);

$scenario = new HttpScenario($runtime);
$form = $scenario->consent();
$scenario->fault('before-code-save', $form['transaction']);
$before = $counts();
$scenario->race('/oauth/authorize', $form, [0]);
HttpScenario::check(is_file($runtime.'/fault-observed.json'), 'Approval consumption crash did not execute.');
$assertTerminated();
$retry = $scenario->http->post(($settings['peer_origin'] ?? $settings['issuer']).'/oauth/authorize', ['form_params' => $form]);
HttpScenario::check($retry->getStatusCode() === 400, 'A consumed approval resumed after worker termination.');
HttpScenario::check($counts() === $before, 'Approval crash/retry persisted a code or token.');
$record('process-exit-after-approval-consumption', ['retry_status' => $retry->getStatusCode(), 'database_unchanged' => true]);

echo "Full HTTP concurrency evidence saved in the private fixture runtime.\n";
