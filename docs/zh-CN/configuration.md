# 配置参考

本文档提供了 `laravel-oidc-server` 扩展包所有可用配置选项的完整参考。

## 发布配置文件

使用 Artisan 命令发布配置文件：

```bash
php artisan vendor:publish --tag=oidc-server-config
```

这会将 `oidc-server.php` 复制到应用程序的 `config/` 目录中。

---

## 配置项说明

### `issuer`

| Key | Type | Default | Env Variable |
|-----|------|---------|--------------|
| `issuer` | `string` | `env('APP_URL')` | `OIDC_ISSUER` |

OpenID Connect 发行者标识符。此值会出现在 ID 令牌的 `iss` 声明中，以及 `/.well-known/openid-configuration` 的发现文档中。默认为应用程序的 URL。

---

### `user_model`

| Key | Type | Default |
|-----|------|---------|
| `user_model` | `string\|null` | `null` |

用于在生成 ID 令牌时查找用户的完全限定 Eloquent 模型类。当为 `null` 时，扩展包会回退到 `config/auth.php` 中默认认证提供者定义的模型。

---

### `configure_passport`

| Key | Type | Default |
|-----|------|---------|
| `configure_passport` | `bool` | `true` |

当为 `true` 时，扩展包会自动配置 Laravel Passport：注册作用域、设置令牌 TTL、设置响应类型、分配客户端模型并注册授权视图。如果您想完全手动控制 Passport 配置，请设置为 `false`。

---

### `ignore_passport_routes`

| Key | Type | Default |
|-----|------|---------|
| `ignore_passport_routes` | `bool` | `true` |

当为 `true` 时，扩展包会调用 `Passport::ignoreRoutes()` 来阻止 Passport 注册其默认路由。如果您需要在 OIDC 路由之外使用 Passport 的内置路由，请设置为 `false`。

---

### `authorization_view`

| Key | Type | Default |
|-----|------|---------|
| `authorization_view` | `string` | `'oidc-server::authorize'` |

用于 OAuth 授权提示的 Blade 视图。您可以发布默认视图并自定义它，或将其指向您自己的视图。

---

### `client_model`

| Key | Type | Default |
|-----|------|---------|
| `client_model` | `string` | `\Admin9\OidcServer\Models\OidcClient::class` |

Passport 客户端模型类。默认的 `OidcClient` 模型会跳过第一方客户端的授权提示。如果需要不同的行为，请替换为您自己的模型。

---

### `scopes`

| Key | Type | Default |
|-----|------|---------|
| `scopes` | `array<string, array>` | 见下文 |

定义支持的 OIDC 作用域。每个键是作用域名称，其值是一个包含以下内容的数组：

- `description`（字符串）-- 在同意屏幕上显示的人类可读描述。
- `claims`（字符串数组）-- 授予此作用域时包含的声明。

默认作用域：

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

当客户端未明确请求任何作用域时自动应用的作用域。

---

### `claims_resolver`

| Key | Type | Default |
|-----|------|---------|
| `claims_resolver` | `array` | `[]` |

声明名称到模型属性或可调用对象的映射。此处的条目优先于 `default_claims_map`。使用此配置来自定义如何从 User 模型解析各个声明。

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
| `default_claims_map` | `array` | 见下文 |

当 `claims_resolver` 中不存在条目时，`HasOidcClaims` trait 使用的回退映射。覆盖这些配置以匹配您的 User 模型架构。

| Claim | Default Resolution |
|-------|--------------------|
| `name` | `$user->name` |
| `email` | `$user->email` |
| `email_verified` | `$user->email_verified_at !== null` |
| `updated_at` | `$user->updated_at`（Unix 时间戳） |

---

### `tokens`

| Key | Type | Default | Env Variable |
|-----|------|---------|--------------|
| `tokens.access_token_ttl` | `int` | `900` | `OIDC_ACCESS_TOKEN_TTL` |
| `tokens.refresh_token_ttl` | `int` | `604800` | `OIDC_REFRESH_TOKEN_TTL` |
| `tokens.id_token_ttl` | `int` | `900` | `OIDC_ID_TOKEN_TTL` |

所有值的单位均为**秒**。

- `access_token_ttl` -- 访问令牌的生命周期。默认：900（15 分钟）。
- `refresh_token_ttl` -- 刷新令牌的生命周期。默认：604800（7 天）。
- `id_token_ttl` -- 保留供将来使用。当前 ID 令牌的过期时间遵循访问令牌 TTL。

---

### `response_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `response_types_supported` | `array` | `['code', 'token']` |

在发现文档中公布的 OAuth 2.0 响应类型。

---

### `grant_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `grant_types_supported` | `array` | 见下文 |

在发现文档中公布的授权类型。默认值：

- `authorization_code`
- `refresh_token`
- `client_credentials`
- `urn:ietf:params:oauth:grant-type:device_code`

---

### `token_endpoint_auth_methods_supported`

| Key | Type | Default |
|-----|------|---------|
| `token_endpoint_auth_methods_supported` | `array` | `['client_secret_basic', 'client_secret_post']` |

令牌端点接受的认证方法，在发现文档中公布。

---

### `id_token_signing_alg_values_supported`

| Key | Type | Default |
|-----|------|---------|
| `id_token_signing_alg_values_supported` | `array` | `['RS256']` |

用于 ID 令牌的签名算法，在发现文档中公布。

---

### `subject_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `subject_types_supported` | `array` | `['public']` |

支持的主体标识符类型，在发现文档中公布。

---

### `code_challenge_methods_supported`

| Key | Type | Default |
|-----|------|---------|
| `code_challenge_methods_supported` | `array` | `['S256', 'plain']` |

支持的 PKCE 代码挑战方法，在发现文档中公布。

---

### `post_logout_redirect_uris_supported`

| Key | Type | Default |
|-----|------|---------|
| `post_logout_redirect_uris_supported` | `array` | `[]` |

注销后允许的重定向 URI。默认为空；根据需要添加 URI。

---

### `routes`

| Key | Type | Default |
|-----|------|---------|
| `routes.enabled` | `bool` | `true` |
| `routes.discovery_middleware` | `array` | `[]` |
| `routes.token_middleware` | `array` | `[]` |
| `routes.userinfo_middleware` | `array` | `['auth:api']` |

- `enabled` -- 设置为 `false` 可禁用扩展包注册的所有路由。
- `discovery_middleware` -- 应用于 `/.well-known/openid-configuration` 和 JWKS 端点的中间件。
- `token_middleware` -- 应用于 `/oauth/token`、`/oauth/introspect`、`/oauth/revoke` 和 `/oauth/logout` 端点的中间件。
- `userinfo_middleware` -- 应用于 userinfo 端点的中间件。默认为 `auth:api`。

---

## 环境变量参考

| Variable | Config Key | Type | Default | Description |
|----------|-----------|------|---------|-------------|
| `OIDC_ISSUER` | `issuer` | `string` | `APP_URL` | OpenID Connect 发行者标识符 |
| `OIDC_ACCESS_TOKEN_TTL` | `tokens.access_token_ttl` | `int` | `900` | 访问令牌生命周期（秒） |
| `OIDC_REFRESH_TOKEN_TTL` | `tokens.refresh_token_ttl` | `int` | `604800` | 刷新令牌生命周期（秒） |
| `OIDC_ID_TOKEN_TTL` | `tokens.id_token_ttl` | `int` | `900` | 保留供将来使用 |
