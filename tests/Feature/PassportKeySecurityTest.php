<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Feature;

use Admin9\OidcServer\Tests\PassportTestCase;
use Laravel\Passport\Passport;

class PassportKeySecurityTest extends PassportTestCase
{
    public function test_configured_keys_sign_verify_and_publish_without_default_key_files(): void
    {
        $oldPath = Passport::$keyPath;
        Passport::loadKeysFrom(storage_path('nonexistent-key-directory'));
        try {
            config([
                'passport.public_key' => str_replace("\n", '\n', config('passport.public_key')),
                'passport.private_key' => str_replace("\n", '\n', config('passport.private_key')),
            ]);
            $client = $this->client();
            $tokens = $this->issueTokens($client);
            $this->getJson('/.well-known/jwks.json')->assertOk()->assertJsonPath('keys.0.kty', 'RSA');
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $tokens['access_token'],
            ])->assertJsonPath('active', true);
            $this->get('/oauth/logout?'.http_build_query(['id_token_hint' => $tokens['id_token']]))->assertRedirect('/');
        } finally {
            Passport::$keyPath = $oldPath;
        }
    }

    public function test_verification_needs_only_the_public_key(): void
    {
        $client = $this->client();
        $tokens = $this->issueTokens($client);
        $oldPath = Passport::$keyPath;
        Passport::loadKeysFrom(storage_path('no-private-key'));
        config(['passport.private_key' => null]);
        try {
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $tokens['access_token'],
            ])->assertJsonPath('active', true);
        } finally {
            Passport::$keyPath = $oldPath;
        }
    }

    public function test_custom_passport_key_directory_is_supported(): void
    {
        $directory = storage_path('test-key-directory-'.bin2hex(random_bytes(4)));
        mkdir($directory);
        file_put_contents($directory.'/oauth-public.key', config('passport.public_key'));
        file_put_contents($directory.'/oauth-private.key', config('passport.private_key'));
        chmod($directory.'/oauth-public.key', 0600);
        chmod($directory.'/oauth-private.key', 0600);
        $oldPath = Passport::$keyPath;
        Passport::loadKeysFrom($directory);
        config(['passport.public_key' => null, 'passport.private_key' => null]);
        try {
            $client = $this->client();
            $tokens = $this->issueTokens($client);
            $this->getJson('/.well-known/jwks.json')->assertOk();
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $tokens['access_token'],
            ])->assertJsonPath('active', true);
        } finally {
            Passport::$keyPath = $oldPath;
            unlink($directory.'/oauth-public.key');
            unlink($directory.'/oauth-private.key');
            rmdir($directory);
        }
    }

    public function test_refresh_decryption_follows_the_actual_passport_encryption_key(): void
    {
        if (! method_exists(Passport::class, 'encryptTokensUsing')) {
            $this->markTestSkipped('This Passport version does not expose a token encryption callback.');
        }
        $original = Passport::$tokenEncryptionKeyCallback;
        Passport::encryptTokensUsing(fn () => str_repeat('different-key', 3));
        try {
            $client = $this->client();
            $tokens = $this->issueTokens($client);
            $this->postJson('/oauth/introspect', [
                'client_id' => $client->id, 'client_secret' => 'test-secret', 'token' => $tokens['refresh_token'],
            ])->assertJsonPath('active', true);
        } finally {
            Passport::$tokenEncryptionKeyCallback = $original;
        }
    }
}
