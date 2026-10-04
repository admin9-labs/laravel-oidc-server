<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Tests\Integration\HostApplication;
use Admin9\OidcServer\Tests\Integration\HttpScenario;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Laravel\Passport\Passport;

$runtime = getenv('OIDC_TEST_RUNTIME') ?: throw new RuntimeException('Set OIDC_TEST_RUNTIME.');
HostApplication::create();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
});
$scenario = new HttpScenario($runtime);
$http = $scenario->http;
$host = $scenario->settings['issuer'];
$rp = 'http://127.0.0.1:18992';
$counts = fn () => [Passport::authCode()->count(), Passport::token()->count(), Passport::refreshToken()->count()];
$events = fn ($name) => is_file($runtime.'/'.$name.'.jsonl')
    ? array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($runtime.'/'.$name.'.jsonl', FILE_IGNORE_NEW_LINES)) : [];
$form = function ($response): array {
    $document = new DOMDocument;
    @$document->loadHTML((string) $response->getBody());
    $fields = [];
    foreach ((new DOMXPath($document))->query('//form[1]//input[@type="hidden"]') as $input) {
        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }

    return $fields;
};
$follow = function ($response, string &$url, bool $stopBeforeCallback = false) use ($http, $host) {
    for ($i = 0; $i < 12 && in_array($response->getStatusCode(), [302, 303], true); $i++) {
        $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
        if ($stopBeforeCallback && str_starts_with($url, $host.'/sso/callback?')) {
            return $response;
        }
        $response = $http->get($url);
    }

    return $response;
};
$get = function (string $target, string &$url) use ($http, $follow) {
    $url = $target;

    return $follow($http->get($url), $url);
};
$login = function ($view, string &$url) use ($http, $follow, $form) {
    HttpScenario::check(str_contains((string) $view->getBody(), 'Upstream login'), 'Expected actual upstream credential interaction.');
    $response = $http->post($url, ['form_params' => $form($view) + ['email' => 'member@upstream.test', 'password' => 'upstream-password']]);
    $response = $follow($response, $url);
    if (str_contains((string) $response->getBody(), 'Upstream consent')) {
        $response = $follow($http->post($url, ['form_params' => $form($response)]), $url);
    }

    return $response;
};
$result = ['upstream' => 'oidc-provider 9.12.2', 'verifier_and_rp' => 'openid-client 6.8.8', 'simulated_time' => false];
$before = $counts();
$url = $host.'/admin/login';
$admin = $http->get($url);
HttpScenario::check($http->post($url, ['form_params' => $form($admin) + ['email' => 'admin@fixture.test', 'password' => 'fixture-password']])->getStatusCode() === 302, 'Admin sign-in failed.');
$initialEvents = count($events('upstream-events'));
$view = $get($host.'/sso/start', $url);
$wrong = $http->post($url, ['form_params' => $form($view) + ['email' => 'member@upstream.test', 'password' => 'wrong-password']]);
HttpScenario::check($wrong->getStatusCode() === 401 && count($events('upstream-events')) === $initialEvents && $counts() === $before, 'Invalid upstream credentials advanced authentication.');
$response = $login($view, $url);
$bootstrap = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
HttpScenario::check($response->getStatusCode() === 200 && ($bootstrap['sso_authenticated'] ?? false), 'Initial real SSO sign-in failed.');
HttpScenario::check(count($events('upstream-events')) === $initialEvents + 1, 'Expected one real password event.');
$result['bootstrap'] = ['wrong_password_status' => 401, 'auth_time' => $bootstrap['auth_time'], 'password_events' => 1];

