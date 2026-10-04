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

用于在生成 ID 令牌时查找用户的完全限定 Eloquent 模型类。当为 `null` 时，使用 `passport.guard` 对应 provider 的模型；若 `passport.guard` 为 null，则使用 `auth.defaults.guard`。自定义 provider 没有 Eloquent 模型配置时，需要显式设置为该 provider 返回的同一 Eloquent 模型类。

### 替代用户模型与 guard

`passport.guard` 是交互式授权和 `/oauth/logout` 唯一的会话 guard 配置，必须指向有状态的 session guard。`user_model` 控制 OIDC 上下文校验和 ID 令牌生成时的用户查询，不会改变浏览器登录或 UserInfo 使用的 Bearer Token provider。

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

显式设置的 `user_model` 必须与 `passport.guard` 对应 provider 使用同一模型类；若 `passport.guard` 为 null，则以 `auth.defaults.guard` 为准。已认证用户与配置模型查询结果的运行时 PHP 类必须相同，即使不同类使用同一表、用户 ID 和 OIDC subject，也不支持跨类映射。UserInfo provider 也必须解析到同一组用户；无关表可能存在相同的数字 ID，因此仅修改 `user_model` 是不安全的。

本包通过宿主显式认证契约编排 GET 授权，包括 `prompt=none`。POST/DELETE 必须提供有效的事务凭据，且认证快照须与所选 guard 的当前用户匹配。注册路由时，本包会从 `routes.authorization_middleware` 中过滤 `auth`、`auth:*` 和 `Illuminate\Auth\Middleware\Authenticate` 类名，确保访客和重新认证请求可以到达本包流程。剩余中间件应用于这三种方法，不会保护 Discovery/JWKS。仍作用于 GET 的自定义认证中间件可能将本包的 `prompt=none` 错误响应替换为登录跳转。

`/oauth/logout` 仅注销所选 guard，清除 Passport 待确认授权状态及该 guard 的 `auth.session` 密码哈希，轮换 session ID 与 CSRF token，并保留其他会话数据。Laravel 共享的密码确认时间也会清除，确保后续登录者确认自己的密码；其他已登录 guard 执行敏感操作时可能需要重新确认密码。这替代了此前清空整个会话的行为；需要清理额外数据的应用可通过 `OidcLogoutInitiated` 监听器处理。重新认证使用宿主契约并保留其他 guard。recorder、handler、共享 session/锁及 Redis 要求见 [2.0 接入指引](upgrading-to-2.0.0.md)。

---

### `configure_passport`

| Key | Type | Default |
|-----|------|---------|
| `configure_passport` | `bool` | `true` |

当为 `true` 时，扩展包会自动配置 Laravel Passport：注册作用域、设置令牌 TTL、分配客户端模型并注册授权视图。本包 server 始终使用受保护的 grants/response；设为 `false` 不会关闭认证检查。

---

### `ignore_passport_routes`

| Key | Type | Default |
|-----|------|---------|
| `ignore_passport_routes` | `bool` | `true` |

当为 `true` 时，扩展包调用 `Passport::ignoreRoutes()` 阻止 Passport 注册其默认路由。设为 `false` 时，保留的 Passport 授权/token 路由（包括自定义前缀）是本包 controller 的受保护别名，执行相同的 grant 策略，不会恢复 password、device 或自定义用户 grant。独立的宿主 OAuth 入口必须使用自己的 controller、原生 server/response 和 envelope 加密密钥，见 [2.0 接入指引](upgrading-to-2.0.0.md)。

---

### `freshness.redis_connection`

| Key | Type | Default | Env Variable |
|-----|------|---------|--------------|
| `freshness.redis_connection` | `string` | `'default'` | `OIDC_FRESHNESS_REDIS_CONNECTION` |

用于共享原子认证状态和一次性 code/refresh 状态的 Laravel Redis 连接。所有 web/token worker 必须访问同一个权威可写 Redis 服务端，并允许 `INFO server` 和 Lua 执行。Redis 重启或主节点切换会改变 `run_id`，使旧 freshness 状态失效。该连接不配置 Laravel session 存储或 session blocking 使用的缓存存储；它们也必须满足 [共享存储和锁要求](upgrading-to-2.0.0.md)。

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

