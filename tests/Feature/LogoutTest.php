<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;

class LogoutTest extends TestCase
{
    public function test_logout_without_params_redirects_to_home(): void
    {
        $response = $this->withSession(['_token' => 'test'])
            ->confirmOidcLogout('/oauth/logout');

        $response->assertRedirect('/');
    }

    public function test_logout_rotates_session_and_preserves_unrelated_data(): void
    {
        $this->withSession(['_token' => 'test']);
        $oldSessionId = session()->getId();

        $response = $this->withSession([
            '_token' => 'test',
            'some_data' => 'value',
        ])->confirmOidcLogout('/oauth/logout');

        $response->assertRedirect('/');
        $response->assertSessionHas('some_data', 'value');
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertNotSame('test', session()->token());
    }

    public function test_logout_with_invalid_redirect_uri_goes_to_home(): void
    {
        $response = $this->withSession(['_token' => 'test'])
            ->confirmOidcLogout('/oauth/logout?post_logout_redirect_uri=https://evil.com');

        $response->assertRedirect('/');
    }

    public function test_logout_with_explicitly_registered_local_redirect(): void
    {
        config(['oidc-server.post_logout_redirect_uris_supported' => ['https://example.com/logged-out']]);

        $response = $this->withSession(['_token' => 'test'])
            ->confirmOidcLogout('/oauth/logout?post_logout_redirect_uri=https://example.com/logged-out');

        $response->assertRedirect('https://example.com/logged-out');
    }

    public function test_logout_appends_state_to_redirect(): void
    {
        config(['oidc-server.post_logout_redirect_uris_supported' => ['https://example.com']]);

        $response = $this->withSession(['_token' => 'test'])
            ->confirmOidcLogout('/oauth/logout?post_logout_redirect_uri=https://example.com&state=abc123');

        $response->assertRedirect('https://example.com?state=abc123');
    }

    public function test_logout_with_malformed_id_token_hint_still_works(): void
    {
        $response = $this->withSession(['_token' => 'test'])
            ->confirmOidcLogout('/oauth/logout?id_token_hint=not-a-valid-jwt');

        // Should not throw, just redirect to home
        $response->assertRedirect('/');
    }
}
