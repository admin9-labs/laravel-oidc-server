<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Contracts\AtomicStateStore;
use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Admin9\OidcServer\Services\FreshnessSession;
use Admin9\OidcServer\Tests\PassportTestCase;
use Admin9\OidcServer\Tests\Support\InMemoryAtomicStateStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthenticationRecorderTest extends PassportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(AtomicStateStore::class, new InMemoryAtomicStateStore);
        request()->setLaravelSession(app('session.store'));
    }

    public function test_login_and_set_user_do_not_record_authentication_and_explicit_events_have_distinct_generations(): void
    {
        $recorder = app(AuthenticationRecorder::class);
        $user = $this->user();
        Auth::guard('web')->login($user, true);
        $this->assertNull($recorder->current('web'));
        Auth::guard('web')->setUser($user);
        $this->assertNull($recorder->current('web'));
        $time = new DateTimeImmutable('-10 seconds');
        $first = $recorder->markAuthenticated('web', $user, $time);
        $second = $recorder->markAuthenticated('web', $user, $time);
        $this->assertSame($first->authTime, $second->authTime);
        $this->assertNotSame($first->generation, $second->generation);
        $this->assertEquals($second, $recorder->current('web'));
        $this->assertSame($second->toArray(), session('oidc.freshness.authentications.web'));
    }

    public function test_recorder_rejects_other_user_and_future_or_negative_time(): void
    {
        $user = $this->user();
        Auth::guard('web')->login($user);
        foreach ([[$this->user(), null], [$user, new DateTimeImmutable('+1 minute')], [$user, new DateTimeImmutable('@-1')]] as [$candidate, $time]) {
            try {
                app(AuthenticationRecorder::class)->markAuthenticated('web', $candidate, $time);
                $this->fail('Invalid authentication event was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertNull(app(AuthenticationRecorder::class)->current('web'));
            }
        }
    }

    public function test_guard_records_survive_session_rotation_and_forget_is_scoped(): void
    {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
        $recorder = app(AuthenticationRecorder::class);
        $member = $this->user();
        $admin = $this->user();
        Auth::guard('web')->login($member);
        Auth::guard('admin')->login($admin);
        $memberRecord = $recorder->markAuthenticated('web', $member);
        $adminRecord = $recorder->markAuthenticated('admin', $admin);
        $binding = session('oidc.freshness.binding');
        session()->regenerate(true);
        $this->assertSame($binding, session('oidc.freshness.binding'));
        $this->assertEquals($memberRecord, $recorder->current('web'));
        $this->assertEquals($adminRecord, $recorder->current('admin'));
        $recorder->forget('web');
        $this->assertNull($recorder->current('web'));
        $this->assertEquals($adminRecord, $recorder->current('admin'));
        $this->assertAuthenticated('admin');
    }

    public function test_old_session_snapshot_and_missing_atomic_state_cannot_restore_authentication(): void
    {
        $user = $this->user();
        Auth::guard('web')->login($user);
        $recorder = app(AuthenticationRecorder::class);
        $recorder->markAuthenticated('web', $user);
        $old = session(FreshnessSession::KEY);
        session()->regenerate(true);
        $recorder->markAuthenticated('web', $user);
        $current = session(FreshnessSession::KEY);
        foreach ([$old, $current] as $i => $state) {
            session()->put(FreshnessSession::KEY, $state);
            if ($i === 1) {
                app(AtomicStateStore::class)->values = [];
            }
            try {
                $recorder->current('web');
                $this->fail('Stale or missing state was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(400, $exception->getStatusCode());
            }
        }
    }
}
