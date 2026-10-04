# Architecture

This document describes the 2.0 host authentication contract, authorization transactions and Passport/League issuance adapters. See the [integration guide](upgrading-to-2.0.0.md) and [acceptance evidence](implementation-2.0.md).

## Overview

Package controllers own `/oauth/authorize` and `/oauth/token`, using a dedicated server built from Passport repositories and League grants:

- Enforces freshness through explicit host authentication records and one-time challenges
- Stores original authorization parameters, authentication snapshots and one-time approvals
- Generates ID Tokens with real `auth_time` for `openid` responses after validating code/refresh context
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
│   │   ├── AuthorizationFlow.php           ← Authorization and freshness checkpoints
│   │   ├── AuthorizationTransactions.php   ← Session transactions and challenge proof
│   │   ├── SessionAuthenticationRecorder.php ← Explicit authentication events
│   │   ├── RedisAtomicStateStore.php        ← Atomic revisions and one-time state
│   │   ├── OidcAuthorizationServer.php     ← Protected grants and isolated response
│   │   ├── TokenResponseType.php           ← Injects id_token into token response
│   │   ├── IdTokenService.php              ← JWT generation (RS256)
│   │   └── ClaimsService.php               ← Unified claims resolution
│   ├── Http/Controllers/OidcController.php ← OIDC endpoints (6 methods)
│   └── Models/OidcClient.php               ← Consent required by default
├── config/oidc-server.php                         ← Package configuration
├── resources/views/authorize.blade.php     ← Default authorization view
└── routes/web.php                          ← Route registration
```

## Service Provider Auto-Configuration

`OidcServerServiceProvider` registers host contracts and native-route suppression before boot, then configures services and routes during boot:

1. Calls `Passport::ignoreRoutes()` (configurable via `oidc-server.ignore_passport_routes`)
2. Sets the authorization view (`oidc-server.authorization_view`)
3. Sets the Client model (`oidc-server.client_model`)
4. Registers scopes from `oidc-server.scopes`
5. Configures token TTLs from `oidc-server.tokens`
6. Uses a dedicated `OidcAuthorizationServer` with protected code/refresh grants and `TokenResponseType`; native host OAuth servers remain separate
7. Registers OIDC + Passport routes

`configure_passport=false` disables scope/model/TTL auto-configuration; the package server retains its authentication/context checks.

## id_token Injection — TokenResponseType

`TokenResponseType` extends League OAuth2 Server's `BearerTokenResponse`:

The response consumes only request-local context already verified by a grant. Its refresh envelope preserves that context; ID Token generation receives the original authentication time, and receives nonce only on authorization-code exchange. Client credentials have no authentication context and produce no ID Token.

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
| `exp` | Access token expiry | Expiration |
| `auth_time` | Explicit host authentication record | Frozen at code issuance and preserved through refresh |
| `nonce` | Original authorization-code context | Exact request value; omitted on refresh |

Additional claims are added based on requested scopes (see [Claims Resolution](claims-resolution.md) for details).

## Custom Client Model — OidcClient

```php
class OidcClient extends BaseClient
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return false;
    }
}
```

Default clients require consent, and existing tokens do not prove historical explicit approval. Custom client-model overrides are explicit operator trust decisions. See the [2.0 integration guide](upgrading-to-2.0.0.md).

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
  │                                         │  9. Logout guard, rotate session, redirect back
  │ ←─────────────────────────────────────── │
```
