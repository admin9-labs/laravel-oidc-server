# Configuration Reference

## Publishing the Config

Publish the configuration file with Artisan:

```bash
php artisan vendor:publish --tag=oidc-config
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

The fully-qualified Eloquent model class used to look up users when generating ID tokens. When `null`, the package falls back to the model defined by your default auth provider in `config/auth.php`.

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

The Passport Client model class. The default `OidcClient` model skips the authorization prompt for first-party clients. Replace with your own model if you need different behavior.

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
- `id_token_ttl` -- Lifetime of ID tokens. Default: 900 (15 minutes).

---

### `response_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `response_types_supported` | `array` | `['code', 'token']` |

OAuth 2.0 response types advertised in the discovery document.

---

### `grant_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `grant_types_supported` | `array` | See below |

Grant types advertised in the discovery document. Defaults:

- `authorization_code`
- `refresh_token`
- `client_credentials`
- `urn:ietf:params:oauth:grant-type:device_code`

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
| `code_challenge_methods_supported` | `array` | `['S256', 'plain']` |

PKCE code challenge methods supported, advertised in the discovery document.

---

### `post_logout_redirect_uris_supported`

| Key | Type | Default |
|-----|------|---------|
| `post_logout_redirect_uris_supported` | `array` | `[]` |

Allowed redirect URIs after logout. Empty by default; add URIs as needed.

---

### `routes`

| Key | Type | Default |
|-----|------|---------|
| `routes.enabled` | `bool` | `true` |
| `routes.discovery_middleware` | `array` | `[]` |
| `routes.token_middleware` | `array` | `[]` |
| `routes.userinfo_middleware` | `array` | `['auth:api']` |

- `enabled` -- Set to `false` to disable all routes registered by the package.
- `discovery_middleware` -- Middleware applied to the `/.well-known/openid-configuration` and JWKS endpoints.
- `token_middleware` -- Middleware applied to the token endpoint.
- `userinfo_middleware` -- Middleware applied to the userinfo endpoint. Defaults to `auth:api`.

---

## Environment Variables Reference

| Variable | Config Key | Type | Default | Description |
|----------|-----------|------|---------|-------------|
| `OIDC_ISSUER` | `issuer` | `string` | `APP_URL` | OpenID Connect Issuer Identifier |
| `OIDC_ACCESS_TOKEN_TTL` | `tokens.access_token_ttl` | `int` | `900` | Access token lifetime in seconds |
| `OIDC_REFRESH_TOKEN_TTL` | `tokens.refresh_token_ttl` | `int` | `604800` | Refresh token lifetime in seconds |
| `OIDC_ID_TOKEN_TTL` | `tokens.id_token_ttl` | `int` | `900` | ID token lifetime in seconds |
