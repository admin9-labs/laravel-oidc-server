<?php

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;

class DiscoveryTest extends TestCase
{
    public function test_discovery_endpoint_returns_valid_document(): void
    {
        $response = $this->getJson('/.well-known/openid-configuration');

        $response->assertOk();
        $response->assertJsonStructure([
            'issuer',
            'authorization_endpoint',
            'token_endpoint',
            'userinfo_endpoint',
            'jwks_uri',
            'end_session_endpoint',
            'introspection_endpoint',
            'revocation_endpoint',
            'response_types_supported',
            'subject_types_supported',
            'id_token_signing_alg_values_supported',
            'scopes_supported',
            'token_endpoint_auth_methods_supported',
            'claims_supported',
            'code_challenge_methods_supported',
            'grant_types_supported',
        ]);

        $data = $response->json();
        $this->assertEquals('https://example.com', $data['issuer']);
        $this->assertContains('openid', $data['scopes_supported']);
        $this->assertContains('sub', $data['claims_supported']);
    }

    public function test_discovery_endpoint_has_correct_cache_headers(): void
    {
        $response = $this->getJson('/.well-known/openid-configuration');

        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_discovery_endpoints_use_issuer_as_base(): void
    {
        $response = $this->getJson('/.well-known/openid-configuration');
        $data = $response->json();

        $issuer = $data['issuer'];
        $this->assertStringStartsWith($issuer, $data['authorization_endpoint']);
        $this->assertStringStartsWith($issuer, $data['token_endpoint']);
        $this->assertStringStartsWith($issuer, $data['userinfo_endpoint']);
        $this->assertStringStartsWith($issuer, $data['jwks_uri']);
    }
}
