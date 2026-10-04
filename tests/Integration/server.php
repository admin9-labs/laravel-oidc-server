<?php

declare(strict_types=1);

$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
$baseline = getenv('OIDC_TEST_BASELINE');
if (is_string($baseline) && $baseline !== '') {
    $loader->addPsr4('Admin9\\OidcServer\\', $baseline.'/src', true);
}
$runtime = getenv('OIDC_TEST_RUNTIME');
if (is_file($runtime.'/issuance-gate.closed')
    && in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ['/oauth/authorize', '/oauth/token'], true)) {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'temporarily_unavailable']);
    return;
}

$app = \Admin9\OidcServer\Tests\Integration\HostApplication::create();
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
