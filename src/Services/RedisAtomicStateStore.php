<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\AtomicStateStore;
use Illuminate\Support\Facades\Redis;

class RedisAtomicStateStore implements AtomicStateStore
{
    private const CHECK_SERVER = <<<'LUA'
redis.replicate_commands()
local identity = string.match(redis.call('INFO', 'server'), 'run_id:([a-f0-9]+)')
if identity ~= ARGV[1] then return redis.error_reply('OIDC atomic state server changed') end
LUA;

    public function create(string $key, string $value, int $seconds): bool
    {
        [$connection, $key, $identity] = $this->location($key);

        return (bool) $connection->eval(self::CHECK_SERVER."\n".
            "if redis.call('EXISTS', KEYS[1]) == 1 then return 0 end redis.call('SETEX', KEYS[1], ARGV[3], ARGV[2]) return 1",
            1, $key, $identity, $value, max(1, $seconds),
        );
    }

    public function read(string $key): ?string
    {
        [$connection, $key, $identity] = $this->location($key);
        $value = $connection->eval(self::CHECK_SERVER."\nreturn redis.call('GET', KEYS[1])", 1, $key, $identity);

        return is_string($value) ? $value : null;
    }

    public function replace(string $key, string $expected, string $value, int $seconds): bool
    {
        [$connection, $key, $identity] = $this->location($key);

        return (bool) $connection->eval(self::CHECK_SERVER."\n".
            "if redis.call('GET', KEYS[1]) ~= ARGV[2] then return 0 end redis.call('SETEX', KEYS[1], ARGV[4], ARGV[3]) return 1",
            1, $key, $identity, $expected, $value, max(1, $seconds),
        );
    }

    private function location(string $key): array
    {
        $connection = Redis::connection(config('oidc-server.freshness.redis_connection', 'default'));
        $info = $connection->info('server');
        $identity = $info['run_id'] ?? $info['Server']['run_id'] ?? null;
        if (! is_string($identity) || ! preg_match('/\A[a-f0-9]{40}\z/', $identity)) {
            throw new \RuntimeException('Cannot establish the atomic state server identity.');
        }

        return [$connection, 'oidc:freshness:'.hash('sha256', (string) config('oidc-server.issuer')).':'.$identity.':'.$key, $identity];
    }
}
