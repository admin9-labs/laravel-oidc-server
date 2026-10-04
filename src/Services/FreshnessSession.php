<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\AtomicStateStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FreshnessSession
{
    public const KEY = 'oidc.freshness';

    public function __construct(private AtomicStateStore $atomic) {}

    public function read(Request $request, bool $create = false): ?array
    {
        $state = $request->session()->get(self::KEY);
        if ($state === null && $create) {
            $state = [
                'schema' => 1, 'binding' => self::random(), 'revision' => self::random(),
                'authentications' => [], 'transactions' => [],
            ];
            if (! $this->atomic->create('session:'.$state['binding'], $state['revision'], $this->lifetime())) {
                throw new HttpException(503, 'Authentication state could not be initialized.');
            }
            $request->session()->put(self::KEY, $state);
        }
        if ($state === null) {
            return null;
        }
        if (! is_array($state) || ($state['schema'] ?? null) !== 1
            || ! is_string($state['binding'] ?? null) || ! is_string($state['revision'] ?? null)
            || ! is_array($state['authentications'] ?? null) || ! is_array($state['transactions'] ?? null)
            || $this->atomic->read('session:'.$state['binding']) !== $state['revision']) {
            $request->session()->forget(self::KEY);
            throw new HttpException(400, 'Authentication session is stale or unavailable. Restart authorization.');
        }

        return $state;
    }

    public function write(Request $request, array $state): void
    {
        $revision = self::random();
        if (! $this->atomic->replace('session:'.$state['binding'], $state['revision'], $revision, $this->lifetime())) {
            throw new HttpException(400, 'Authentication session changed. Restart authorization.');
        }
        $state['revision'] = $revision;
        $request->session()->put(self::KEY, $state);
    }

    public static function random(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function lifetime(): int
    {
        return max(600, (int) config('session.lifetime', 120) * 60);
    }
}
