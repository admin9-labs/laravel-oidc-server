<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

$job = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
usleep($job['delay'] ?? 0);
$started = microtime(true);
try {
    $response = (new \GuzzleHttp\Client)->request($job['method'], $job['url'], [
        'http_errors' => false, 'allow_redirects' => false, 'timeout' => 40,
        'headers' => $job['headers'] ?? [], 'form_params' => $job['body'] ?? [],
    ]);
    $result = ['status' => $response->getStatusCode(), 'headers' => $response->getHeaders(), 'body' => (string) $response->getBody()];
} catch (\Throwable $exception) {
    $result = ['status' => 0, 'error' => get_class($exception)];
}
$result['started'] = $started;
$result['finished'] = microtime(true);
file_put_contents($argv[2], json_encode($result, JSON_THROW_ON_ERROR));
chmod($argv[2], 0600);
