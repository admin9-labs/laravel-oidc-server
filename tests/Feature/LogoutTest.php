<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;

class LogoutTest extends TestCase
{
    public function test_logout_without_params_redirects_to_home(): void
    {
        $response = $this->withSession(['_token' => 'test'])
            ->get('/oauth/logout');

        $response->assertRedirect('/');
    }

    public function test_logout_invalidates_session(): void
    {
        $response = $this->withSession([
            '_token' => 'test',
            'some_data' => 'value',
        ])->get('/oauth/logout');

        $response->assertRedirect('/');
        $response->assertSessionMissing('some_data');
    }

    public function test_logout_with_invalid_redirect_uri_goes_to_home(): void
    {
        $response = $this->withSession(['_token' => 'test'])
            ->get('/oauth/logout?post_logout_redirect_uri=https://evil.com');

        $response->assertRedirect('/');
    }

    public function test_logout_with_valid_redirect_uri_matching_app_url(): void
    {
        config(['app.url' => 'https://example.com']);

        $response = $this->withSession(['_token' => 'test'])
            ->get('/oauth/logout?post_logout_redirect_uri=https://example.com/logged-out');

        $response->assertRedirect('https://example.com/logged-out');
    }

    public function test_logout_appends_state_to_redirect(): void
    {
        config(['app.url' => 'https://example.com']);

        $response = $this->withSession(['_token' => 'test'])
            ->get('/oauth/logout?post_logout_redirect_uri=https://example.com&state=abc123');

        $response->assertRedirect('https://example.com?state=abc123');
    }

    public function test_logout_with_malformed_id_token_hint_still_works(): void
    {
        $response = $this->withSession(['_token' => 'test'])
            ->get('/oauth/logout?id_token_hint=not-a-valid-jwt');

        // Should not throw, just redirect to home
        $response->assertRedirect('/');
    }
}
