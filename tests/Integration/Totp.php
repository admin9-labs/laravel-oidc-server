<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Tests\Integration;

/** Minimal deterministic TOTP factor for this local fixture, not a package authentication API. */
class Totp
{
    public static function valid(string $secret, string $code): bool
    {
        return hash_equals(self::code($secret), $code) || hash_equals(self::code($secret, time() - 30), $code);
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $digest = hash_hmac('sha1', pack('N2', 0, $counter), hex2bin($secret), true);
        $offset = ord($digest[19]) & 15;
        $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
