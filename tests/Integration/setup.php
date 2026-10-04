<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Tests\Integration\Admin;
use Admin9\OidcServer\Tests\Integration\HostApplication;
use Admin9\OidcServer\Tests\Integration\Member;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\ClientRepository;

$runtime = $argv[1] ?? throw new RuntimeException('Usage: php tests/Integration/setup.php <new-runtime-directory> <redis-socket>');
if (file_exists($runtime)) {
    throw new RuntimeException('Runtime must be a new directory.');
}
mkdir($runtime, 0700, true);
foreach (['storage/framework/views', 'storage/framework/cache/data', 'storage/logs'] as $path) {
    mkdir($runtime.'/'.$path, 0700, true);
}
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $private);
file_put_contents($runtime.'/private.pem', $private);
chmod($runtime.'/private.pem', 0600);
file_put_contents($runtime.'/public.pem', openssl_pkey_get_details($key)['key']);
$settings = ['app_key' => 'base64:'.base64_encode(random_bytes(32)), 'issuer' => 'http://127.0.0.1:18991',
    'redis_socket' => $argv[2], 'totp_secret' => bin2hex(random_bytes(20)), 'rp_callback' => 'http://127.0.0.1:18992/callback'];
file_put_contents($runtime.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
touch($runtime.'/database.sqlite');
putenv('OIDC_TEST_RUNTIME='.$runtime);
$app = HostApplication::create();
Artisan::call('migrate', ['--path' => [realpath(dirname(__DIR__, 2).'/vendor/laravel/passport/database/migrations')], '--realpath' => true, '--force' => true]);
foreach (['users', 'admins'] as $table) {
    Schema::create($table, function (Blueprint $table): void {
        $table->id(); $table->string('name'); $table->string('email')->unique(); $table->string('password');
        $table->rememberToken(); $table->timestamps();
    });
}
Member::forceCreate(['name' => 'Member', 'email' => 'member@fixture.test', 'password' => Hash::make('fixture-password')]);
Admin::forceCreate(['name' => 'Admin', 'email' => 'admin@fixture.test', 'password' => Hash::make('fixture-password')]);
$repository = app(ClientRepository::class);
$client = method_exists($repository, 'createAuthorizationCodeGrantClient')
    ? $repository->createAuthorizationCodeGrantClient('Independent RP', [$settings['rp_callback']], false)
    : $repository->create(null, 'Independent RP', $settings['rp_callback'], null, false, false, false);
$settings['client_id'] = (string) $client->getKey();
file_put_contents($runtime.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "Fixture initialized at ".$runtime."\n";
