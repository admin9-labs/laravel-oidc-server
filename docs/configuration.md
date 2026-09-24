# Configuration Reference

This document provides a complete reference for all configuration options available in the `laravel-oidc-server` package.

## Publishing the Config

Publish the configuration file with Artisan:

```bash
php artisan vendor:publish --tag=oidc-server-config
```

This copies `oidc-server.php` into your application's `config/` directory.

---

## Config Sections

### `issuer`

| Key | Type | Default | Env Variable |
|-----|------|---------|--------------|
| `issuer` | `string` | `env('APP_URL')` | `OIDC_ISSUER` |

The OpenID Connect Issuer Identifier. This value appears in the `iss` claim of ID tokens and in the discovery document at `/.well-known/openid-configuration`. Defaults to your application URL.

---

### `user_model`

| Key | Type | Default |
|-----|------|---------|
| `user_model` | `string\|null` | `null` |

The fully-qualified Eloquent model class used to look up users when generating ID tokens. When `null`, the package uses the provider model of `passport.guard` (or `auth.defaults.guard` when `passport.guard` is null). For custom providers without an Eloquent model configuration, set this to the same Eloquent model class returned by that provider.

### Alternate user models and guards

`passport.guard` is the single session guard setting for interactive authorization and `/oauth/logout`. It must name a stateful session guard. `user_model` controls user lookup for OIDC context validation and ID Token generation; it does not change the browser login or the bearer-token provider used by UserInfo.

For a Member identity separate from administrator User records, configure all three consistently:

```php
// config/auth.php (merge with your existing configuration)
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'member_web' => ['driver' => 'session', 'provider' => 'members'],
    'api' => ['driver' => 'passport', 'provider' => 'members'],
],
'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'members' => ['driver' => 'eloquent', 'model' => App\Models\Member::class],
],

// config/passport.php
'guard' => 'member_web',

// config/oidc-server.php
'user_model' => App\Models\Member::class, // null also resolves members.model
'routes' => [
    'enabled' => true,
    'discovery_middleware' => [],
    'authorization_middleware' => [],
    'token_middleware' => [],
    'userinfo_middleware' => ['auth:api'],
],
```

Member must implement `OidcUserInterface`, use `HasOidcClaims` (or provide its own claims), and satisfy the installed Passport version's user-model requirements, including `HasApiTokens` and, for Passport 13, `OAuthenticatable`. Your member login must authenticate `member_web`; configure the application's unauthenticated redirect to the member login page for that guard. If an OAuth client has a non-null `provider`, it must be `members`. Existing clients, access tokens, and refresh tokens issued for another identity provider must not be reused after switching providers.

An explicit `user_model` must use the same model class as the provider of `passport.guard` (or `auth.defaults.guard` when `passport.guard` is null). The authenticated user and the configured model lookup must return the same runtime PHP class. Different classes are not supported even when they share a table, user ID and OIDC subject. The UserInfo provider must also resolve the same users; unrelated tables can have matching numeric IDs, so changing only `user_model` is unsafe.

By default, Passport handles GET authorization authentication (including `prompt=none`), and POST/DELETE authorization always require the selected guard. Optional `routes.authorization_middleware`, such as `['auth:member_web']`, applies to all three methods without protecting Discovery/JWKS. An authentication middleware on GET runs before Passport and therefore replaces its unauthenticated `prompt=none` handling.

