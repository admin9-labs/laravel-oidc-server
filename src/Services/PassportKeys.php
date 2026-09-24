<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Laravel\Passport\Passport;
use Lcobucci\JWT\Signer\Key\InMemory;
use League\OAuth2\Server\CryptKey;

class PassportKeys
{
    public function key(string $type): InMemory
    {
        $configured = str_replace('\n', "\n", config('passport.'.$type.'_key') ?? '');
        $key = new CryptKey($configured ?: 'file://'.Passport::keyPath('oauth-'.$type.'.key'), null, false);

        return InMemory::plainText($key->getKeyContents());
    }

    public function encryptionKey(): string
    {
        // Passport 12 uses the encrypter directly, even where the callback API exists.
        return property_exists(Passport::class, 'hashesClientSecrets')
            ? app('encrypter')->getKey()
            : Passport::tokenEncryptionKey(app('encrypter'));
    }
}
