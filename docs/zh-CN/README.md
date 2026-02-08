# Laravel OIDC Server

[![Latest Version on Packagist](https://img.shields.io/packagist/v/admin9/laravel-oidc-server.svg?style=flat-square)](https://packagist.org/packages/admin9/laravel-oidc-server)
[![Total Downloads](https://img.shields.io/packagist/dt/admin9/laravel-oidc-server.svg?style=flat-square)](https://packagist.org/packages/admin9/laravel-oidc-server)
[![License](https://img.shields.io/packagist/l/admin9/laravel-oidc-server.svg?style=flat-square)](https://packagist.org/packages/admin9/laravel-oidc-server)

适用于 Laravel Passport 的 OpenID Connect 服务器 — 为任何 Laravel + Passport 应用程序添加 OIDC 发现、JWKS、用户信息、令牌自省、令牌撤销和 RP 发起的登出功能。

## 系统要求

- PHP 8.2+
- Laravel 11 或 12
- Laravel Passport 12 或 13

## 快速开始

> **前置条件：** 在使用本扩展包之前，必须先安装并配置 [Laravel Passport](https://laravel.com/docs/passport)。

### 1. 安装扩展包

```bash
composer require admin9/laravel-oidc-server
```

### 2. 在 User 模型上实现接口

```php
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;

class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims;

    // Optional: Override for custom claims
    protected function resolveOidcClaim(string $claim): mixed
    {
        return match ($claim) {
            'nickname' => $this->display_name,
            'picture' => $this->avatar_url,
            default => parent::resolveOidcClaim($claim),
        };
    }
}
```

### 3. 生成 Passport 密钥

```bash
php artisan passport:keys
```

这将创建用于签名令牌所需的 RSA 密钥对（`storage/oauth-private.key` 和 `storage/oauth-public.key`）。

### 4. 创建 OAuth 客户端

创建一个将使用您的 OIDC 服务器的客户端应用程序：

```bash
# 用于授权码流程（推荐用于 Web 应用）
php artisan passport:client

# 用于客户端凭证授权（推荐用于机器对机器通信，如微服务）
php artisan passport:client --client

# 或安装默认客户端（个人访问令牌 + 密码授权）
php artisan passport:install
```

您将收到一个 **Client ID** 和 **Client Secret** — 保存这些信息以便配置您的客户端应用程序。

**授权类型说明：**
- **Authorization Code Flow**：适用于有用户交互的 Web 应用，最安全
- **Client Credentials Grant**：适用于服务端到服务端的 API 调用，无需用户参与
- **Password Grant**：仅适用于第一方可信应用，不推荐用于第三方

### 5. （可选）发布并自定义配置

```bash
php artisan vendor:publish --tag=oidc-server-config
```

编辑 `config/oidc-server.php` 以自定义作用域、声明、令牌 TTL 等。

---

**就是这样！** 您的 OIDC 服务器已准备就绪。通过访问以下地址进行测试：

```
https://your-app.test/.well-known/openid-configuration
```

## 端点

| 端点 | 方法 | 描述 |
|---|---|---|
| `/.well-known/openid-configuration` | GET | OIDC 发现 |
| `/.well-known/jwks.json` | GET | JSON Web 密钥集 |
| `/oauth/authorize` | GET | 授权（Passport） |
| `/oauth/token` | POST | 令牌（Passport） |
| `/oauth/userinfo` | GET/POST | 用户信息 |
| `/oauth/introspect` | POST | 令牌自省（RFC 7662） |
| `/oauth/revoke` | POST | 令牌撤销（RFC 7009） |
| `/oauth/logout` | GET | RP 发起的登出 |

## 配置

发布配置文件后，您可以在 `config/oidc-server.php` 中自定义各个方面：

### 用户模型

默认情况下，扩展包使用 `config('auth.providers.users.model')` 在生成 ID 令牌时查找用户。如有需要可以覆盖：

```php
'user_model' => \App\Models\User::class,
```

### Passport 路由控制

扩展包默认调用 `Passport::ignoreRoutes()` 以防止路由冲突。如果您需要在 OIDC 之外使用 Passport 的默认路由，请禁用此选项：

```php
'ignore_passport_routes' => false,
```

### 默认声明映射

`HasOidcClaims` trait 通过可配置的映射解析标准声明。覆盖以匹配您的 User 模型架构：

```php
'default_claims_map' => [
    'name' => 'name',           // string = model attribute
    'email' => 'email',
    'email_verified' => fn ($user) => $user->email_verified_at !== null,
    'updated_at' => fn ($user) => $user->updated_at?->timestamp,
],
```

对于自定义声明（例如 `nickname`、`picture`），请使用 `claims_resolver` 或在您的 User 模型中覆盖 `resolveOidcClaim()`。

### 其他选项

- **作用域和声明映射** — `scopes`、`claims_resolver`
- **令牌 TTL** — `tokens.access_token_ttl`、`tokens.refresh_token_ttl`、`tokens.id_token_ttl`
- **路由中间件** — `routes.discovery_middleware`、`routes.token_middleware`、`routes.userinfo_middleware`
- **Passport 自动配置** — `configure_passport`（设置为 `false` 以自行配置 Passport）

查看[配置参考](configuration.md)了解所有可用选项。

## 文档

- [架构](architecture.md)
- [配置参考](configuration.md)
- [端点参考](endpoints.md)
- [声明解析](claims-resolution.md)
- [扩展点](extension-points.md)
- [故障排除](troubleshooting.md)

## 许可证

[MIT](../../LICENSE.md)