默认客户端模型要求显式确认，已有令牌不能跳过包授权页。自定义 `skipsAuthorization()` 属于运维明确的信任策略，参见[升级指引](upgrading-to-1.2.2.md)。

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

Client credentials 请求也可能继承这些作用域，包括默认的 `openid`。此类令牌代表客户端，不携带用户认证上下文，也不返回 ID Token。宿主应配置或请求服务 API 所需的作用域；仅包含 `openid` 并不代表用户已认证。

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

包内默认映射使用静态 callable 数组，支持 `php artisan config:cache`。需要配置缓存的自定义 resolver 应使用属性字符串或 `[\App\Support\OidcClaims::class, 'picture']` 这类静态 callable 数组；配置中的闭包无法序列化。已发布的旧配置若含闭包，缓存前需同步替换。

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
| `response_types_supported` | `array` | `['code']` |

包端点仅支持 `code`；即使旧发布配置中包含 `token`，Discovery 也固定返回 `['code']`。

---

### `grant_types_supported`

| Key | Type | Default |
|-----|------|---------|
| `grant_types_supported` | `array` | 见下文 |

保留的配置项。2.0 包端点与 Discovery 固定使用以下列表，即使旧发布配置声明了其他 grant，也不会启用或公布它们：

- `authorization_code`
- `refresh_token`
- `client_credentials`

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
| `code_challenge_methods_supported` | `array` | `['S256']` |

包端点仅接受并宣告 `S256`，旧 metadata 配置不能启用 `plain`。

---

### `post_logout_redirect_uris_supported`

| Key | Type | Default |
|-----|------|---------|
| `post_logout_redirect_uris_supported` | `array` | `[]` |

未指定客户端的本地确认退出使用此精确 URI 白名单。`post_logout_redirect_uris` 按客户端 ID 配置专用 URI 数组，否则精确使用该客户端 OAuth 回调；各列表不合并，不允许同域任意路径。

`introspection_allowed_clients` 将机密查询客户端 ID 映射到额外可查询的令牌所属客户端 ID（字符串）。默认 `[]`，仅可查询自身令牌，且不授予跨客户端撤销权限。

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
- `authorization_middleware` -- 应用于 GET/POST/DELETE `/oauth/authorize` 的额外中间件，内置认证项会按上文说明被过滤。本包在 POST/DELETE 上校验事务凭据，并核对认证快照与 `passport.guard` 当前用户。剩余的自定义 GET 认证中间件可能替代本包的静默授权错误处理。
- `token_middleware` -- 应用于 `/oauth/token`、`/oauth/introspect` 和 `/oauth/revoke` 端点的中间件。登出使用 `web` 中间件组提供会话支持。
- `userinfo_middleware` -- 应用于 userinfo 端点的中间件。默认为 `auth:api`。

升级说明：授权路由不再继承 `discovery_middleware`。请将授权专用中间件移动到 `authorization_middleware`，公开元数据端点所需中间件保留在 `discovery_middleware`。

---

## 环境变量参考

| Variable | Config Key | Type | Default | Description |
|----------|-----------|------|---------|-------------|
| `OIDC_ISSUER` | `issuer` | `string` | `APP_URL` | OpenID Connect 发行者标识符 |
| `OIDC_FRESHNESS_REDIS_CONNECTION` | `freshness.redis_connection` | `string` | `default` | 共享原子 freshness 状态的 Redis 连接 |
| `OIDC_ACCESS_TOKEN_TTL` | `tokens.access_token_ttl` | `int` | `900` | 访问令牌生命周期（秒） |
| `OIDC_REFRESH_TOKEN_TTL` | `tokens.refresh_token_ttl` | `int` | `604800` | 刷新令牌生命周期（秒） |
| `OIDC_ID_TOKEN_TTL` | `tokens.id_token_ttl` | `int` | `900` | 保留供将来使用 |
