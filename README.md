# Laravel OIDC Server

[![Latest Version on Packagist](https://img.shields.io/packagist/v/admin9/laravel-oidc-server.svg?style=flat-square)](https://packagist.org/packages/admin9/laravel-oidc-server)
[![Total Downloads](https://img.shields.io/packagist/dt/admin9/laravel-oidc-server.svg?style=flat-square)](https://packagist.org/packages/admin9/laravel-oidc-server)
[![License](https://img.shields.io/packagist/l/admin9/laravel-oidc-server.svg?style=flat-square)](https://packagist.org/packages/admin9/laravel-oidc-server)

[English](README.md) | [中文文档](docs/zh-CN/README.md)

OpenID Connect Server for Laravel Passport — adds OIDC Discovery, JWKS, UserInfo, Token Introspection, Token Revocation, and RP-Initiated Logout to any Laravel + Passport application.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Laravel Passport 12 or 13

## Quick Start

> **Prerequisite:** [Laravel Passport](https://laravel.com/docs/passport) must be installed and configured before using this package.

### 1. Install the package

```bash
composer require admin9/laravel-oidc-server
```

### 2. Implement the interface on your User model

```php
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;

class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims;

    // Optional: Override for custom claims
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

### 3. Generate Passport keys

```bash
php artisan passport:keys
```

This creates the RSA key pair (`storage/oauth-private.key` and `storage/oauth-public.key`) needed for signing tokens.

### 4. Create an OAuth client

Create a client application that will use your OIDC server:

```bash
# For authorization code flow (recommended for web apps)
php artisan passport:client

# For client credentials grant (recommended for machine-to-machine, e.g., microservices)
php artisan passport:client --client

# Or install default clients (personal access + password grant)
php artisan passport:install
```

You'll receive a **Client ID** and **Client Secret** — save these for configuring your client application.

**Grant Type Guide:**
- **Authorization Code Flow**: For web apps with user interaction, most secure
- **Client Credentials Grant**: For server-to-server API calls, no user involvement
- **Password Grant**: Only for first-party trusted apps, not recommended for third-party

### 5. (Optional) Publish and customize the config

```bash
php artisan vendor:publish --tag=oidc-server-config
```

Edit `config/oidc-server.php` to customize scopes, claims, token TTLs, and more.

---

**That's it!** Your OIDC server is ready. Test it by visiting:

```
https://your-app.test/.well-known/openid-configuration
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

After publishing the config file, you can customize various aspects in `config/oidc-server.php`:

### User Model

By default, the package uses `config('auth.providers.users.model')` to look up users when generating ID tokens. Override if needed:

```php
'user_model' => \App\Models\User::class,
```

### Passport Route Control

The package calls `Passport::ignoreRoutes()` by default to prevent route conflicts. Disable this if you need Passport's default routes alongside OIDC:

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

For custom claims (e.g., `nickname`, `picture`), use `claims_resolver` or override `resolveOidcClaim()` in your User model.

### Other Options

- **Scopes & claims mapping** — `scopes`, `claims_resolver`
- **Token TTLs** — `tokens.access_token_ttl`, `tokens.refresh_token_ttl`, `tokens.id_token_ttl`
- **Route middleware** — `routes.discovery_middleware`, `routes.token_middleware`, `routes.userinfo_middleware`
- **Passport auto-configuration** — `configure_passport` (set to `false` to configure Passport yourself)

See the [Configuration Reference](docs/configuration.md) for all available options.

## Documentation

- [Architecture](docs/architecture.md)
- [Configuration Reference](docs/configuration.md)
- [Endpoint Reference](docs/endpoints.md)
- [Claims Resolution](docs/claims-resolution.md)
- [Extension Points](docs/extension-points.md)
- [Troubleshooting](docs/troubleshooting.md)

## License

[MIT](LICENSE.md)
