<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Events\OidcLogoutInitiated;
use Admin9\OidcServer\Tests\PassportTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

class LogoutSecurityTest extends PassportTestCase
{
    public function test_get_preserves_login_and_head_has_no_logout_side_effects(): void
    {
        $user = $this->user();
        Auth::guard('web')->login($user);
        Event::fake([OidcLogoutInitiated::class]);
        $this->withSession(['_token' => 'existing', 'oidc.logout_pending' => 'unchanged'])
            ->call('HEAD', '/oauth/logout')->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('existing', session('_token'));
        $this->assertSame('unchanged', session('oidc.logout_pending'));
        $this->get('/oauth/logout')->assertOk()->assertSee('Are you sure');
        $this->assertAuthenticatedAs($user);
        Event::assertNotDispatched(OidcLogoutInitiated::class);
    }

    public function test_real_id_token_can_log_out_current_user_by_get_or_protocol_post(): void
    {
        $client = $this->client();
        $user = $this->user();
        $tokens = $this->issueTokens($client, $user);
        foreach (['GET', 'POST'] as $method) {
            Auth::guard('web')->login($user);
            $this->call($method, '/oauth/logout', [
                'id_token_hint' => $tokens['id_token'],
                'client_id' => (string) $client->id,
                'post_logout_redirect_uri' => 'https://rp.example/callback',
            ])->assertRedirect('https://rp.example/callback');
            $this->assertGuest('web');
        }
    }

