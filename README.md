# Laravel OIDC Server

OpenID Connect Server for Laravel Passport — adds OIDC Discovery, JWKS, UserInfo, Token Introspection, Token Revocation, and RP-Initiated Logout to any Laravel + Passport application.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Laravel Passport 12 or 13

## Installation

```bash
composer require admin9/laravel-oidc-server
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=oidc-config
```

## Setup

### 1. Implement the interface on your User model

```php
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;

class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims;

    // Override for custom claims:
    protected function resolveOidcClaim(string $claim): mixed
    {
        return match ($claim) {
            'nickname' => $this->display_name,
            'picture' => $this->avatar_url,
            default => parent::resolveOidcClaim($claim),
        };
    }
}
```

### 2. Generate Passport keys

```bash
php artisan passport:keys
```

## Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/.well-known/openid-configuration` | GET | OIDC Discovery |
| `/.well-known/jwks.json` | GET | JSON Web Key Set |
| `/oauth/authorize` | GET | Authorization (Passport) |
| `/oauth/token` | POST | Token (Passport) |
| `/oauth/userinfo` | GET/POST | UserInfo |
| `/oauth/introspect` | POST | Token Introspection (RFC 7662) |
| `/oauth/revoke` | POST | Token Revocation (RFC 7009) |
| `/oauth/logout` | GET | RP-Initiated Logout |

## Configuration

See `config/oidc.php` for all options including scopes, token TTLs, claims resolvers, route middleware, and Passport auto-configuration.

## License

MIT
