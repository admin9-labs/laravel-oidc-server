<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

class HttpScenario
{
    public Client $http;
    public CookieJar $cookies;
    public string $verifier;
    public array $settings;

    public function __construct(public string $runtime)
    {
        $this->settings = json_decode(file_get_contents($runtime.'/settings.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->cookies = new CookieJar;
        $this->http = new Client(['base_uri' => $this->settings['issuer'], 'cookies' => $this->cookies,
            'http_errors' => false, 'allow_redirects' => false, 'timeout' => 40]);
        $this->verifier = bin2hex(random_bytes(32));
    }

    public static function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }

    public function challenge(): array
    {
        $response = $this->http->get('/oauth/authorize', ['query' => ['client_id' => $this->settings['client_id'],
            'redirect_uri' => $this->settings['rp_callback'], 'response_type' => 'code', 'scope' => 'openid', 'max_age' => 0,
            'state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)),
            'code_challenge_method' => 'S256', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
        ]]);
        self::check($response->getStatusCode() === 302, 'Authorization did not start a challenge.');
        $view = $this->http->get($response->getHeaderLine('Location'));
        self::check($view->getStatusCode() === 200, 'Challenge form was not available.');

        return $this->form($view) + ['email' => 'member@fixture.test', 'password' => 'fixture-password', 'code' => Totp::code($this->settings['totp_secret'])];
    }

    public function consent(): array
    {
        $response = $this->http->post('/member/reauthenticate', ['form_params' => $this->challenge()]);
        self::check($response->getStatusCode() === 302, 'Challenge completion failed.');
        $view = $this->http->get($response->getHeaderLine('Location'));
        self::check($view->getStatusCode() === 200, 'Consent was not displayed.');

        return $this->form($view);
    }

    public function code(): string
    {
        $response = $this->http->post('/oauth/authorize', ['form_params' => $this->consent()]);
        self::check($response->getStatusCode() === 302, 'Approval did not issue a code.');
        parse_str(parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $parameters);
        self::check(isset($parameters['code']), 'Approval returned an error.');

        return $parameters['code'];
    }

    public function codeBody(string $code): array
    {
        return ['grant_type' => 'authorization_code', 'client_id' => $this->settings['client_id'], 'code' => $code,
            'code_verifier' => $this->verifier, 'redirect_uri' => $this->settings['rp_callback']];
    }

    public function refreshBody(string $refresh): array
    {
        return ['grant_type' => 'refresh_token', 'client_id' => $this->settings['client_id'], 'refresh_token' => $refresh];
    }

    public function race(string $path, array $body, array $delays = [0, 0, 0, 0]): array
    {
        return $this->parallel(array_map(fn ($delay) => ['path' => $path, 'body' => $body, 'delay' => $delay], $delays));
    }

    public function parallel(array $jobs): array
    {
        $workers = [];
        foreach ($jobs as $index => $job) {
            $origin = $index % 2 === 1 ? ($this->settings['peer_origin'] ?? $this->settings['issuer']) : $this->settings['issuer'];
            $url = $origin.$job['path'];
            $cookie = $this->cookies->withCookieHeader(new Request('POST', $url))->getHeaderLine('Cookie');
            $file = $this->runtime.'/request-'.bin2hex(random_bytes(8));
            file_put_contents($file.'.json', json_encode(['method' => 'POST', 'url' => $url, 'body' => $job['body'],
                'headers' => ['Cookie' => $cookie, 'Accept' => 'application/json'], 'delay' => $job['delay'] ?? 0], JSON_THROW_ON_ERROR));
            chmod($file.'.json', 0600);
            $worker = new Process([PHP_BINARY, __DIR__.'/request-worker.php', $file.'.json', $file.'-result.json']);
            $worker->start();
            $workers[] = [$worker, $file];
        }
        $results = [];
        foreach ($workers as [$worker, $file]) {
            $worker->wait();
            self::check($worker->isSuccessful(), 'HTTP worker failed: '.$worker->getErrorOutput());
            $results[] = json_decode(file_get_contents($file.'-result.json'), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function resume(array $completed): array
    {
        $response = new \GuzzleHttp\Psr7\Response($completed['status'], $completed['headers'], $completed['body']);
        $this->cookies->extractCookies(new Request('POST', $this->settings['issuer'].'/member/reauthenticate'), $response);
        $view = $this->http->get($response->getHeaderLine('Location'));
        self::check($view->getStatusCode() === 200, 'The valid rotated session could not resume.');

        return $this->form($view);
    }

    public function fault(string $stage, string $id, string $action = 'exit', int $seconds = 0): void
    {
        @unlink($this->runtime.'/fault-active.json');
        @unlink($this->runtime.'/fault-observed.json');
        file_put_contents($this->runtime.'/fault.json', json_encode(compact('stage', 'id', 'action', 'seconds'), JSON_THROW_ON_ERROR));
    }

    private function form(ResponseInterface $response): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML((string) $response->getBody());
        $values = [];
        foreach ((new \DOMXPath($document))->query('//form[1]//input[@type="hidden"]') as $input) {
            $values[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::check(isset($values['_token'], $values['transaction']), 'Missing CSRF or transaction input.');

        return $values;
    }
}
