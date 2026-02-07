# Claims Resolution

This document explains how user claims are resolved from scopes using a 3-layer resolution mechanism.

## Scope → Claims Mapping

Claims are grouped by scope in `config/oidc-server.php`:

| Scope | Claims |
|-------|--------|
| `openid` | `sub` |
| `profile` | `name`, `nickname`, `picture`, `updated_at` |
| `email` | `email`, `email_verified` |

When a token is issued with `scope=openid profile email`, the UserInfo endpoint and id_token will include all claims from those scopes.

## 3-Layer Resolution

When resolving a claim value, the `HasOidcClaims` trait checks three layers in order:

### Layer 1: Config Resolver (`oidc-server.claims_resolver`)

Map claim names to model attributes or callables:

```php
// config/oidc-server.php
'claims_resolver' => [
    'nickname' => 'public_name',                    // string → model attribute
    'picture'  => fn($user) => $user->avatar_url,   // callable
],
```

### Layer 2: Model Override (`resolveOidcClaim()`)

Override the method in your User model for custom logic:

```php
protected function resolveOidcClaim(string $claim): mixed
{
    return match ($claim) {
        'nickname' => $this->display_name ?? $this->name,
        'picture'  => $this->getAvatarUrl(),
        default    => parent::resolveOidcClaim($claim),
    };
}
```

### Layer 3: Default Claims Map (`oidc-server.default_claims_map`)

Fallback mapping for standard claims:

```php
// config/oidc-server.php
'default_claims_map' => [
    'name'           => 'name',                                    // model attribute
    'email'          => 'email',                                   // model attribute
    'email_verified' => fn($user) => $user->email_verified_at !== null,
    'updated_at'     => fn($user) => $user->updated_at?->timestamp,
],
```

If no layer resolves the claim, `null` is returned and the claim is omitted.

## Resolution Flow

```
resolveOidcClaim('email')
  │
  ├─ 1. Check config('oidc-server.claims_resolver')['email']
  │     → Found? Return value (string=attribute, callable=call)
  │
  ├─ 2. Check if claim is 'sub'
  │     → Yes? Return getOidcSubject() (default: model primary key)
  │
  ├─ 3. Check config('oidc-server.default_claims_map')['email']
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
