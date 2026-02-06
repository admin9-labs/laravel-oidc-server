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
php artisan vendor:publish --tag=oidc-server-config
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

Publish the config file and see `config/oidc.php` for all options. Key configuration sections:

### User Model

By default the package uses `config('auth.providers.users.model')` to look up users when generating ID tokens. Override with:

```php
'user_model' => \App\Models\User::class,
```

### Passport Route Control

The package calls `Passport::ignoreRoutes()` by default to prevent route conflicts. Disable if you need Passport's default routes alongside OIDC:

```php
'ignore_passport_routes' => false,
```

### Default Claims Map

The `HasOidcClaims` trait resolves standard claims via a configurable map. Override to match your User model's schema:

```php
'default_claims_map' => [
    'name' => 'name',           // string = model attribute
    'email' => 'email',
    'email_verified' => fn ($user) => $user->email_verified_at !== null,
    'updated_at' => fn ($user) => $user->updated_at?->timestamp,
],
```

For custom claims (e.g. `nickname`, `picture`), use `claims_resolver` or override `resolveOidcClaim()` in your User model.

### Other Options

- **Scopes & claims mapping** — `scopes`, `claims_resolver`
- **Token TTLs** — `tokens.access_token_ttl`, `tokens.refresh_token_ttl`, `tokens.id_token_ttl`
- **Route middleware** — `routes.discovery_middleware`, `routes.token_middleware`, `routes.userinfo_middleware`
- **Passport auto-configuration** — `configure_passport` (set `false` to configure Passport yourself)

## License

MIT
