<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Integration;

use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Admin9\OidcServer\Contracts\ReauthenticationHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class HostProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\Admin9\OidcServer\Contracts\AtomicStateStore::class, fn () => new class extends \Admin9\OidcServer\Services\RedisAtomicStateStore
        {
            public function replace(string $key, string $expected, string $value, int $seconds): bool
            {
                $changed = parent::replace($key, $expected, $value, $seconds);
                if ($changed && $value === 'consumed') {
                    Faults::hit('token-consumed', $key);
                }

                return $changed;
            }
        });
        $this->app->bind(ReauthenticationHandler::class, fn () => new class implements ReauthenticationHandler
        {
            public function redirect(Request $request, ReauthenticationChallenge $challenge): RedirectResponse
            {
                $route = in_array($request->session()->get('fixture_sso_mode'), ['silent', 'fresh'], true)
                    ? 'fixture.sso' : 'fixture.reauthenticate';

                return redirect()->route($route, ['transaction' => $challenge->transactionId, 'challenge' => $challenge->token]);
            }
        });
    }

    public function boot(): void
    {
        $codeModel = get_class(\Laravel\Passport\Passport::authCode());
        $codeModel::creating(fn () => Faults::hit('before-code-save', request()->input('transaction')));
        $this->loadViewsFrom(__DIR__.'/views', 'fixture');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadRoutesFrom(__DIR__.'/sso-routes.php');
    }
}
