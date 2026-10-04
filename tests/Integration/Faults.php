<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Integration;

/** Only the disposable local host loads this provider and fault file. */
class Faults
{
    public static function hit(string $stage, ?string $id): void
    {
        $runtime = getenv('OIDC_TEST_RUNTIME');
        $path = $runtime.'/fault.json';
        if (! is_file($path)) {
            return;
        }
        $fault = json_decode(file_get_contents($path), true);
        if (($fault['stage'] ?? null) !== $stage || ($fault['id'] ?? null) !== $id) {
            return;
        }
        if (! @rename($path, $runtime.'/fault-active.json')) {
            return;
        }
        file_put_contents($runtime.'/fault-observed.json', json_encode(['stage' => $stage, 'pid' => getmypid(), 'time' => microtime(true)]));
        if (($fault['action'] ?? null) === 'delay') {
            usleep((int) ($fault['seconds'] * 1000000));
        } else {
            if (! function_exists('posix_kill')) {
                throw new \RuntimeException('Process-termination acceptance requires ext-posix.');
            }
            posix_kill(getmypid(), SIGKILL);
            exit(77);
        }
    }
}
