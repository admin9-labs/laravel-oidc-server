<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use DateTimeImmutable;
use Defuse\Crypto\Crypto;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;

class TokenVerifier
{
    public function __construct(protected PassportKeys $keys) {}

    public function signedJwt(string $value): ?Plain
    {
        try {
            $token = (new Parser(new JoseEncoder))->parse($value);
        } catch (\Throwable) {
            return null;
        }

        if (! $token instanceof Plain) {
            return null;
        }

        return (new Validator)->validate($token, new SignedWith(new Sha256, $this->keys->key('public')))
            ? $token
            : null;
    }

    public function encryptedPayload(string $value): ?array
    {
        try {
            $payload = json_decode(Crypto::decryptWithPassword($value, $this->keys->encryptionKey()), true, 512, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function accessToken(string $value, bool $activeOnly): ?Token
    {
        $jwt = $this->signedJwt($value);
        if (! $jwt) {
            return null;
        }

        $claims = $jwt->claims();
        $id = $claims->get('jti');
        if (! is_string($id) || $id === '' || ! is_array($claims->get('scopes'))
            || ! $claims->get('exp') instanceof DateTimeImmutable) {
            return null;
        }

        $token = Passport::token()->find($id);
        if (! $token || ! $token->client || $token->client->revoked
            || $claims->get('aud') !== [(string) $token->client_id]) {
            return null;
        }

        $subject = $claims->get('sub');
        $expectedSubjects = $token->user_id === null
            ? ['', (string) $token->client_id]
            : [(string) $token->user_id];
        if (! in_array($subject, $expectedSubjects, true)) {
            return null;
        }

        if ($activeOnly) {
            $now = new DateTimeImmutable;
            if ($token->revoked || ! $token->expires_at?->isFuture()
                || $claims->get('exp') <= $now
                || ($claims->has('nbf') && $claims->get('nbf') > $now)
                || ($claims->has('iat') && $claims->get('iat') > $now)) {
                return null;
            }
        }

        return $token;
    }

    public function refreshToken(string $value, bool $activeOnly): ?RefreshToken
    {
        $payload = $this->encryptedPayload($value);
        foreach (['refresh_token_id', 'access_token_id', 'client_id'] as $key) {
            if (! is_string($payload[$key] ?? null) || $payload[$key] === '') {
                return null;
            }
        }
        if (! is_int($payload['expire_time'] ?? null)) {
            return null;
        }

        $refresh = Passport::refreshToken()->find($payload['refresh_token_id']);
        $access = $refresh?->accessToken;
        if (! $access || ! $access->client || $access->client->revoked
            || (string) $access->id !== $payload['access_token_id']
            || (string) $access->client_id !== $payload['client_id']) {
            return null;
        }

        // An expired access token can still have a usable refresh token.
        if ($activeOnly && ($refresh->revoked || ! $refresh->expires_at?->isFuture()
            || $payload['expire_time'] <= time())) {
            return null;
        }

        return $refresh;
    }
}