`/oauth/logout` logs out only the selected guard, clears pending Passport authorization state and that guard's `auth.session` password hash, and rotates the session ID and CSRF token while preserving other session data. Laravel's shared password-confirmation timestamp is also cleared, so a subsequent user must confirm their own password; other logged-in guards may need to reconfirm for sensitive actions. This replaces the previous whole-session invalidation behavior; applications that need to clear additional data should use an `OidcLogoutInitiated` listener. This isolation applies only to the package logout endpoint. Passport handles prompt=login natively and may invalidate the shared session, including other guards. max_age and Essential auth_time requests are rejected; see [the upgrade guide](upgrading-to-1.2.2.md#nonce-and-authentication-freshness).

---

### `configure_passport`

| Key | Type | Default |
|-----|------|---------|
| `configure_passport` | `bool` | `true` |

When `true`, the package automatically configures Laravel Passport: registers scopes, sets token TTLs, sets the response type, assigns the client model, and registers the authorization view. Set to `false` if you want full manual control over Passport configuration.

---

### `ignore_passport_routes`

| Key | Type | Default |
|-----|------|---------|
| `ignore_passport_routes` | `bool` | `true` |

When `true`, the package calls `Passport::ignoreRoutes()` to prevent Passport from registering its default routes. Set to `false` if you need Passport's built-in routes alongside the OIDC routes.

---

### `authorization_view`

| Key | Type | Default |
|-----|------|---------|
| `authorization_view` | `string` | `'oidc-server::authorize'` |

The Blade view rendered for the OAuth authorization prompt. You can publish the default view and customize it, or point this to your own view.

---

### `client_model`

| Key | Type | Default |
|-----|------|---------|
| `client_model` | `string` | `\Admin9\OidcServer\Models\OidcClient::class` |

The default client model requires explicit consent. Existing tokens do not bypass the package authorization prompt. Custom `skipsAuthorization()` overrides are deliberate trusted-client policy; see [the upgrade guide](upgrading-to-1.2.2.md).

---

### `scopes`

| Key | Type | Default |
|-----|------|---------|
| `scopes` | `array<string, array>` | See below |

Defines the supported OIDC scopes. Each key is a scope name, and its value is an array with:

- `description` (string) -- Human-readable description shown on the consent screen.
- `claims` (array of strings) -- The claims included when this scope is granted.

Default scopes:

| Scope | Claims |
|-------|--------|
| `openid` | `sub` |
| `profile` | `name`, `nickname`, `picture`, `updated_at` |
| `email` | `email`, `email_verified` |

---

### `default_scopes`

| Key | Type | Default |
|-----|------|---------|
| `default_scopes` | `array` | `['openid']` |

Scopes applied automatically when a client does not explicitly request any.

---

### `claims_resolver`

| Key | Type | Default |
|-----|------|---------|
| `claims_resolver` | `array` | `[]` |

A map of claim names to model attributes or callables. Entries here take priority over `default_claims_map`. Use this to customize how individual claims are resolved from your User model.

```php
'claims_resolver' => [
    'nickname' => 'public_name',
    'picture' => fn ($user) => $user->avatar_url,
],
```

---

### `default_claims_map`

| Key | Type | Default |
|-----|------|---------|
| `default_claims_map` | `array` | See below |

Fallback map used by the `HasOidcClaims` trait when no entry exists in `claims_resolver`. Override these to match your User model's schema.

| Claim | Default Resolution |
|-------|--------------------|
| `name` | `$user->name` |
| `email` | `$user->email` |
| `email_verified` | `$user->email_verified_at !== null` |
| `updated_at` | `$user->updated_at` (as Unix timestamp) |

---

### `tokens`

| Key | Type | Default | Env Variable |
|-----|------|---------|--------------|
| `tokens.access_token_ttl` | `int` | `900` | `OIDC_ACCESS_TOKEN_TTL` |
| `tokens.refresh_token_ttl` | `int` | `604800` | `OIDC_REFRESH_TOKEN_TTL` |
| `tokens.id_token_ttl` | `int` | `900` | `OIDC_ID_TOKEN_TTL` |

All values are in **seconds**.

- `access_token_ttl` -- Lifetime of access tokens. Default: 900 (15 minutes).
- `refresh_token_ttl` -- Lifetime of refresh tokens. Default: 604800 (7 days).
- `id_token_ttl` -- Reserved for future use. Currently the ID token expiry follows the access token TTL.

---

### `response_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `response_types_supported` | `array` | `['code']` |

The package endpoints enforce `code`; Discovery reports `['code']` even if an older published configuration contains `token`.

---

### `grant_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `grant_types_supported` | `array` | See below |

Grant types advertised in the discovery document. Defaults:

- `authorization_code`
- `refresh_token`
- `client_credentials`

---

### `token_endpoint_auth_methods_supported`

| Key | Type | Default |
|-----|------|---------|
| `token_endpoint_auth_methods_supported` | `array` | `['client_secret_basic', 'client_secret_post']` |

Authentication methods the token endpoint accepts, advertised in the discovery document.

---

### `id_token_signing_alg_values_supported`

| Key | Type | Default |
|-----|------|---------|
| `id_token_signing_alg_values_supported` | `array` | `['RS256']` |

Signing algorithms used for ID tokens, advertised in the discovery document.

---

### `subject_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `subject_types_supported` | `array` | `['public']` |

Subject identifier types supported, advertised in the discovery document.

---

### `code_challenge_methods_supported`

| Key | Type | Default |
|-----|------|---------|
| `code_challenge_methods_supported` | `array` | `['S256']` |

The package enforces and advertises only `S256`. The old metadata array cannot enable `plain`.

---

### `post_logout_redirect_uris_supported`

| Key | Type | Default |
|-----|------|---------|
| `post_logout_redirect_uris_supported` | `array` | `[]` |

Exact URI allowlist for local confirmed logout without a client. Use `post_logout_redirect_uris` (client ID => URI array) for per-client registration; otherwise the client's OAuth redirect URIs apply exactly. These lists are not combined. Arbitrary same-origin redirects are rejected.

`introspection_allowed_clients` maps a confidential querying client ID to additional token-owning client IDs (strings). Default: `[]`, permitting only the caller's own tokens. This never permits cross-client revocation.

---

### `routes`

| Key | Type | Default |
|-----|------|---------|
| `routes.enabled` | `bool` | `true` |
| `routes.discovery_middleware` | `array` | `[]` |
| `routes.authorization_middleware` | `array` | `[]` |
| `routes.token_middleware` | `array` | `[]` |
| `routes.userinfo_middleware` | `array` | `['auth:api']` |

- `enabled` -- Set to `false` to disable all routes registered by the package.
- `discovery_middleware` -- Middleware applied to the `/.well-known/openid-configuration` and JWKS endpoints.
- `authorization_middleware` -- Additional middleware for GET/POST/DELETE `/oauth/authorize`. POST/DELETE also require authentication via `passport.guard`.
- `token_middleware` -- Middleware applied to the `/oauth/token`, `/oauth/introspect`, and `/oauth/revoke` endpoints. Logout uses the `web` middleware group for sessions.
- `userinfo_middleware` -- Middleware applied to the userinfo endpoint. Defaults to `auth:api`.

Upgrade note: authorization no longer inherits `discovery_middleware`. Move any authorization-specific middleware to `authorization_middleware` and leave public metadata middleware in `discovery_middleware`.

---

## Environment Variables Reference

| Variable | Config Key | Type | Default | Description |
|----------|-----------|------|---------|-------------|
| `OIDC_ISSUER` | `issuer` | `string` | `APP_URL` | OpenID Connect Issuer Identifier |
| `OIDC_ACCESS_TOKEN_TTL` | `tokens.access_token_ttl` | `int` | `900` | Access token lifetime in seconds |
| `OIDC_REFRESH_TOKEN_TTL` | `tokens.refresh_token_ttl` | `int` | `604800` | Refresh token lifetime in seconds |
| `OIDC_ID_TOKEN_TTL` | `tokens.id_token_ttl` | `int` | `900` | Reserved for future use |
