<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Admin9\OidcServer\Contracts\ReauthenticationHandler;
use Admin9\OidcServer\Contracts\ReauthenticationService;
use Admin9\OidcServer\Services\TokenVerifier;
use Admin9\OidcServer\Tests\PassportTestCase;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

class AuthenticationFreshnessTest extends PassportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->bind(ReauthenticationHandler::class, fn () => new class implements ReauthenticationHandler
        {
            public function redirect(Request $request, ReauthenticationChallenge $challenge): RedirectResponse
            {
                return redirect('/test-reauth?'.http_build_query(['transaction' => $challenge->transactionId, 'challenge' => $challenge->token]));
            }
        });
        Route::middleware('web')->post('/test-reauth', function (Request $request) {
            $service = app(ReauthenticationService::class);
            $challenge = $service->challenge($request, $request->input('transaction'), $request->input('challenge'));
            // Test host authentication result; production integrations must validate credentials / all factors.
            $user = \Admin9\OidcServer\Tests\Support\OidcUser::findOrFail($request->input('user'));
            Auth::guard($challenge->guard)->login($user);
            $request->session()->regenerate(true);
            app(AuthenticationRecorder::class)->markAuthenticated($challenge->guard, $user,
                $request->has('time') ? new DateTimeImmutable('@'.$request->input('time')) : null, $challenge);

            return $service->complete($request, $challenge);
        })->block();
    }

    private function login(?int $time = null): \Admin9\OidcServer\Tests\Support\OidcUser
    {
        $user = $this->user();
        Auth::guard('web')->login($user);
        request()->setLaravelSession(app('session.store'));
        app(AuthenticationRecorder::class)->markAuthenticated('web', $user, $time !== null ? new DateTimeImmutable('@'.$time) : null);

        return $user;
    }

    private function approval($response): array
    {
        return ['transaction' => $response->viewData('transactionId'), 'auth_token' => $response->viewData('authToken')];
    }

    private function authorizationQuery($response): array
    {
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY) ?? '', $query);

        return $query;
    }

    private function exchange($client, string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'code' => $code, 'redirect_uri' => 'https://rp.example/callback', 'code_verifier' => str_repeat('a', 64)]);
    }

    public function test_known_authentication_is_frozen_in_code_and_refresh_without_browser_session(): void
    {
        $client = $this->client();
        $authTime = time() - 50;
        $this->login($authTime);
        $view = $this->authorize($client, ['nonce' => ' exact nonce ', 'max_age' => '60'])->assertOk();
        $code = $this->authorizationQuery($this->post('/oauth/authorize', $this->approval($view))->assertRedirect())['code'];
        Auth::guard('web')->logout();
        session()->flush();
        $tokens = $this->exchange($client, $code)->assertOk()->json();
        $claims = app(TokenVerifier::class)->signedJwt($tokens['id_token'])->claims();
        $this->assertSame($authTime, $claims->get('auth_time'));
        $this->assertSame(' exact nonce ', $claims->get('nonce'));
        for ($i = 0; $i < 2; $i++) {
            $tokens = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
                'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']])->assertOk()->json();
            $claims = app(TokenVerifier::class)->signedJwt($tokens['id_token'])->claims();
            $this->assertSame($authTime, $claims->get('auth_time'));
            $this->assertFalse($claims->has('nonce'));
        }
    }

    public function test_unknown_authentication_requires_challenge_and_prompt_none_never_interacts(): void
    {
        $client = $this->client();
        Auth::guard('web')->login($this->user(), true);
        $silent = $this->authorize($client, ['prompt' => 'none'])->assertRedirect();
        $this->assertSame('login_required', $this->authorizationQuery($silent)['error']);
        $interactive = $this->authorize($client)->assertRedirect();
        $this->assertSame('/test-reauth', parse_url($interactive->headers->get('Location'), PHP_URL_PATH));
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_guest_challenge_binds_first_identity_and_resume_is_single_use(): void
    {
        $client = $this->client();
        $user = $this->user();
        $challenge = $this->authorizationQuery($this->authorize($client, ['max_age' => 0])->assertRedirect());
        $completed = $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertRedirect();
        $resume = $completed->headers->get('Location');
        $view = $this->get($resume)->assertOk();
        $this->get($resume)->assertStatus(400);
        $approved = $this->post('/oauth/authorize', $this->approval($view))->assertRedirect();
        $this->exchange($client, $this->authorizationQuery($approved)['code'])->assertOk();
        $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertStatus(400);
    }

    public function test_reauthentication_preserves_state_and_omitted_redirect_semantics(): void
    {
        $client = $this->client();
        $user = $this->user();
        $state = ' exact + / 中文 ';
        $challenge = $this->authorizationQuery($this->authorize($client, [
            'max_age' => 0, 'scope' => 'profile', 'redirect_uri' => null, 'state' => $state,
        ])->assertRedirect());
        $completed = $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertRedirect();
        $view = $this->get($completed->headers->get('Location'))->assertOk();
        $approved = $this->post('/oauth/authorize', $this->approval($view))->assertRedirect();
        $query = $this->authorizationQuery($approved);
        $this->assertSame($state, $query['state']);
        $this->assertSame('https://rp.example/callback', strtok($approved->headers->get('Location'), '?'));
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'code' => $query['code'], 'code_verifier' => str_repeat('a', 64)])
            ->assertOk()->assertJsonMissingPath('id_token');
    }

    public function test_prompt_login_requires_current_challenge_and_expired_consent_invalidates_old_form(): void
    {
        $client = $this->client();
        $user = $this->login();
        $first = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login', 'max_age' => 60])->assertRedirect());
        $resume = $this->post('/test-reauth', $first + ['user' => $user->id])->assertRedirect()->headers->get('Location');
        $view = $this->get($resume)->assertOk();
        $form = $this->approval($view);
        $this->travel(61)->seconds();
        $second = $this->authorizationQuery($this->post('/oauth/authorize', $form)->assertRedirect());
        $this->assertNotSame($first['challenge'], $second['challenge']);
        $this->post('/oauth/authorize', $form)->assertStatus(400);
        $resume = $this->post('/test-reauth', $second + ['user' => $user->id])->assertRedirect()->headers->get('Location');
        $newView = $this->get($resume)->assertOk();
        $this->post('/oauth/authorize', $form)->assertStatus(400);
        $this->post('/oauth/authorize', $this->approval($newView))->assertRedirect();
        $this->assertSame(1, Passport::authCode()->count());
    }

    public function test_challenge_rejects_old_sso_time_wrong_user_and_a_record_without_challenge_proof(): void
    {
        $client = $this->client();
        $user = $this->login();
        $challenge = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login'])->assertRedirect());
        $this->post('/test-reauth', $challenge + ['user' => $user->id, 'time' => now()->timestamp - 100])->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
        $challenge = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login'])->assertRedirect());
        $this->post('/test-reauth', $challenge + ['user' => $this->user()->id])->assertStatus(400);
        Auth::guard('web')->login($user);
        request()->setLaravelSession(app('session.store'));
        app(AuthenticationRecorder::class)->markAuthenticated('web', $user);
        $challenge = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login'])->assertRedirect());
        Route::middleware('web')->post('/test-complete-only', function (Request $request) {
            $service = app(ReauthenticationService::class);

            return $service->complete($request, $service->challenge($request, $request->input('transaction'), $request->input('challenge')));
        })->block();
        $this->post('/test-complete-only', $challenge)->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_reauthentication_preserves_another_guard_and_cannot_borrow_another_tabs_challenge(): void
    {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
        $user = $this->login();
        $admin = $this->user();
        Auth::guard('admin')->login($admin);
        $client = $this->client();
        $first = $this->authorizationQuery($this->authorize($client, ['max_age' => '000'])->assertRedirect());
        $second = $this->authorizationQuery($this->authorize($client, ['max_age' => 0])->assertRedirect());
        $this->post('/test-reauth', ['transaction' => $first['transaction'], 'challenge' => $second['challenge'], 'user' => $user->id])->assertStatus(400);
        $completed = $this->post('/test-reauth', $first + ['user' => $user->id])->assertRedirect();
        $this->assertAuthenticatedAs($admin, 'admin');
        $view = $this->get($completed->headers->get('Location'))->assertOk();
        $this->post('/oauth/authorize', $this->approval($view))->assertRedirect();
        $this->assertSame(1, Passport::authCode()->count());
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_new_generation_invalidates_another_tabs_consent(): void
    {
        $client = $this->client();
        $user = $this->login();
        $old = $this->approval($this->authorize($client)->assertOk());
        $challenge = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login'])->assertRedirect());
        $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertRedirect();
        $this->post('/oauth/authorize', $old)->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_client_revocation_or_callback_removal_during_interaction_fails_locally(): void
    {
        $this->login();
        foreach (['revoke', 'callback'] as $change) {
            $client = $this->client();
            $view = $this->authorize($client)->assertOk();
            $client->forceFill($change === 'revoke' ? ['revoked' => true] : (property_exists(Passport::class, 'hashesClientSecrets')
                ? ['redirect' => 'https://removed.example/callback'] : ['redirect_uris' => ['https://removed.example/callback']]))->save();
            $response = $this->post('/oauth/authorize', $this->approval($view))->assertStatus(400);
            $this->assertFalse($response->headers->has('Location'));
        }
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_expired_transaction_and_deleted_identity_cannot_issue_code(): void
    {
        $client = $this->client();
        $user = $this->login();
        $view = $this->authorize($client)->assertOk();
        $this->travel(601)->seconds();
        $this->post('/oauth/authorize', $this->approval($view))->assertStatus(400);
        $this->travelBack();
        $view = $this->authorize($client)->assertOk();
        $user->delete();
        $this->post('/oauth/authorize', $this->approval($view))->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_failed_registration_check_terminates_challenge_even_if_client_is_restored(): void
    {
        $client = $this->client();
        $user = $this->login();
        $challenge = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login'])->assertRedirect());
        $client->forceFill(['revoked' => true])->save();
        $response = $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertStatus(400);
        $this->assertFalse($response->headers->has('Location'));
        $client->forceFill(['revoked' => false])->save();
        $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_oauth_only_code_and_refresh_have_context_but_no_id_token(): void
    {
        $client = $this->client();
        $this->login();
        $view = $this->authorize($client, ['scope' => 'profile'])->assertOk();
        $code = $this->authorizationQuery($this->post('/oauth/authorize', $this->approval($view))->assertRedirect())['code'];
        $context = app(TokenVerifier::class)->encryptedPayload($code)['oidc'];
        $this->assertSame(3, $context['v']);
        $tokens = $this->exchange($client, $code)->assertOk()->assertJsonMissingPath('id_token')->json();
        $refreshed = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']])->assertOk()->assertJsonMissingPath('id_token')->json();
        $this->assertSame($context, app(TokenVerifier::class)->encryptedPayload($refreshed['refresh_token'])['oidc']);
    }

    public function test_original_max_age_is_not_rechecked_at_code_exchange_or_refresh(): void
    {
        $client = $this->client();
        $time = time();
        $this->login($time);
        $this->travel(59)->seconds();
        $view = $this->authorize($client, ['max_age' => 60])->assertOk();
        $code = $this->authorizationQuery($this->post('/oauth/authorize', $this->approval($view))->assertRedirect())['code'];
        $this->travel(6)->seconds();
        $tokens = $this->exchange($client, $code)->assertOk()->json();
        $this->travel(3600)->seconds();
        $refreshed = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $client->id,
            'client_secret' => 'test-secret', 'refresh_token' => $tokens['refresh_token']])->assertOk()->json();
        $this->assertSame($time, app(TokenVerifier::class)->signedJwt($refreshed['id_token'])->claims()->get('auth_time'));
    }

    public function test_unmanaged_user_grants_are_rejected_before_any_token_is_saved(): void
    {
        foreach (['password', 'urn:ietf:params:oauth:grant-type:device_code', 'custom-user-grant'] as $grant) {
            $this->postJson('/oauth/token', ['grant_type' => $grant, 'scope' => 'openid'])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        }
        $this->assertSame(0, Passport::token()->count());
        $this->assertSame(0, Passport::refreshToken()->count());
    }

    public function test_trusted_client_cannot_skip_authentication_or_explicit_consent(): void
    {
        $original = Passport::clientModel();
        Passport::useClientModel(FreshnessTrustedClient::class);
        try {
            $client = $this->client();
            $silent = $this->authorize($client, ['prompt' => 'none'])->assertRedirect();
            $this->assertSame('login_required', $this->authorizationQuery($silent)['error']);
            $user = $this->login();
            $challenge = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login consent'])->assertRedirect());
            $completed = $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertRedirect();
            $this->get($completed->headers->get('Location'))->assertOk()->assertSee('Authorize');
            $this->assertSame(0, Passport::authCode()->count());
        } finally {
            Passport::useClientModel($original);
        }
    }

    public function test_final_trusted_client_check_detects_revocation_after_consent_decision(): void
    {
        $original = Passport::clientModel();
        Passport::useClientModel(FreshnessTrustedClient::class);
        try {
            $this->login();
            $client = $this->client();
            config(['oidc_test_revoke_during_consent_decision' => true]);
            $response = $this->authorize($client)->assertStatus(400);
            $this->assertFalse($response->headers->has('Location'));
            $this->assertSame(0, Passport::authCode()->count());
        } finally {
            Passport::useClientModel($original);
        }
    }

    public function test_authentication_expiring_at_the_final_code_checkpoint_starts_a_new_challenge(): void
    {
        $original = Passport::clientModel();
        Passport::useClientModel(FreshnessTrustedClient::class);
        try {
            $this->login();
            $client = $this->client();
            config(['oidc_test_expire_during_consent_decision' => true]);
            $response = $this->authorize($client, ['max_age' => 1])->assertRedirect();
            $this->assertSame('/test-reauth', parse_url($response->headers->get('Location'), PHP_URL_PATH));
            $this->assertSame(0, Passport::authCode()->count());
        } finally {
            Passport::useClientModel($original);
            $this->travelBack();
        }
    }

    public function test_cancelled_challenge_is_terminal_and_does_not_remove_another_tab(): void
    {
        Route::middleware('web')->post('/test-cancel', function (Request $request) {
            $service = app(ReauthenticationService::class);

            return $service->cancel($request, $service->challenge($request, $request->input('transaction'), $request->input('challenge')));
        })->block();
        $client = $this->client();
        $user = $this->login();
        $first = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login', 'state' => 'exact cancel state'])->assertRedirect());
        $second = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login'])->assertRedirect());
        $cancelled = $this->post('/test-cancel', $first)->assertRedirect();
        $this->assertSame('access_denied', $this->authorizationQuery($cancelled)['error']);
        $this->assertSame('exact cancel state', $this->authorizationQuery($cancelled)['state']);
        $this->post('/test-reauth', $first + ['user' => $user->id])->assertStatus(400);
        $this->post('/test-reauth', $second + ['user' => $user->id])->assertRedirect();
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_expired_challenge_cannot_be_revived_by_a_later_clock_correction(): void
    {
        $client = $this->client();
        $user = $this->login();
        $challenge = $this->authorizationQuery($this->authorize($client, ['prompt' => 'login'])->assertRedirect());
        $this->travel(301)->seconds();
        $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertStatus(400);
        $this->travelBack();
        $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertStatus(400);
        $this->assertSame(0, Passport::authCode()->count());
    }

    public function test_transaction_limit_and_wrong_credentials_preserve_valid_pending_transactions(): void
    {
        $client = $this->client();
        $this->login();
        $forms = [];
        for ($i = 0; $i < 10; $i++) {
            $forms[] = $this->approval($this->authorize($client, ['nonce' => (string) $i])->assertOk());
        }
        $this->authorize($client)->assertStatus(429);
        $this->post('/oauth/authorize', ['transaction' => $forms[0]['transaction'], 'auth_token' => str_repeat('0', 64)])->assertStatus(400);
        $this->post('/oauth/authorize', $forms[0])->assertRedirect();
        $this->authorize($client)->assertOk();
        $this->assertSame(1, Passport::authCode()->count());
    }

    public function test_positive_max_age_accepts_its_exact_boundary_then_requires_authentication(): void
    {
        \Illuminate\Support\Carbon::setTestNow(now()->startOfSecond());
        try {
            $client = $this->client();
            $this->login(now()->timestamp - 60);
            $view = $this->authorize($client, ['max_age' => '00060'])->assertOk();
            $this->travel(1)->seconds();
            $response = $this->post('/oauth/authorize', $this->approval($view))->assertRedirect();
            $this->assertSame('/test-reauth', parse_url($response->headers->get('Location'), PHP_URL_PATH));
            $this->assertSame(0, Passport::authCode()->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_same_second_challenge_uses_new_generation_and_only_references_the_snapshot(): void
    {
        \Illuminate\Support\Carbon::setTestNow(now()->startOfSecond());
        try {
            $client = $this->client();
            $user = $this->login();
            $old = app(AuthenticationRecorder::class)->current('web');
            $challenge = $this->authorizationQuery($this->authorize($client, ['max_age' => 0])->assertRedirect());
            $response = $this->post('/test-reauth', $challenge + ['user' => $user->id])->assertRedirect();
            $tx = session('oidc.freshness.transactions.'.$challenge['transaction']);
            $this->assertSame($old->authTime, $tx['authentication_snapshot']['auth_time']);
            $this->assertNotSame($old->generation, $tx['authentication_snapshot']['generation']);
            $this->assertSame(['challenge_id' => $tx['challenge']['id'], 'generation' => $tx['authentication_snapshot']['generation']], $tx['verified_authentication']);
            $this->get($response->headers->get('Location'))->assertOk();
        } finally {
            $this->travelBack();
        }
    }
}

class FreshnessTrustedClient extends \Laravel\Passport\Client
{
    protected $table = 'oauth_clients';

    public function skipsAuthorization(...$arguments): bool
    {
        if (config('oidc_test_revoke_during_consent_decision')) {
            $this->newQuery()->whereKey($this->getKey())->update(['revoked' => true]);
        }
        if (config('oidc_test_expire_during_consent_decision')) {
            \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(2));
        }

        return true;
    }
}
