<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Admin9\OidcServer\Services\RedisAtomicStateStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;

$container = new Container;
Container::setInstance($container);
Facade::setFacadeApplication($container);
$container->instance('config', new Repository(['oidc-server' => ['issuer' => 'https://example.com', 'freshness' => ['redis_connection' => 'default']]]));
$container->instance('redis', new RedisManager($container, 'phpredis', ['default' => ['host' => $argv[1], 'port' => 0, 'database' => 0]]));
$store = new RedisAtomicStateStore;
usleep((int) $argv[5]);
echo $store->replace($argv[2], $argv[3], $argv[4], 60) ? '1' : '0';
// Deliberate process termination after consumption models a lost session/response write.
exit(0);
