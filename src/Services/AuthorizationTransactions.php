<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Authentication\AuthenticationRecord;
use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthorizationTransactions
{
    public const LIFETIME = 600;
    public const CHALLENGE_LIFETIME = 300;
    public const LIMIT = 10;

    public function __construct(private FreshnessSession $sessions) {}

    public function create(Request $request, array $authorization, array $requirements, bool $redirectUriProvided): string
    {
        $state = $this->sessions->read($request, true);
        $state['transactions'] = array_filter($state['transactions'], fn (array $tx): bool =>
            $tx['expires_at'] > now()->timestamp && ! in_array($tx['state'], ['issued', 'denied', 'failed'], true));
        if (count($state['transactions']) >= self::LIMIT) {
            throw new HttpException(429, 'Too many pending authorization requests.');
        }
        $guard = config('passport.guard') ?? Auth::getDefaultDriver();
        $user = Auth::guard($guard)->user();
        if ($user && ! $user instanceof OidcUserInterface) {
            throw new HttpException(500, 'The authorization guard must provide an OIDC user.');
        }
        $id = FreshnessSession::random();
        $state['transactions'][$id] = [
            'id' => $id, 'state' => 'validated', 'created_at' => now()->timestamp,
            'expires_at' => now()->timestamp + self::LIFETIME, 'authorization' => $authorization,
            'redirect_uri_provided' => $redirectUriProvided,
            'requirements' => $requirements, 'guard' => $guard,
            'issuer' => config('oidc-server.issuer', config('app.url')),
            'provider' => config('auth.guards.'.$guard.'.provider'),
            'provider_model' => config('auth.providers.'.config('auth.guards.'.$guard.'.provider').'.model'),
            'user_model' => config('oidc-server.user_model'),
            'expected_identity' => $user ? [$guard, get_class($user), (string) $user->getAuthIdentifier()] : null,
            'expected_subject' => $user?->getOidcSubject(),
            'baseline_generation' => null, 'challenge' => null, 'authentication_snapshot' => null,
            'verified_authentication' => null, 'approval_token_hash' => null, 'resume_token_hash' => null,
        ];
        $this->sessions->write($request, $state);

        return $id;
    }

    public function get(Request $request, string $id): array
    {
        return $this->transaction($this->sessions->read($request) ?? [], $id);
    }

    public function update(Request $request, string $id, callable $change): array
    {
        $state = $this->sessions->read($request);
        $tx = $this->transaction($state ?? [], $id);
        $change($tx);
        $state['transactions'][$id] = $tx;
        $this->sessions->write($request, $state);

        return $tx;
    }

    public function beginChallenge(Request $request, string $id): ReauthenticationChallenge
    {
        $token = FreshnessSession::random();
        $tx = $this->update($request, $id, function (array &$tx) use ($request, $token): void {
            $state = $this->sessions->read($request);
            $tx['baseline_generation'] = $state['authentications'][$tx['guard']]['generation'] ?? null;
            $tx['state'] = 'awaiting_authentication';
            $tx['challenge'] = ['id' => FreshnessSession::random(), 'token_hash' => hash('sha256', $token),
                'issued_at' => now()->timestamp, 'expires_at' => min($tx['expires_at'], now()->timestamp + self::CHALLENGE_LIFETIME),
                'consumed' => false];
            $tx['authentication_snapshot'] = $tx['verified_authentication'] = null;
            $tx['approval_token_hash'] = $tx['resume_token_hash'] = null;
        });

        return $this->challengeValue($tx, $token);
    }

    public function challenge(Request $request, string $id, string $token): ReauthenticationChallenge
    {
        $tx = $this->get($request, $id);
        $this->checkChallenge($tx, $token);

        return $this->challengeValue($tx, $token);
    }

    public function recordChallenge(array &$state, ReauthenticationChallenge $challenge, AuthenticationRecord $record): void
    {
        $tx = $this->transaction($state, $challenge->transactionId);
        $this->checkChallenge($tx, $challenge->token);
        if ($record->guard !== $tx['guard']
            || ($tx['expected_identity'] !== null && $tx['expected_identity'] !== $record->identity())
            || $record->generation === $tx['baseline_generation']
            || $record->authTime < $tx['challenge']['issued_at'] || $record->authTime > now()->timestamp) {
            throw new HttpException(400, 'Authentication does not satisfy this challenge.');
        }
        $user = Auth::guard($tx['guard'])->user();
        if (! $user instanceof OidcUserInterface || ($tx['expected_subject'] !== null && $user->getOidcSubject() !== $tx['expected_subject'])) {
            throw new HttpException(400, 'The authentication identity changed.');
        }
        $tx['expected_identity'] ??= $record->identity();
        $tx['expected_subject'] ??= $user->getOidcSubject();
        $tx['authentication_snapshot'] = $record->toArray();
        $tx['verified_authentication'] = ['challenge_id' => $tx['challenge']['id'], 'generation' => $record->generation];
        $state['transactions'][$tx['id']] = $tx;
    }

    public function complete(Request $request, ReauthenticationChallenge $challenge): string
    {
        $token = FreshnessSession::random();
        $this->update($request, $challenge->transactionId, function (array &$tx) use ($request, $challenge, $token): void {
            $this->checkChallenge($tx, $challenge->token);
            $this->assertSnapshot($request, $tx);
            if ($tx['verified_authentication'] === null || $tx['authentication_snapshot']['auth_time'] < $tx['challenge']['issued_at']) {
                throw new HttpException(400, 'Complete the authentication challenge first.');
            }
            $tx['challenge']['consumed'] = true;
            $tx['state'] = 'authentication_verified';
            $tx['resume_token_hash'] = hash('sha256', $token);
        });

        return $token;
    }

    public function consume(Request $request, string $id, string $token, string $kind): array
    {
        return $this->update($request, $id, function (array &$tx) use ($token, $kind): void {
            $field = $kind === 'approval' ? 'approval_token_hash' : 'resume_token_hash';
            $expectedState = $kind === 'approval' ? 'awaiting_consent' : 'authentication_verified';
            if ($tx['state'] !== $expectedState || ! is_string($tx[$field]) || ! hash_equals($tx[$field], hash('sha256', $token))) {
                throw new HttpException(400, 'Invalid or already used authorization credential.');
            }
            $tx[$field] = null;
        });
    }

    public function assertSnapshot(Request $request, array $tx): void
    {
        $state = $this->sessions->read($request);
        $snapshot = $tx['authentication_snapshot'];
        $current = $state['authentications'][$tx['guard']] ?? null;
        $user = Auth::guard($tx['guard'])->user();
        if ($snapshot === null || $current !== $snapshot || ! $user instanceof OidcUserInterface
            || $tx['expected_identity'] !== [$tx['guard'], get_class($user), (string) $user->getAuthIdentifier()]
            || $tx['expected_subject'] !== $user->getOidcSubject()
            || $snapshot['auth_time'] < 0 || $snapshot['auth_time'] > now()->timestamp) {
            $this->invalidate($request, $tx['id']);
            throw new HttpException(400, 'The authorization authentication changed. Restart authorization.');
        }
        if ($tx['challenge'] !== null && $tx['verified_authentication'] !== [
            'challenge_id' => $tx['challenge']['id'], 'generation' => $snapshot['generation'],
        ]) {
            throw new HttpException(400, 'Missing transaction authentication proof.');
        }
        $model = $snapshot['model'];
        $persisted = $model::find($snapshot['user_id']);
        if (! $persisted instanceof OidcUserInterface || get_class($persisted) !== $model || $persisted->getOidcSubject() !== $tx['expected_subject']) {
            $this->invalidate($request, $tx['id']);
            throw new HttpException(400, 'The original authentication identity no longer exists.');
        }
    }

    public function expiredAuthentication(array $tx): bool
    {
        $age = $tx['requirements']['max_age'];

        return $age !== null && $age > 0 && now()->timestamp - $tx['authentication_snapshot']['auth_time'] > $age;
    }

    public function finish(Request $request, string $id, string $state): void
    {
        $this->update($request, $id, function (array &$tx) use ($state): void {
            $tx['state'] = $state;
            $tx['approval_token_hash'] = $tx['resume_token_hash'] = null;
            if ($tx['challenge'] !== null) {
                $tx['challenge']['consumed'] = true;
            }
        });
    }

    public function invalidate(Request $request, string $id): void
    {
        $state = $this->sessions->read($request);
        if (! isset($state['transactions'][$id]) || in_array($state['transactions'][$id]['state'], ['issued', 'denied', 'failed'], true)) {
            return;
        }
        $state['transactions'][$id]['state'] = 'failed';
        $state['transactions'][$id]['approval_token_hash'] = $state['transactions'][$id]['resume_token_hash'] = null;
        $this->sessions->write($request, $state);
    }

    private function transaction(array $state, string $id): array
    {
        $tx = $state['transactions'][$id] ?? null;
        if (! is_array($tx) || $tx['created_at'] > now()->timestamp || $tx['expires_at'] <= now()->timestamp
            || $tx['guard'] !== (config('passport.guard') ?? Auth::getDefaultDriver())
            || $tx['issuer'] !== config('oidc-server.issuer', config('app.url'))
            || $tx['provider'] !== config('auth.guards.'.$tx['guard'].'.provider')
            || $tx['provider_model'] !== config('auth.providers.'.$tx['provider'].'.model')
            || $tx['user_model'] !== config('oidc-server.user_model')
            || in_array($tx['state'], ['issued', 'denied', 'failed'], true)) {
            if (is_array($tx)) {
                $this->invalidate(request(), $id);
            }
            throw new HttpException(400, 'Invalid or expired authorization transaction.');
        }

        return $tx;
    }

    private function checkChallenge(array $tx, string $token): void
    {
        if ($tx['state'] !== 'awaiting_authentication' || $tx['challenge'] === null
            || $tx['challenge']['consumed']
            || ! hash_equals($tx['challenge']['token_hash'], hash('sha256', $token))) {
            throw new HttpException(400, 'Invalid or expired authentication challenge.');
        }
        if ($tx['challenge']['expires_at'] <= now()->timestamp) {
            $this->invalidate(request(), $tx['id']);
            throw new HttpException(400, 'Invalid or expired authentication challenge.');
        }
    }

    private function challengeValue(array $tx, string $token): ReauthenticationChallenge
    {
        return new ReauthenticationChallenge($tx['id'], $token, $tx['guard'], $tx['expected_identity'], $tx['challenge']['issued_at'], $tx['challenge']['expires_at']);
    }
}
