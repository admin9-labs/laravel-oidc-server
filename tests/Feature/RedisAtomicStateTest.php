<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Services\RedisAtomicStateStore;
use Admin9\OidcServer\Tests\TestCase;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class RedisAtomicStateTest extends TestCase
{
    private ?Process $redis = null;
    private ?string $directory = null;
    private string $socket;
    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();
        $binary = (new ExecutableFinder)->find('redis-server');
        if (! $binary || ! extension_loaded('redis')) {
            $this->markTestSkipped('Real Redis concurrency acceptance requires redis-server and ext-redis.');
        }
        $this->binary = $binary;
        $this->directory = '/tmp/oidc-state-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->socket = $this->directory.'/redis.sock';
        $this->startRedis();
        config(['database.redis.client' => 'phpredis', 'database.redis.oidc_tests' => ['host' => $this->socket, 'port' => 0, 'database' => 0],
            'database.redis.options.prefix' => '', 'oidc-server.freshness.redis_connection' => 'oidc_tests']);
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
    }

    private function startRedis(): void
    {
        $this->redis = new Process([$this->binary, '--port', '0', '--unixsocket', $this->socket, '--save', '', '--appendonly', 'no', '--dir', $this->directory]);
        $this->redis->start();
        $this->redis->waitUntil(fn () => file_exists($this->socket));
    }

    protected function tearDown(): void
    {
        $this->redis?->stop();
        if ($this->directory !== null) {
            foreach (glob($this->directory.'/*') as $file) {
                @unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function worker(string $key, string $expected, string $replacement, int $delay = 0): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/atomic-state-worker.php', $this->socket, $key, $expected, $replacement, (string) $delay]);
        $process->start();

        return $process;
    }

    public function test_independent_processes_cannot_consume_the_same_state_twice(): void
    {
        $store = app(RedisAtomicStateStore::class);
        $this->assertTrue($store->create('race', 'ready', 60));
        $workers = [];
        for ($i = 0; $i < 6; $i++) {
            $workers[] = $this->worker('race', 'ready', 'consumed');
        }
        $winners = 0;
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $winners += (int) $worker->getOutput();
        }
        $this->assertSame(1, $winners);
        $this->assertSame('consumed', $store->read('race'));
    }

    public function test_delayed_old_session_and_process_exit_after_consumption_fail_closed(): void
    {
        $store = app(RedisAtomicStateStore::class);
        $store->create('session', 'before-rotation', 60);
        $oldRequest = $this->worker('session', 'before-rotation', 'duplicate', 1200000);
        // No lease is relied on: even a request delayed beyond a one-second lock cannot advance this revision.
        $newRequest = $this->worker('session', 'before-rotation', 'after-rotation');
        $newRequest->wait();
        $this->assertSame('1', $newRequest->getOutput());
        $oldRequest->wait();
        $this->assertSame('0', $oldRequest->getOutput());
        $retry = $this->worker('session', 'before-rotation', 'retry-after-crash');
        $retry->wait();
        $this->assertSame('0', $retry->getOutput());
        $this->assertSame('after-rotation', $store->read('session'));
    }

    public function test_missing_and_expired_positive_state_is_never_recreated_by_consumption(): void
    {
        $store = app(RedisAtomicStateStore::class);
        $store->create('expires', 'ready', 1);
        $worker = $this->worker('expires', 'ready', 'consumed', 1200000);
        $worker->wait();
        $this->assertSame('0', $worker->getOutput());
        $this->assertNull($store->read('expires'));
        $store->create('lost', 'ready', 60);
        Redis::connection('oidc_tests')->flushdb();
        $worker = $this->worker('lost', 'ready', 'consumed');
        $worker->wait();
        $this->assertSame('0', $worker->getOutput());
        $this->assertNull($store->read('lost'));
    }

    public function test_restart_with_a_pre_consumption_snapshot_does_not_resurrect_a_credential(): void
    {
        $store = app(RedisAtomicStateStore::class);
        $identity = Redis::connection('oidc_tests')->info('server')['run_id'];
        $this->assertTrue($store->create('rollback', 'ready', 60));
        Redis::connection('oidc_tests')->save();
        $this->assertTrue($store->replace('rollback', 'ready', 'consumed', 60));
        $this->redis->stop();
        $this->startRedis();
        app('redis')->purge('oidc_tests');
        $this->assertNotSame($identity, Redis::connection('oidc_tests')->info('server')['run_id']);
        $oldKey = 'oidc:freshness:'.hash('sha256', config('oidc-server.issuer')).':'.$identity.':rollback';
        $this->assertSame('ready', Redis::connection('oidc_tests')->get($oldKey), 'The restored snapshot must contain the unconsumed old value.');
        $this->assertNull($store->read('rollback'));
        $this->assertFalse($store->replace('rollback', 'ready', 'consumed-again', 60));
    }
}
