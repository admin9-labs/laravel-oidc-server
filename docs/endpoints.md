# Endpoint Reference

This document describes all HTTP endpoints provided by the `laravel-oidc-server` package.

## Endpoint Overview

| # | Method | Path | Purpose | Auth Required |
|---|--------|------|---------|---------------|
| 1 | `GET` | `/.well-known/openid-configuration` | OIDC Discovery | No |
| 2 | `GET` | `/.well-known/jwks.json` | JSON Web Key Set | No |
| 3 | `GET` | `/oauth/authorize` | Authorization | Session (user login) |
| 4 | `POST` | `/oauth/token` | Token issuance (with `id_token` injection) | Client credentials |
| 5 | `GET\|POST` | `/oauth/userinfo` | UserInfo | Bearer token |
| 6 | `POST` | `/oauth/introspect` | Token Introspection | Client credentials |
| 7 | `POST` | `/oauth/revoke` | Token Revocation | Client credentials |
| 8 | `GET` | `/oauth/logout` | RP-Initiated Logout | None (optional `id_token_hint`) |

---

## 1. OIDC Discovery

Returns the OpenID Connect Provider Configuration document, allowing clients to dynamically discover all endpoint URLs and supported capabilities.

- **Method:** `GET`
- **Path:** `/.well-known/openid-configuration`
- **Authentication:** None
- **Middleware:** Configurable via `oidc-server.routes.discovery_middleware`
- **Cache:** `Cache-Control: public, max-age=3600`
- **Spec:** [OpenID Connect Discovery 1.0](https://openid.net/specs/openid-connect-discovery-1_0.html)

### Request

```http
GET /.well-known/openid-configuration HTTP/1.1
Host: your-app.example.com
```

### Response

```json
{
  "issuer": "https://your-app.example.com",
  "authorization_endpoint": "https://your-app.example.com/oauth/authorize",
  "token_endpoint": "https://your-app.example.com/oauth/token",
  "userinfo_endpoint": "https://your-app.example.com/oauth/userinfo",
  "jwks_uri": "https://your-app.example.com/.well-known/jwks.json",
  "end_session_endpoint": "https://your-app.example.com/oauth/logout",
  "introspection_endpoint": "https://your-app.example.com/oauth/introspect",
  "revocation_endpoint": "https://your-app.example.com/oauth/revoke",
  "response_types_supported": ["code", "token"],
  "subject_types_supported": ["public"],
  "id_token_signing_alg_values_supported": ["RS256"],
  "scopes_supported": ["openid", "profile", "email"],
  "token_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post"],
  "claims_supported": ["sub", "iss", "aud", "exp", "iat", "auth_time", "name", "nickname", "picture", "updated_at", "email", "email_verified"],
  "code_challenge_methods_supported": ["S256", "plain"],
  "grant_types_supported": ["authorization_code", "refresh_token", "client_credentials", "urn:ietf:params:oauth:grant-type:device_code"],
  "introspection_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post"],
  "revocation_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post"],
  "post_logout_redirect_uris_supported": []
}
```

---

## 2. JSON Web Key Set (JWKS)

Exposes the server's public RSA key in JWK format so that clients and resource servers can verify the signatures of ID tokens and access tokens.

- **Method:** `GET`
- **Path:** `/.well-known/jwks.json`
- **Authentication:** None
- **Middleware:** Configurable via `oidc-server.routes.discovery_middleware`
- **Cache:** `Cache-Control: public, max-age=86400`
- **Spec:** [RFC 7517 -- JSON Web Key](https://datatracker.ietf.org/doc/html/rfc7517)

### Request

```http
GET /.well-known/jwks.json HTTP/1.1
Host: your-app.example.com
```

### Response

```json
{
  "keys": [
    {
      "kty": "RSA",
      "alg": "RS256",
      "use": "sig",
      "kid": "a1b2c3d4e5f6g7h8",
      "n": "0vx7agoebGc...base64url-encoded-modulus...",
      "e": "AQAB"
    }
  ]
}
```

> The `kid` (Key ID) is derived from a SHA-256 hash of the public key file at `storage/oauth-public.key`.

---

## 3. Authorization Endpoint

Initiates the OAuth 2.0 Authorization Code flow. The user is presented with a consent screen and, upon approval, is redirected back to the client with an authorization code.

- **Method:** `GET`
- **Path:** `/oauth/authorize`
- **Authentication:** User session (the user must be logged in)
- **Middleware:** `web` (session-based)
- **Spec:** [RFC 6749 Section 4.1](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1), [OpenID Connect Core 1.0 Section 3.1.2](https://openid.net/specs/openid-connect-core-1_0.html#AuthorizationEndpoint)

This endpoint is provided by Laravel Passport's built-in `AuthorizationController`. The package also registers `POST /oauth/authorize` (approve) and `DELETE /oauth/authorize` (deny) for handling the consent form submission.

### Request

```http
GET /oauth/authorize?response_type=code
    &client_id=your-client-id
    &redirect_uri=https://client.example.com/callback
    &scope=openid+profile+email
    &state=random-csrf-string
    &code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
    &code_challenge_method=S256
    &nonce=random-nonce-value HTTP/1.1
Host: your-app.example.com
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `response_type` | Yes | Must be `code` for Authorization Code flow |
| `client_id` | Yes | The OAuth client identifier |
| `redirect_uri` | Yes | Must match a registered redirect URI for the client |
| `scope` | Recommended | Space-separated list of scopes (e.g., `openid profile email`) |
| `state` | Recommended | Opaque value for CSRF protection, returned unchanged |
| `code_challenge` | Recommended | PKCE code challenge (required when using PKCE) |
| `code_challenge_method` | Recommended | `S256` (recommended) or `plain` |
| `nonce` | Optional | Value passed through to the ID token for replay protection |

### Response (redirect)

```http
HTTP/1.1 302 Found
Location: https://client.example.com/callback?code=def50200abc...&state=random-csrf-string
```

---

## 4. Token Endpoint

Exchanges an authorization code (or refresh token) for an access token. When the `openid` scope is present, an `id_token` (signed JWT) is automatically included in the response.

- **Method:** `POST`
- **Path:** `/oauth/token`
- **Authentication:** Client credentials (`client_secret_basic` or `client_secret_post`)
- **Middleware:** Configurable via `oidc-server.routes.token_middleware`
- **Spec:** [RFC 6749 Section 4.1.3](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.3), [OpenID Connect Core 1.0 Section 3.1.3](https://openid.net/specs/openid-connect-core-1_0.html#TokenEndpoint)

This endpoint is provided by Laravel Passport's built-in `AccessTokenController`. The package injects a custom `TokenResponseType` that appends the `id_token` field when the `openid` scope was granted.

### Request (Authorization Code Grant)

```http
POST /oauth/token HTTP/1.1
Host: your-app.example.com
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&code=def50200abc...
&redirect_uri=https://client.example.com/callback
&client_id=your-client-id
&client_secret=your-client-secret
&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
```

### Request (Refresh Token Grant)

```http
POST /oauth/token HTTP/1.1
Host: your-app.example.com
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&refresh_token=def50200xyz...
&client_id=your-client-id
&client_secret=your-client-secret
&scope=openid+profile
```

### Response

```json
{
  "token_type": "Bearer",
  "expires_in": 900,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def50200abc123...",
  "id_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9..."
}
```

> The `id_token` field is only present when the `openid` scope was included in the original authorization request. The ID token is a signed JWT containing claims such as `sub`, `iss`, `aud`, `exp`, `iat`, `auth_time`, and `nonce`, plus any additional claims resolved from the granted scopes (e.g., `name`, `email`).

---

## 5. UserInfo Endpoint

Returns claims about the authenticated user based on the scopes granted to the access token.

- **Method:** `GET` or `POST`
- **Path:** `/oauth/userinfo`
- **Authentication:** Bearer token (`Authorization: Bearer <access_token>`)
- **Middleware:** Configurable via `oidc-server.routes.userinfo_middleware` (default: `auth:api`)
- **Spec:** [OpenID Connect Core 1.0 Section 5.3](https://openid.net/specs/openid-connect-core-1_0.html#UserInfo)

### Request

```http
GET /oauth/userinfo HTTP/1.1
Host: your-app.example.com
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
```

### Response (with `openid profile email` scopes)

```json
{
  "sub": "12345",
  "name": "Jane Doe",
  "nickname": "janedoe",
  "picture": "https://example.com/avatar/jane.jpg",
  "updated_at": 1700000000,
  "email": "jane@example.com",
  "email_verified": true
}
```

### Error Response (invalid or expired token)

```json
HTTP/1.1 401 Unauthorized

{
  "error": "invalid_token",
  "error_description": "The access token is invalid or expired."
}
```

> The claims returned depend on the scopes associated with the access token. If the user model does not implement `OidcUserInterface`, only the `sub` claim (set to the user's primary key) is returned.

---

## 6. Token Introspection

Allows a client to determine whether an access token or refresh token is currently active and retrieve metadata about it.

- **Method:** `POST`
- **Path:** `/oauth/introspect`
- **Authentication:** Client credentials (`client_secret_basic` or `client_secret_post`)
- **Middleware:** Configurable via `oidc-server.routes.token_middleware`
- **Spec:** [RFC 7662 -- OAuth 2.0 Token Introspection](https://datatracker.ietf.org/doc/html/rfc7662)

### Request

```http
POST /oauth/introspect HTTP/1.1
Host: your-app.example.com
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
&token_type_hint=access_token
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `token` | Yes | The token string to introspect |
| `token_type_hint` | Optional | `access_token` (default) or `refresh_token` |

### Response (active access token)

```json
{
  "active": true,
  "scope": "openid profile email",
  "client_id": "your-client-id",
  "username": "jane@example.com",
  "token_type": "Bearer",
  "exp": 1700000900,
  "iat": 1700000000,
  "sub": "12345",
  "aud": "your-client-id",
  "iss": "https://your-app.example.com"
}
```

### Response (active refresh token)

```json
{
  "active": true,
  "token_type": "refresh_token",
  "exp": 1700604800,
  "client_id": "your-client-id"
}
```

### Response (inactive or unknown token)

```json
{
  "active": false
}
```

### Error Response (client authentication failure)

```json
HTTP/1.1 401 Unauthorized

{
  "error": "invalid_client",
  "error_description": "Client authentication failed."
}
```

---

## 7. Token Revocation

Revokes an access token or refresh token. When an access token is revoked, all associated refresh tokens are also revoked, and vice versa.

- **Method:** `POST`
- **Path:** `/oauth/revoke`
- **Authentication:** Client credentials (`client_secret_basic` or `client_secret_post`)
- **Middleware:** Configurable via `oidc-server.routes.token_middleware`
- **Spec:** [RFC 7009 -- OAuth 2.0 Token Revocation](https://datatracker.ietf.org/doc/html/rfc7009)

### Request

```http
POST /oauth/revoke HTTP/1.1
Host: your-app.example.com
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
&token_type_hint=access_token
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `token` | Yes | The token string to revoke |
| `token_type_hint` | Optional | `access_token` (default) or `refresh_token` |

### Response (success)

```http
HTTP/1.1 200 OK
Content-Type: application/json

{}
```

> Per RFC 7009, the server returns `200 OK` regardless of whether the token was found or already revoked. The only error case is client authentication failure (401).

### Error Response (client authentication failure)

```json
HTTP/1.1 401 Unauthorized

{
  "error": "invalid_client",
  "error_description": "Client authentication failed."
}
```

---

## 8. RP-Initiated Logout

Allows a Relying Party (client application) to log the user out of the OpenID Provider. The server invalidates the user's session and optionally redirects to a post-logout URI.

- **Method:** `GET`
- **Path:** `/oauth/logout`
- **Authentication:** None required (optional `id_token_hint` for client identification)
- **Middleware:** Configurable via `oidc-server.routes.token_middleware`
- **Spec:** [OpenID Connect RP-Initiated Logout 1.0](https://openid.net/specs/openid-connect-rpinitiated-1_0.html)

### Request

```http
GET /oauth/logout?id_token_hint=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
    &post_logout_redirect_uri=https://client.example.com/logged-out
    &state=random-state-value HTTP/1.1
Host: your-app.example.com
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `id_token_hint` | Optional | The ID token previously issued to the client; used to identify the client for redirect URI validation |
| `post_logout_redirect_uri` | Optional | URL to redirect the user to after logout |
| `state` | Optional | Opaque value passed through to the redirect URI |

### Response (with valid post-logout redirect)

```http
HTTP/1.1 302 Found
Location: https://client.example.com/logged-out?state=random-state-value
```

### Response (without redirect or invalid redirect)

```http
HTTP/1.1 302 Found
Location: /
```

> The `post_logout_redirect_uri` is validated against the client's registered redirect URIs (when `id_token_hint` is provided) or against the application URL. If validation fails, the user is redirected to the application root (`/`).

---

## Client Authentication Methods

Endpoints that require client authentication (Token, Introspect, Revoke) support two methods:

### HTTP Basic Authentication (`client_secret_basic`)

```http
Authorization: Basic base64(client_id:client_secret)
```

### POST Body (`client_secret_post`)

```http
Content-Type: application/x-www-form-urlencoded

client_id=your-client-id&client_secret=your-client-secret
```

---

## OIDC Authorization Code Flow Lifecycle

The following diagram illustrates the complete lifecycle of an OpenID Connect Authorization Code flow through the endpoints provided by this package:

```
User-Agent              Client App              OIDC Server
    |                       |                       |
    |  1. Click "Login"     |                       |
    |---------------------->|                       |
    |                       |                       |
    |  2. Redirect to /oauth/authorize              |
    |<----------------------------------------------|
    |                       |                       |
    |  3. User authenticates & consents             |
    |---------------------------------------------->|
    |                       |                       |
    |  4. Redirect with authorization code          |
    |<----------------------------------------------|
    |                       |                       |
    |  5. Follow redirect   |                       |
    |---------------------->|                       |
    |                       |                       |
    |                       |  6. POST /oauth/token |
    |                       |  (exchange code)      |
    |                       |---------------------->|
    |                       |                       |
    |                       |  7. access_token +    |
    |                       |     id_token +        |
    |                       |     refresh_token     |
    |                       |<----------------------|
    |                       |                       |
    |                       |  8. GET /oauth/       |
    |                       |     userinfo          |
    |                       |---------------------->|
    |                       |                       |
    |                       |  9. User claims       |
    |                       |<----------------------|
    |                       |                       |
    |  10. User is logged in|                       |
    |<----------------------|                       |
    |                       |                       |
    :   ... time passes ... :                       :
    |                       |                       |
    |  11. Click "Logout"   |                       |
    |---------------------->|                       |
    |                       |                       |
    |                       | 12. POST /oauth/revoke|
    |                       |     (revoke tokens)   |
    |                       |---------------------->|
    |                       |<----------------------|
    |                       |                       |
    |  13. Redirect to /oauth/logout                |
    |<----------------------------------------------|
    |                       |                       |
    |  14. Session destroyed, redirect back         |
    |---------------------------------------------->|
    |<----------------------------------------------|
    |                       |                       |
```

**Supporting endpoints used throughout the lifecycle:**

- `GET /.well-known/openid-configuration` -- Client discovers all endpoint URLs at startup.
- `GET /.well-known/jwks.json` -- Client or resource server fetches public keys to verify token signatures.
- `POST /oauth/introspect` -- Resource server validates tokens server-side (alternative to local JWT verification).

---

## References

| Specification | URL |
|---------------|-----|
| OpenID Connect Core 1.0 | https://openid.net/specs/openid-connect-core-1_0.html |
| OpenID Connect Discovery 1.0 | https://openid.net/specs/openid-connect-discovery-1_0.html |
| OpenID Connect RP-Initiated Logout 1.0 | https://openid.net/specs/openid-connect-rpinitiated-1_0.html |
| RFC 6749 -- OAuth 2.0 Authorization Framework | https://datatracker.ietf.org/doc/html/rfc6749 |
| RFC 7009 -- OAuth 2.0 Token Revocation | https://datatracker.ietf.org/doc/html/rfc7009 |
| RFC 7517 -- JSON Web Key (JWK) | https://datatracker.ietf.org/doc/html/rfc7517 |
| RFC 7636 -- PKCE | https://datatracker.ietf.org/doc/html/rfc7636 |
| RFC 7662 -- OAuth 2.0 Token Introspection | https://datatracker.ietf.org/doc/html/rfc7662 |