    public function test_foreign_user_access_tokens_wrong_issuer_and_audience_require_confirmation(): void
    {
        $client = $this->client();
        $victim = $this->user();
        $other = $this->user();
        $access = $this->accessToken($client);
        $hints = [
            $this->idHint($client, $other),
            $this->accessJwt($access),
            $this->idHint($client, $victim, ['iss' => 'https://other-issuer.example']),
            $this->idHint($client, $victim, ['aud' => ['missing-client']]),
            $this->idHint($client, $victim, ['aud' => [(string) $client->id, 'another-client']]),
            $this->idHint($client, $victim, ['claims' => ['azp' => 'another-client']]),
            $this->idHint($client, $victim, ['headers' => ['typ' => 'at+jwt']]),
            $this->idHint($client, $victim, ['claims' => ['sid' => 'unknown-session']]),
        ];
        foreach ($hints as $hint) {
            Auth::guard('web')->login($victim);
            $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $hint]))->assertOk();
            $this->assertAuthenticatedAs($victim, 'web');
        }
    }

    public function test_invalid_signature_and_unsigned_hint_do_not_authorize_redirect(): void
    {
        $client = $this->client();
        $user = $this->user();
        $valid = $this->idHint($client, $user);
        [$header, $body, $signature] = explode('.', $valid);
        $forged = $header.'.'.$body.'.'.str_repeat('a', strlen($signature));
        $unsigned = rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=').'.'.$body.'.';
        foreach ([$forged, $unsigned] as $hint) {
            Auth::guard('web')->login($user);
            $this->confirmLogout([
                'id_token_hint' => $hint, 'post_logout_redirect_uri' => 'https://rp.example/callback',
            ])->assertRedirect('/');
        }
    }

    public function test_expired_and_future_hints_require_confirmation(): void
    {
        $client = $this->client();
        $user = $this->user();
        foreach ([
            ['exp' => new \DateTimeImmutable('-1 hour')],
            ['iat' => new \DateTimeImmutable('+1 hour')],
        ] as $overrides) {
            Auth::guard('web')->login($user);
            $this->get('/oauth/logout?'.http_build_query([
                'id_token_hint' => $this->idHint($client, $user, $overrides),
            ]))->assertOk();
            $this->assertAuthenticatedAs($user);
        }
    }

    public function test_revoked_client_and_mismatched_client_id_cannot_skip_confirmation(): void
    {
        $client = $this->client();
        $user = $this->user();
        $hint = $this->idHint($client, $user);
        Auth::guard('web')->login($user);
        $this->get('/oauth/logout?'.http_build_query([
            'id_token_hint' => $hint, 'client_id' => 'another',
        ]))->assertOk();
        $client->forceFill(['revoked' => true])->save();
        $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $hint]))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_requires_complete_exact_match(): void
    {
        $client = $this->client();
        $user = $this->user();
        config(['oidc-server.post_logout_redirect_uris' => [
            (string) $client->id => ['https://rp.example/callback?next=/safe'],
        ]]);
        foreach ([
            'https://rp.example/callback?next=https://other.example',
            'https://rp.example/callback?next=/safe#extra',
            'https://rp.example/callback/?next=/safe',
            'https://rp.example/callback/child?next=/safe',
            'https://RP.example/callback?next=/safe',
            'https://rp.example:443/callback?next=/safe',
            ' https://rp.example/callback?next=/safe',
            'https://rp.example/callback?next=/safe ',
        ] as $uri) {
            Auth::guard('web')->login($user);
            $this->get('/oauth/logout?'.http_build_query([
                'id_token_hint' => $this->idHint($client, $user),
                'post_logout_redirect_uri' => $uri,
            ]))->assertRedirect('/');
        }
        Auth::guard('web')->login($user);
        $this->get('/oauth/logout?'.http_build_query([
            'id_token_hint' => $this->idHint($client, $user),
            'post_logout_redirect_uri' => 'https://rp.example/callback?next=/safe',
            'state' => '0',
        ]))->assertRedirect('https://rp.example/callback?next=/safe&state=0');
    }

    public function test_global_redirect_does_not_expand_client_allowlist_and_same_origin_is_not_enough(): void
    {
        $client = $this->client();
        $user = $this->user();
        Auth::guard('web')->login($user);
        config(['oidc-server.post_logout_redirect_uris_supported' => ['https://different.example/end']]);
        $this->get('/oauth/logout?'.http_build_query([
            'id_token_hint' => $this->idHint($client, $user),
            'post_logout_redirect_uri' => 'https://different.example/end',
        ]))->assertRedirect('/');
        $this->confirmLogout(['post_logout_redirect_uri' => 'https://example.com/unregistered'])->assertRedirect('/');
    }

    public function test_confirmation_uses_server_side_parameters_and_is_single_use(): void
    {
        $user = $this->user();
        Auth::guard('web')->login($user);
        config(['oidc-server.post_logout_redirect_uris_supported' => ['https://rp.example/done#finish']]);
        $this->get('/oauth/logout?'.http_build_query([
            'post_logout_redirect_uri' => 'https://rp.example/done#finish', 'state' => 'a&b',
        ]))->assertOk();
        $confirmation = session('oidc.logout_pending.challenge');
        $this->post('/oauth/logout/confirm', [
            'confirmation' => $confirmation, 'post_logout_redirect_uri' => 'https://attacker.example', 'state' => 'changed',
        ])->assertRedirect('https://rp.example/done?state=a%26b#finish');
        $this->assertGuest();
        $this->post('/oauth/logout/confirm', ['confirmation' => $confirmation])->assertStatus(400);
    }

    public function test_confirmation_cannot_log_out_a_different_or_newly_logged_in_user(): void
    {
        Auth::guard('web')->login($this->user());
        $this->get('/oauth/logout')->assertOk();
        $confirmation = session('oidc.logout_pending.challenge');
        $other = $this->user();
        Auth::guard('web')->login($other);
        $this->post('/oauth/logout/confirm', ['confirmation' => $confirmation])->assertStatus(400);
        $this->assertAuthenticatedAs($other);
    }

    public function test_expired_or_invalid_confirmation_preserves_session(): void
    {
        Auth::guard('web')->login($this->user());
        $this->get('/oauth/logout')->assertOk();
        $this->post('/oauth/logout/confirm', ['confirmation' => 'wrong'])->assertStatus(400);
        $this->assertAuthenticated();
        $this->get('/oauth/logout')->assertOk();
        session()->put('oidc.logout_pending.expires_at', time() - 1);
        $this->post('/oauth/logout/confirm', [
            'confirmation' => session('oidc.logout_pending.challenge'),
        ])->assertStatus(400);
        $this->assertAuthenticated();
    }

    public function test_confirm_revalidates_registration_before_redirecting(): void
    {
        $client = $this->client();
        $this->get('/oauth/logout?'.http_build_query([
            'client_id' => (string) $client->id, 'post_logout_redirect_uri' => 'https://rp.example/callback',
        ]))->assertOk();
        $client->forceFill(['revoked' => true])->save();
        $this->post('/oauth/logout/confirm', [
            'confirmation' => session('oidc.logout_pending.challenge'),
        ])->assertRedirect('/');
    }

    public function test_real_csrf_protects_confirmation_but_not_the_verified_protocol_post(): void
    {
        $client = $this->client();
        $user = $this->user();
        Auth::guard('web')->login($user);
        $previous = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->post('/oauth/logout')->assertOk();
            $this->assertAuthenticated();
            $confirmation = session('oidc.logout_pending.challenge');
            $this->post('/oauth/logout/confirm', ['confirmation' => $confirmation])->assertStatus(419);
            $this->assertAuthenticated();
            $this->post('/oauth/logout/confirm', [
                'confirmation' => $confirmation, '_token' => csrf_token(),
            ])->assertRedirect('/');
            $this->assertGuest();
            Auth::guard('web')->login($user);
            $this->post('/oauth/logout', ['id_token_hint' => $this->idHint($client, $user)])->assertRedirect('/');
            $this->assertGuest();
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_bad_parameter_shapes_do_not_change_authentication(): void
    {
        Auth::guard('web')->login($this->user());
        foreach (['id_token_hint', 'client_id', 'post_logout_redirect_uri', 'state'] as $parameter) {
            $this->post('/oauth/logout', [$parameter => ['bad']])->assertStatus(400);
            $this->assertAuthenticated();
        }
    }

    public function test_form_serialized_protocol_post_preserves_exact_callback_bytes(): void
    {
        $client = $this->client();
        $user = $this->user();
        Auth::guard('web')->login($user);
        $this->call('POST', '/oauth/logout', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'id_token_hint' => $this->idHint($client, $user),
            'post_logout_redirect_uri' => 'https://rp.example/callback ',
        ]))->assertRedirect('/');
        Auth::guard('web')->login($user);
        $this->post('/oauth/logout', [], ['Content-Type' => 'multipart/form-data; boundary=example'])
            ->assertStatus(400);
        $this->assertAuthenticatedAs($user);
    }
}
