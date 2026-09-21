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

用于在生成 ID 令牌时查找用户的完全限定 Eloquent 模型类。当为 `null` 时，使用 `passport.guard` 对应 provider 的模型；若 `passport.guard` 为 null，则使用 `auth.defaults.guard`。自定义 provider 没有 Eloquent 模型配置时，需要显式设置此项。

### 替代用户模型与 guard

`passport.guard` 是交互式授权和 `/oauth/logout` 唯一的会话 guard 配置，必须指向有状态的 session guard。`user_model` 只控制 ID 令牌查询，不会改变浏览器登录或 UserInfo 使用的 Bearer Token provider。

如果 OIDC 使用 Member，而管理员使用独立的 User，应将三处配置对齐：

```php
// config/auth.php（合并到已有配置）
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
'user_model' => App\Models\Member::class, // null 也会解析到 members.model
'routes' => [
    'enabled' => true,
    'discovery_middleware' => [],
    'authorization_middleware' => [],
    'token_middleware' => [],
    'userinfo_middleware' => ['auth:api'],
],
```

Member 必须实现 `OidcUserInterface`，使用 `HasOidcClaims` 或自行提供 claims，并满足已安装 Passport 版本的用户模型要求，包括 `HasApiTokens`，以及 Passport 13 的 `OAuthenticatable`。会员登录必须登录到 `member_web`；应用应为该 guard 配置未认证时跳转的会员登录页。OAuth 客户端若设置了非空 `provider`，必须为 `members`。切换 provider 后，不得复用为原身份域签发的客户端、访问令牌和刷新令牌。

显式设置的 `user_model` 必须与授权及 UserInfo provider 解析到相同身份。扩展包保留模型覆盖和自定义 provider 的支持，无法自动判断不同模型类是否代表同一组用户。无关表可能存在相同的数字 ID，因此仅修改 `user_model` 是不安全的。

默认由 Passport 处理 GET 授权认证（包括 `prompt=none`），POST/DELETE 则始终要求所选 guard 已登录。可选的 `routes.authorization_middleware`（例如 `['auth:member_web']`）应用于这三种方法，不会保护 Discovery/JWKS。GET 上的认证中间件会先于 Passport 执行，因此会替代未登录时的 `prompt=none` 处理。

`/oauth/logout` 仅注销所选 guard，清除 Passport 待确认授权状态及该 guard 的 `auth.session` 密码哈希，轮换 session ID 与 CSRF token，并保留其他会话数据。Laravel 共享的密码确认时间也会清除，确保后续登录者确认自己的密码；其他已登录 guard 执行敏感操作时可能需要重新确认密码。这替代了此前清空整个会话的行为；需要清理额外数据的应用可通过 `OidcLogoutInitiated` 监听器处理。隔离保证仅适用于本扩展包的登出端点；Passport 上游的 `prompt=login` 流程仍可能使共享会话整体失效。

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
| `routes.authorization_middleware` | `array` | `[]` |
| `routes.token_middleware` | `array` | `[]` |
| `routes.userinfo_middleware` | `array` | `['auth:api']` |

- `enabled` -- 设置为 `false` 可禁用扩展包注册的所有路由。
- `discovery_middleware` -- 应用于 `/.well-known/openid-configuration` 和 JWKS 端点的中间件。
- `authorization_middleware` -- 应用于 GET/POST/DELETE `/oauth/authorize` 的额外中间件。POST/DELETE 还必须通过 `passport.guard` 认证。
- `token_middleware` -- 应用于 `/oauth/token`、`/oauth/introspect` 和 `/oauth/revoke` 端点的中间件。登出使用 `web` 中间件组提供会话支持。
- `userinfo_middleware` -- 应用于 userinfo 端点的中间件。默认为 `auth:api`。

升级说明：授权路由不再继承 `discovery_middleware`。请将授权专用中间件移动到 `authorization_middleware`，公开元数据端点所需中间件保留在 `discovery_middleware`。

---

## 环境变量参考

| Variable | Config Key | Type | Default | Description |
|----------|-----------|------|---------|-------------|
| `OIDC_ISSUER` | `issuer` | `string` | `APP_URL` | OpenID Connect 发行者标识符 |
| `OIDC_ACCESS_TOKEN_TTL` | `tokens.access_token_ttl` | `int` | `900` | 访问令牌生命周期（秒） |
| `OIDC_REFRESH_TOKEN_TTL` | `tokens.refresh_token_ttl` | `int` | `604800` | 刷新令牌生命周期（秒） |
| `OIDC_ID_TOKEN_TTL` | `tokens.id_token_ttl` | `int` | `900` | 保留供将来使用 |
