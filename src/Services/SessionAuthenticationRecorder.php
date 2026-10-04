<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Authentication\AuthenticationRecord;
use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use DateTimeImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class SessionAuthenticationRecorder implements AuthenticationRecorder
{
    public function __construct(private FreshnessSession $sessions) {}

    public function markAuthenticated(string $guard, Authenticatable $user, ?DateTimeImmutable $authenticatedAt = null, ?ReauthenticationChallenge $challenge = null): AuthenticationRecord
    {
        $current = Auth::guard($guard)->user();
        $time = $authenticatedAt?->getTimestamp() ?? now()->timestamp;
        if (! $current || get_class($current) !== get_class($user)
            || (string) $current->getAuthIdentifier() !== (string) $user->getAuthIdentifier()
            || $time < 0 || $time > now()->timestamp) {
            throw new InvalidArgumentException('Authentication must match the current guard user and a real past or present event.');
        }
        $record = new AuthenticationRecord($guard, get_class($user), (string) $user->getAuthIdentifier(), $time, FreshnessSession::random());
        $state = $this->sessions->read(request(), true);
        if ($challenge !== null) {
            // This validates the locator again; caller-created objects carry no authority.
            $transactions = app(AuthorizationTransactions::class);
            $transactions->challenge(request(), $challenge->transactionId, $challenge->token);
            try {
                $transactions->recordChallenge($state, $challenge, $record);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $transactions->invalidate(request(), $challenge->transactionId);

                throw $exception;
            }
        }
        $state['authentications'][$guard] = $record->toArray();
        $this->sessions->write(request(), $state);

        return $record;
    }

    public function current(string $guard): ?AuthenticationRecord
    {
        $state = $this->sessions->read(request());
        $data = $state['authentications'][$guard] ?? null;
        $user = Auth::guard($guard)->user();
        if ($data === null || ! $user) {
            return null;
        }
        $record = AuthenticationRecord::fromArray($data);
        if ($record->identity() !== [$guard, get_class($user), (string) $user->getAuthIdentifier()]
            || $record->authTime < 0 || $record->authTime > now()->timestamp) {
            return null;
        }

        return $record;
    }

    public function forget(string $guard): void
    {
        $state = $this->sessions->read(request());
        if ($state === null) {
            return;
        }
        unset($state['authentications'][$guard]);
        $state['transactions'] = array_filter($state['transactions'], fn (array $transaction): bool => $transaction['guard'] !== $guard);
        $this->sessions->write(request(), $state);
    }
}
