<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Concerns\HasOidcClaims;
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Events\OidcLogoutInitiated;
use Admin9\OidcServer\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\HasApiTokens;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;

class AuthorizationGuardTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.defaults.guard', 'web');
        $app['config']->set('auth.providers.users.model', GuardTestAdmin::class);
        $app['config']->set('auth.providers.members', ['driver' => 'eloquent', 'model' => GuardTestMember::class]);
        $app['config']->set('auth.guards.member_web', ['driver' => 'session', 'provider' => 'members']);
        $app['config']->set('auth.guards.api.provider', 'members');
        $app['config']->set('passport.guard', 'member_web');
        $app['config']->set('oidc-server.user_model', GuardTestMember::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/passport/database/migrations');
        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
        $this->artisan('passport:keys', ['--force' => true]);

        GuardTestAdmin::forceCreate(['id' => 1, 'name' => 'Administrator', 'email' => 'admin@example.com', 'password' => 'unused']);
        GuardTestAdmin::forceCreate(['id' => 2, 'name' => 'Other Administrator', 'email' => 'other-admin@example.com', 'password' => 'unused']);
        GuardTestMember::forceCreate(['id' => 1, 'name' => 'Member', 'email' => 'member@example.com', 'password' => 'unused']);
    }

    protected function createClient(): Client
    {
        $clients = app(ClientRepository::class);

        if (method_exists($clients, 'createAuthorizationCodeGrantClient')) {
            return $clients->createAuthorizationCodeGrantClient('Member App', ['https://client.example.com/callback'], false);
        }

        return $clients->create(null, 'Member App', 'https://client.example.com/callback', null, false, false, false);
    }

    protected function authorizationUrl(Client $client, array $parameters = []): string
    {
        return '/oauth/authorize?'.http_build_query(array_merge([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://client.example.com/callback',
            'response_type' => 'code',
            'scope' => 'openid profile',
            'state' => 'member-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $parameters));
    }

    protected function loginBoth(): void
    {
        Auth::guard('web')->login(GuardTestAdmin::findOrFail(1));
        Auth::guard('member_web')->login(GuardTestMember::findOrFail(1));
    }

    public function test_authorization_code_and_userinfo_use_member_despite_colliding_admin_id(): void
    {
        $this->loginBoth();

        $this->assertMemberAuthorizationFlow();
    }

    public function test_consent_does_not_switch_to_a_different_administrator_id(): void
    {
        $this->loginBoth();
        Auth::guard('web')->login(GuardTestAdmin::findOrFail(2));

        $this->assertMemberAuthorizationFlow();
    }

    protected function assertMemberAuthorizationFlow(): void
    {
        $client = $this->createClient();

        $authorization = $this->get($this->authorizationUrl($client, ['prompt' => 'consent']));
        $authorization->assertOk();
        $authorization->assertViewHas('user', fn ($user) => $user instanceof GuardTestMember);

        $approved = $this->post('/oauth/authorize', ['auth_token' => session('authToken')]);
        $approved->assertRedirect();
        parse_str(parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertSame('member-state', $query['state']);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://client.example.com/callback',
            'code' => $query['code'],
            'code_verifier' => str_repeat('a', 64),
        ]);
        $response->assertOk()->assertJsonStructure(['access_token', 'id_token']);
        $claims = (new Parser(new JoseEncoder))->parse($response->json('id_token'))->claims();
        $this->assertSame('member:1', $claims->get('sub'));
        $this->assertSame('Member', $claims->get('name'));

        Auth::forgetGuards();
        $this->withToken($response->json('access_token'))->getJson('/oauth/userinfo')
            ->assertOk()->assertJsonPath('sub', 'member:1')->assertJsonPath('name', 'Member');
    }

    public function test_null_user_model_uses_passport_guard_provider(): void
    {
        config(['oidc-server.user_model' => null]);

        $this->loginBoth();
        $this->assertMemberAuthorizationFlow();
    }

    public function test_administrator_session_does_not_satisfy_member_authorization(): void
    {
        Auth::guard('web')->login(GuardTestAdmin::findOrFail(1));

        $this->getJson($this->authorizationUrl($this->createClient()))->assertUnauthorized();
        $this->assertAuthenticatedAs(GuardTestAdmin::findOrFail(1), 'web');
    }

    public function test_prompt_none_returns_oauth_error_without_member_session(): void
    {
        Auth::guard('web')->login(GuardTestAdmin::findOrFail(1));

        $response = $this->get($this->authorizationUrl($this->createClient(), ['prompt' => 'none']));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $expectedError = method_exists(\Laravel\Passport\Exceptions\OAuthServerException::class, 'loginRequired')
            ? 'login_required'
            : 'access_denied';
        $this->assertSame($expectedError, $query['error']);
    }

    public function test_approve_and_deny_require_member_even_with_pending_authorization(): void
    {
        foreach (['POST', 'DELETE'] as $method) {
            $this->loginBoth();
            $this->get($this->authorizationUrl($this->createClient(), ['prompt' => 'consent']))->assertOk();
            $authToken = session('authToken');
            Auth::guard('member_web')->logout();

            $this->json($method, '/oauth/authorize', ['auth_token' => $authToken])->assertUnauthorized();
        }
    }

    public function test_logout_removes_only_member_and_pending_authorization_state(): void
    {
        Event::fake([OidcLogoutInitiated::class]);
        $this->loginBoth();
        $memberGuard = Auth::guard('member_web');
        $adminGuard = Auth::guard('web');
        $oldSessionId = session()->getId();
        $oldCsrfToken = session()->token();

        $response = $this->withSession([
            'authToken' => 'pending-token',
            'authRequest' => 'pending-request',
            'promptedForLogin' => true,
            'admin_preferences' => 'preserved',
        ])->get('/oauth/logout');

        $response->assertRedirect('/');
        foreach ([$memberGuard->getName(), 'authToken', 'authRequest', 'promptedForLogin'] as $key) {
            $response->assertSessionMissing($key);
        }
        $response->assertSessionHas($adminGuard->getName(), 1);
        $response->assertSessionHas('admin_preferences', 'preserved');
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame($oldCsrfToken, session()->token());
        Event::assertDispatched(OidcLogoutInitiated::class, fn ($event) => $event->userId === 1);

        Auth::forgetGuards();
        $this->assertGuest('member_web');
        $this->assertAuthenticatedAs(GuardTestAdmin::findOrFail(1), 'web');
    }

    public function test_logout_without_member_does_not_report_or_clear_administrator(): void
    {
        Event::fake([OidcLogoutInitiated::class]);
        Auth::guard('web')->login(GuardTestAdmin::findOrFail(1));

        $this->get('/oauth/logout')->assertRedirect('/');
        Event::assertDispatched(OidcLogoutInitiated::class, fn ($event) => $event->userId === null);
        Auth::forgetGuards();
        $this->assertAuthenticatedAs(GuardTestAdmin::findOrFail(1), 'web');
    }

    public function test_next_member_can_pass_auth_session_without_clearing_administrator(): void
    {
        Route::get('/login', fn () => 'login')->name('login');
        Route::middleware(['web', 'auth:web', 'auth.session'])->get('/admin-session', fn () => 'admin');
        Route::middleware(['web', 'auth:member_web', 'auth.session'])->get('/member-session', fn () => ['id' => auth()->id()]);
        $this->loginBoth();
        $this->getJson('/admin-session')->assertOk();
        $this->getJson('/member-session')->assertOk();
        $adminPasswordHash = session('password_hash_web');

        $this->get('/oauth/logout')->assertRedirect('/')->assertSessionHas('password_hash_web', $adminPasswordHash);
        $nextMember = GuardTestMember::forceCreate([
            'name' => 'Next Member', 'email' => 'next@example.com', 'password' => 'different-password-hash',
        ]);
        Auth::guard('member_web')->login($nextMember);

        $this->getJson('/member-session')->assertOk()->assertJsonPath('id', $nextMember->id);
        Auth::forgetGuards();
        $this->assertAuthenticatedAs(GuardTestAdmin::findOrFail(1), 'web');
    }

    public function test_next_member_must_confirm_their_own_password(): void
    {
        Route::middleware(['web', 'auth:member_web', 'password.confirm'])->get('/member-sensitive', fn () => 'confirmed');
        $this->loginBoth();
        session()->passwordConfirmed();
        $this->getJson('/member-sensitive')->assertOk();

        $this->get('/oauth/logout')->assertRedirect('/');
        $nextMember = GuardTestMember::forceCreate([
            'name' => 'Next Member', 'email' => 'next@example.com', 'password' => 'different-password-hash',
        ]);
        Auth::guard('member_web')->login($nextMember);

        $this->getJson('/member-sensitive')->assertStatus(423);
        $this->assertAuthenticatedAs(GuardTestAdmin::findOrFail(1), 'web');
    }

    public function test_null_passport_guard_uses_application_default_for_logout_and_tokens(): void
    {
        config(['passport.guard' => null, 'auth.defaults.guard' => 'member_web', 'oidc-server.user_model' => null]);
        $this->loginBoth();
        $this->assertMemberAuthorizationFlow();

        // Testbench reuses the app after auth:api changes the default guard for UserInfo.
        Auth::shouldUse('member_web');
        $this->get('/oauth/logout')->assertRedirect('/');
        Auth::forgetGuards();
        $this->assertGuest('member_web');
        $this->assertAuthenticatedAs(GuardTestAdmin::findOrFail(1), 'web');
    }

    public function test_default_web_guard_logout_remains_supported(): void
    {
        config(['passport.guard' => 'web']);
        $this->loginBoth();

        $this->get('/oauth/logout')->assertRedirect('/');
        Auth::forgetGuards();
        $this->assertGuest('web');
        $this->assertAuthenticatedAs(GuardTestMember::findOrFail(1), 'member_web');
    }
}

class GuardTestAdmin extends User implements OidcUserInterface
{
    use HasApiTokens;
    use HasOidcClaims;

    protected $table = 'users';

    protected $guarded = [];
}

class GuardTestMember extends GuardTestAdmin
{
    protected $table = 'members';

    public function getOidcSubject(): string
    {
        return 'member:'.$this->getKey();
    }
}