// Wall time really advances; no Carbon clock, forged assertion, login.ts or recorder timestamp override.
while (time() <= $bootstrap['auth_time']) {
    usleep(100000);
}
$http->get($host.'/sso/mode/silent');
$response = $get($rp.'/start?max_age=0&prompt=login', $url);
$silent = array_slice($events('sso-events'), -1)[0];
HttpScenario::check($response->getStatusCode() === 400 && $silent['cryptographically_verified'] && ! $silent['accepted']
    && $silent['auth_time'] === $bootstrap['auth_time'] && $silent['auth_time'] < $silent['challenge_issued_at']
    && count($events('upstream-events')) === $initialEvents + 1 && $counts() === $before, 'Silent SSO restoration satisfied a new authentication challenge.');
HttpScenario::check($http->get($url)->getStatusCode() === 400 && $counts() === $before, 'Consumed callback replay advanced authorization.');
$result['silent_restoration'] = $silent + ['status' => 400, 'new_password_events' => 0, 'database_unchanged' => true, 'callback_replay_status' => 400];

// A fresh code from the genuine SSO session still cannot pass an unrelated callback state.
$url = $rp.'/start?max_age=0&prompt=login';
$follow($http->get($url), $url, true);
$badState = preg_replace('/([?&])state=[^&]*/', '${1}state=unrelated', $url);
HttpScenario::check($http->get($badState)->getStatusCode() === 400 && $http->get($url)->getStatusCode() === 400 && $counts() === $before,
    'Wrong state or consumed state advanced authentication.');
$result['state_correlation'] = ['wrong_state_status' => 400, 'original_callback_after_consumption_status' => 400, 'database_unchanged' => true];

$http->get($host.'/sso/mode/fresh');
$view = $get($rp.'/start?max_age=0&prompt=login', $url);
$consent = $login($view, $url);
HttpScenario::check($consent->getStatusCode() === 200 && isset($form($consent)['transaction']), 'Fresh SSO did not reach downstream consent.');
$fresh = array_slice($events('sso-events'), -1)[0];
HttpScenario::check($fresh['accepted'] && $fresh['auth_time'] >= $fresh['challenge_issued_at'] && $fresh['auth_time'] > $bootstrap['auth_time']
    && count($events('upstream-events')) === $initialEvents + 2, 'Fresh SSO did not produce a new real event.');
$url = $host.'/oauth/authorize';
$verifiedPage = $follow($http->post($url, ['form_params' => $form($consent)]), $url);
$rpResult = json_decode((string) $http->get($rp.'/last-result')->getBody(), true, 512, JSON_THROW_ON_ERROR);
HttpScenario::check($verifiedPage->getStatusCode() === 200 && $rpResult['kind'] === 'authorization' && $rpResult['result'] === 'passed'
    && $rpResult['claims']['auth_time'] === $fresh['auth_time'] && $counts() === [$before[0] + 1, $before[1] + 1, $before[2] + 1], 'Independent RP did not verify upstream authentication time.');
$result['forced_reauthentication'] = $fresh + ['new_password_events' => 1, 'rp_verified' => true, 'codes_created' => 1, 'token_pairs_created' => 1];
$refresh = $http->post($rp.'/refresh', ['form_params' => $form($verifiedPage)]);
$rpResult = json_decode((string) $http->get($rp.'/last-result')->getBody(), true, 512, JSON_THROW_ON_ERROR);
HttpScenario::check($refresh->getStatusCode() === 200 && $rpResult['kind'] === 'refresh' && $rpResult['result'] === 'passed'
    && $rpResult['claims']['auth_time'] === $fresh['auth_time'] && ! isset($rpResult['claims']['nonce']), 'SSO authentication changed on refresh.');
$result['refresh'] = ['rp_verified' => true, 'auth_time' => $rpResult['claims']['auth_time'], 'nonce_absent' => true];
$status = (string) $http->get($host.'/')->getBody();
HttpScenario::check(str_contains($status, 'admin@fixture.test') && str_contains($status, 'member@fixture.test'), 'SSO destroyed another guard session.');
$result['guard_isolation'] = true;
file_put_contents($runtime.'/sso-results.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
echo "Real SSO: wrong credentials, silent stale event, callback replay/state, forced fresh event, independent RP, refresh and guard isolation passed.\n";
