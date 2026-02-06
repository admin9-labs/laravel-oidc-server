# Extension Points

> How to customize and extend the OIDC server behavior.

## Interfaces

### OidcUserInterface

Your User model must implement this interface:

```php
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;

class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims;
}
```

The interface requires:
- `getOidcSubject(): string` — returns the OIDC subject identifier (default: primary key)
- `getOidcClaims(array $scopes): array` — returns claims for the given scopes

Both are provided by the `HasOidcClaims` trait with sensible defaults.

## Customization Points

### Custom Claims Resolution

Three ways to customize how claims are resolved (see [claims-resolution.md](./claims-resolution.md)):

1. **Config-based** — `oidc-server.claims_resolver` maps claim names to attributes/callables
2. **Model override** — override `resolveOidcClaim()` in your User model
3. **Default map** — `oidc-server.default_claims_map` for fallback resolution

### Custom Authorization View

Publish and customize the authorization consent screen:

```bash
php artisan vendor:publish --tag=oidc-server-views
```

Or point to your own view:

```php
// config/oidc-server.php
'authorization_view' => 'auth.oauth.authorize',
```

The view receives `$client`, `$scopes`, `$request`, and `$authToken`.

### Custom Client Model

Replace the default `OidcClient` model to customize authorization behavior:

```php
// config/oidc-server.php
'client_model' => \App\Models\MyOAuthClient::class,
```

Your model should extend `Laravel\Passport\Client`.

### Passport Configuration Control

Disable auto-configuration to manage Passport yourself:

```php
// config/oidc-server.php
'configure_passport' => false,
'ignore_passport_routes' => false,
```

### Route Middleware

Customize middleware per endpoint group:

```php
// config/oidc-server.php
'routes' => [
    'enabled' => true,
    'discovery_middleware' => ['throttle:60,1'],
    'token_middleware' => ['throttle:10,1'],
    'userinfo_middleware' => ['auth:api'],
],
```

### Custom Scopes

Add custom scopes with their associated claims:

```php
// config/oidc-server.php
'scopes' => [
    'openid'  => ['description' => 'OpenID Connect', 'claims' => ['sub']],
    'profile' => ['description' => 'Profile info', 'claims' => ['name', 'picture']],
    'phone'   => ['description' => 'Phone number', 'claims' => ['phone_number']],
],
```

### Token Lifetimes

```php
// config/oidc-server.php
'tokens' => [
    'access_token_ttl'  => (int) env('OIDC_ACCESS_TOKEN_TTL', 900),    // 15 min
    'refresh_token_ttl' => (int) env('OIDC_REFRESH_TOKEN_TTL', 604800), // 7 days
    'id_token_ttl'      => (int) env('OIDC_ID_TOKEN_TTL', 900),        // 15 min
],
```

## Disabling Routes

To register routes yourself instead of using the package's routes:

```php
// config/oidc-server.php
'routes' => [
    'enabled' => false,
],
```

Then register routes manually in your application's route files.
