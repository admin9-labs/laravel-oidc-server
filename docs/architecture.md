# Architecture

> How `laravel-oidc-server` extends Laravel Passport into a full OIDC Identity Provider.

## Overview

Laravel Passport provides OAuth2 authorization (`/oauth/authorize`, `/oauth/token`). This package adds the OpenID Connect layer on top:

- Injects `id_token` into the token response when `openid` scope is requested
- Registers OIDC Discovery, JWKS, UserInfo, Introspect, Revoke, and Logout endpoints
- Auto-configures Passport (scopes, TTLs, client model, authorization view)

## Package Structure

```
laravel-oidc-server/
├── src/
│   ├── OidcServerServiceProvider.php       ← Auto-configures Passport
│   ├── Contracts/OidcUserInterface.php     ← User model interface
│   ├── Concerns/HasOidcClaims.php          ← Default claims resolution trait
│   ├── Services/
│   │   ├── TokenResponseType.php           ← Injects id_token into token response
│   │   ├── IdTokenService.php              ← JWT generation (RS256)
│   │   └── ClaimsService.php               ← Unified claims resolution
│   ├── Http/Controllers/OidcController.php ← OIDC endpoints (6 methods)
│   └── Models/OidcClient.php               ← First-party client auto-approval
├── config/oidc-server.php                         ← Package configuration
├── resources/views/authorize.blade.php     ← Default authorization view
└── routes/web.php                          ← Route registration
```

## Service Provider Auto-Configuration

`OidcServerServiceProvider` runs in `packageBooted()`:

1. Calls `Passport::ignoreRoutes()` (configurable via `oidc-server.ignore_passport_routes`)
2. Sets the authorization view (`oidc-server.authorization_view`)
3. Sets the Client model (`oidc-server.client_model`)
4. Registers scopes from `oidc-server.scopes`
5. Configures token TTLs from `oidc-server.tokens`
6. Replaces the token response type with `TokenResponseType` (id_token injection)
7. Registers OIDC + Passport routes

Disable auto-configuration with `config('oidc-server.configure_passport', false)`.

## id_token Injection — TokenResponseType

`TokenResponseType` extends League OAuth2 Server's `BearerTokenResponse`:

```php
protected function getExtraParams(AccessTokenEntityInterface $accessToken): array
{
    // Only generate id_token when 'openid' scope is present
    if (! in_array('openid', $scopes)) {
        return [];
    }

    $user = $userModel::find($accessToken->getUserIdentifier());
    $idToken = $this->idTokenService->generateToken($accessToken, $user, $client, $nonce);

    return ['id_token' => $idToken];
}
```

Standard OAuth2 response:
```json
{ "access_token": "...", "refresh_token": "..." }
```

Becomes OIDC response:
```json
{ "access_token": "...", "refresh_token": "...", "id_token": "..." }
```

## JWT Generation — IdTokenService

Uses **RS256 asymmetric signing** via `lcobucci/jwt`:

- Private key: `storage/oauth-private.key` (signs tokens)
- Public key: `storage/oauth-public.key` (exposed via JWKS endpoint)

JWT configuration is lazy-loaded (initialized on first use, not at boot time).

### Token Claims

| Claim | Source | Description |
|-------|--------|-------------|
| `iss` | `config('oidc-server.issuer')` | Issuer URL |
| `aud` | Client ID | Audience |
| `sub` | `$user->getOidcSubject()` | Subject identifier |
| `iat` | Current time | Issued at |
| `exp` | Token TTL | Expiration |
| `auth_time` | Current timestamp | Authentication time |
| `nonce` | Request parameter | Replay protection |

Additional claims are added based on requested scopes (see [claims-resolution.md](./claims-resolution.md)).

## Custom Client Model — OidcClient

```php
class OidcClient extends BaseClient
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->firstParty();
    }
}
```

First-party clients (`first_party = true`) skip the authorization prompt. Third-party clients show the consent screen. Override via `config('oidc-server.client_model')`.

## Data Flow

```
Client Application                    Auth Server (this package)
  │                                         │
  │  1. Redirect to /oauth/authorize        │
  │ ───────────────────────────────────────→ │
  │                                         │  2. Login → Authorization prompt
  │  3. User approves, redirect with code   │
  │ ←─────────────────────────────────────── │
  │                                         │
  │  4. POST /oauth/token (code → tokens)   │
  │ ───────────────────────────────────────→ │
  │                                         │  5. Return access_token + id_token
  │ ←─────────────────────────────────────── │     + refresh_token
  │                                         │
  │  6. GET /oauth/userinfo                 │
  │ ───────────────────────────────────────→ │
  │                                         │  7. Return user claims
  │ ←─────────────────────────────────────── │     (sub, name, email...)
  │                                         │
  │  8. GET /oauth/logout (optional)        │
  │ ───────────────────────────────────────→ │
  │                                         │  9. Clear session, redirect back
  │ ←─────────────────────────────────────── │
```
