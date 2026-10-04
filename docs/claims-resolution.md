# Claims Resolution

This document explains how scoped user claims are resolved through model overrides and the trait's fallback rules.

## Scope → Claims Mapping

Claims are grouped by scope in `config/oidc-server.php`:

| Scope | Claims |
|-------|--------|
| `openid` | `sub` |
| `profile` | `name`, `nickname`, `picture`, `updated_at` |
| `email` | `email`, `email_verified` |

When a user token is issued with `scope=openid profile email`, the UserInfo endpoint and id_token can include claims from those scopes. Claims whose resolver returns `null` are omitted.

## Resolution Order

If your User model overrides `resolveOidcClaim()`, that method handles the claim first. Claims it does not handle must fall back to the preserved trait method. Without an override, resolution starts directly in the trait.

### Model Override (`resolveOidcClaim()`)

Preserve the trait method with an alias, then override the named claims:

```php
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims {
        resolveOidcClaim as protected resolveDefaultOidcClaim;
    }

    protected function resolveOidcClaim(string $claim): mixed
    {
        return match ($claim) {
            'nickname' => $this->display_name ?? $this->name,
            'picture' => $this->avatar_url,
            default => $this->resolveDefaultOidcClaim($claim),
        };
    }
}
```

Laravel's base `Authenticatable` model does not implement `resolveOidcClaim()`, so `parent::resolveOidcClaim()` is not a valid fallback. This example handles `nickname` and `picture` before consulting configuration; other claims use the trait alias.

### Trait Fallback: Config Resolver (`oidc-server.claims_resolver`)

The trait first checks configured resolvers. Map claim names to model attributes or callables:

```php
// config/oidc-server.php
'claims_resolver' => [
    'nickname' => 'public_name',                    // string → model attribute
    'picture' => 'avatar_url',
],
```

Use attribute strings or static callable arrays with `php artisan config:cache`; closures in configuration cannot be serialized. A configured resolver returning `null` omits the claim rather than falling through to another mapping.

### Trait Fallback: Default Claims Map (`oidc-server.default_claims_map`)

When no configured resolver exists, the trait resolves `sub` using `getOidcSubject()`, then checks the default map for other claims:

```php
// config/oidc-server.php
'default_claims_map' => [
    'name'           => 'name',                                    // model attribute
    'email'          => 'email',                                   // model attribute
    'email_verified' => [\Admin9\OidcServer\Services\DefaultClaims::class, 'emailVerified'],
    'updated_at'     => [\Admin9\OidcServer\Services\DefaultClaims::class, 'updatedAt'],
],
```

If no mapping resolves the claim, `null` is returned and the claim is omitted.

## Resolution Flow

```
resolveOidcClaim($claim)
  │
  ├─ Model override, if defined
  │     → Handled? Return value
  │     → Otherwise call resolveDefaultOidcClaim($claim)
  │
  ├─ 1. Check config('oidc-server.claims_resolver')[$claim]
  │     → Found? Return value (string=attribute, callable=call)
  │
  ├─ 2. Check if claim is 'sub'
  │     → Yes? Return getOidcSubject() (default: model primary key)
  │
  ├─ 3. Check config('oidc-server.default_claims_map')[$claim]
  │     → Found? Return value (string=attribute, callable=call)
  │
  └─ 4. Return null (claim omitted)
```

## Customizing the Subject Identifier

Override `getOidcSubject()` in your User model:

```php
public function getOidcSubject(): string
{
    return (string) $this->uuid; // Use UUID instead of auto-increment ID
}
```

## Adding Custom Scopes and Claims

```php
// config/oidc-server.php
'scopes' => [
    'openid'  => ['description' => '...', 'claims' => ['sub']],
    'profile' => ['description' => '...', 'claims' => ['name', 'nickname', 'picture', 'updated_at']],
    'email'   => ['description' => '...', 'claims' => ['email', 'email_verified']],
    'phone'   => ['description' => 'Access phone number', 'claims' => ['phone_number']],
],

'claims_resolver' => [
    'phone_number' => 'phone',
],
```
