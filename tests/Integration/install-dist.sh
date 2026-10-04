#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
candidate_sha=${1:?Usage: install-dist.sh <full-commit-sha> [exact-release-version]}
candidate_constraint=${2:-dev-main#$candidate_sha}
[[ "$candidate_sha" =~ ^[0-9a-f]{40}$ ]]
fixture_root=$(mktemp -d /tmp/oidc-dist.XXXXXX)
export COMPOSER_HOME="$fixture_root/composer-home"
export COMPOSER_CACHE_DIR="$fixture_root/composer-cache"
mkdir "$fixture_root/expected"
git archive "$candidate_sha" | tar -x -C "$fixture_root/expected"
composer create-project laravel/laravel "$fixture_root/host" '^13.0' \
  --no-install --no-scripts --no-interaction --prefer-dist
cd "$fixture_root/host"
# Before indexing, use the public repository; an exact release uses Packagist alone.
if [[ $# -lt 2 ]]; then
  composer config repositories.oidc vcs https://github.com/admin9-labs/laravel-oidc-server
fi
composer require "admin9/laravel-oidc-server:$candidate_constraint" 'laravel/passport:^13.0' \
  --update-no-dev --no-interaction --prefer-dist
composer check-platform-reqs --no-dev
diff -r "$fixture_root/expected" vendor/admin9/laravel-oidc-server
[[ ! -L vendor/admin9/laravel-oidc-server && ! -d vendor/admin9/laravel-oidc-server/.git ]]
cp .env.example .env
php artisan key:generate --no-interaction
php artisan vendor:publish --tag=oidc-server-config --no-interaction
php artisan passport:keys --no-interaction
php artisan config:cache
php artisan route:cache
php /dev/stdin "$candidate_sha" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$sha = $argv[1];
$metadata = json_decode(file_get_contents('vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$package = array_values(array_filter($metadata['packages'], fn ($p) => $p['name'] === 'admin9/laravel-oidc-server'))[0];
if ($package['installation-source'] !== 'dist' || $package['dist']['reference'] !== $sha || $metadata['dev']) {
    throw new RuntimeException('Expected fixed-SHA dist installation without development dependencies.');
}
foreach ($metadata['packages'] as $dependency) {
    if (($dependency['dist']['type'] ?? null) === 'path' || ($dependency['source']['type'] ?? null) === 'path') {
        throw new RuntimeException('Local path dependency found.');
    }
}
$app = require getcwd().'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$responses = [];
foreach (['/.well-known/openid-configuration', '/.well-known/jwks.json'] as $uri) {
    $request = Illuminate\Http\Request::create('http://localhost'.$uri);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('HTTP smoke failed: '.$uri.' '.$response->getContent());
    }
    $responses[$uri] = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $kernel->terminate($request, $response);
}
if (! in_array('auth_time', $responses['/.well-known/openid-configuration']['claims_supported'], true)
    || count($responses['/.well-known/jwks.json']['keys']) !== 1) {
    throw new RuntimeException('Discovery or signing-key publication failed.');
}
$html = view('oidc-server::authorize', [
    'client' => (object) ['name' => 'Clean dist RP'], 'scopes' => [],
    'transactionId' => 'fixture-transaction', 'authToken' => 'fixture-approval',
])->render();
if (! str_contains($html, 'name="transaction"') || ! str_contains($html, 'name="auth_token"')) {
    throw new RuntimeException('Distributed consent view failed.');
}
$evidence = ['sha' => $sha, 'version' => $package['version'], 'source' => $package['dist'],
    'php' => PHP_VERSION, 'laravel' => Illuminate\Foundation\Application::VERSION,
    'passport' => Composer\InstalledVersions::getPrettyVersion('laravel/passport'),
    'installation' => 'dist', 'dev_dependencies' => false, 'archive_matches' => true,
    'provider_discovery' => true, 'config_route_cache' => true,
    'discovery_jwks_http' => true, 'consent_view' => true];
file_put_contents('dist-results.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo file_get_contents('dist-results.json');
PHP
echo "Clean installation evidence: $fixture_root/host/dist-results.json"
