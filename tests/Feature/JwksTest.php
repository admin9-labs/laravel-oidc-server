<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\TestCase;

class JwksTest extends TestCase
{
    public function test_jwks_endpoint_returns_error_when_no_key(): void
    {
        $publicKeyPath = storage_path('oauth-public.key');
        $backupPath = $publicKeyPath.'.bak';
        $existed = file_exists($publicKeyPath);

        if ($existed) {
            rename($publicKeyPath, $backupPath);
        }

        try {
            $response = $this->getJson('/.well-known/jwks.json');

            // Without oauth keys generated, should return 500
            $response->assertStatus(500);
            $response->assertJsonStructure(['error', 'error_description']);
        } finally {
            if ($existed) {
                rename($backupPath, $publicKeyPath);
            }
        }
    }

    public function test_jwks_endpoint_returns_keys_when_key_exists(): void
    {
        // Generate a temporary key pair for testing
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($keyPair, $privateKey);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        $publicKeyPath = storage_path('oauth-public.key');
        $privateKeyPath = storage_path('oauth-private.key');

        $publicKeyExisted = file_exists($publicKeyPath);
        $privateKeyExisted = file_exists($privateKeyPath);

        file_put_contents($publicKeyPath, $publicKey);
        file_put_contents($privateKeyPath, $privateKey);

        try {
            $response = $this->getJson('/.well-known/jwks.json');

            $response->assertOk();
            $response->assertJsonStructure([
                'keys' => [
                    ['kty', 'alg', 'use', 'kid', 'n', 'e'],
                ],
            ]);

            $data = $response->json();
            $this->assertEquals('RSA', $data['keys'][0]['kty']);
            $this->assertEquals('RS256', $data['keys'][0]['alg']);
            $this->assertEquals('sig', $data['keys'][0]['use']);
        } finally {
            // Clean up only if we created the files
            if (! $publicKeyExisted) {
                @unlink($publicKeyPath);
            }
            if (! $privateKeyExisted) {
                @unlink($privateKeyPath);
            }
        }
    }

    public function test_jwks_endpoint_has_correct_cache_headers(): void
    {
        // Generate a temporary key pair
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($keyPair, $privateKey);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        $publicKeyPath = storage_path('oauth-public.key');
        $existed = file_exists($publicKeyPath);

        file_put_contents($publicKeyPath, $publicKey);

        try {
            $response = $this->getJson('/.well-known/jwks.json');
            $response->assertHeader('Cache-Control', 'max-age=86400, public');
        } finally {
            if (! $existed) {
                @unlink($publicKeyPath);
            }
        }
    }
}
