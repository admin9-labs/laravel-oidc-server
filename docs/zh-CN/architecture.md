# 架构

本文档说明 `laravel-oidc-server` 如何将 Laravel Passport 扩展为完整的 OIDC 身份提供者。

## 概述

Laravel Passport 提供 OAuth2 授权功能（`/oauth/authorize`、`/oauth/token`）。本扩展包在其之上添加了 OpenID Connect 层：

- 当请求 `openid` 作用域时，向令牌响应中注入 `id_token`
- 注册 OIDC Discovery、JWKS、UserInfo、Introspect、Revoke 和 Logout 端点
- 自动配置 Passport（作用域、TTL、客户端模型、授权视图）

## 包结构

```
laravel-oidc-server/
├── src/
│   ├── OidcServerServiceProvider.php       ← 自动配置 Passport
│   ├── Contracts/OidcUserInterface.php     ← 用户模型接口
│   ├── Concerns/HasOidcClaims.php          ← 默认声明解析 trait
│   ├── Services/
│   │   ├── TokenResponseType.php           ← 向令牌响应注入 id_token
│   │   ├── IdTokenService.php              ← JWT 生成（RS256）
│   │   └── ClaimsService.php               ← 统一声明解析
│   ├── Http/Controllers/OidcController.php ← OIDC 端点（6 个方法）
│   └── Models/OidcClient.php               ← 第一方客户端自动批准
├── config/oidc-server.php                         ← 包配置
├── resources/views/authorize.blade.php     ← 默认授权视图
└── routes/web.php                          ← 路由注册
```

## 服务提供者自动配置

`OidcServerServiceProvider` 在 `packageBooted()` 中运行：

1. 调用 `Passport::ignoreRoutes()`（可通过 `oidc-server.ignore_passport_routes` 配置）
2. 设置授权视图（`oidc-server.authorization_view`）
3. 设置客户端模型（`oidc-server.client_model`）
4. 从 `oidc-server.scopes` 注册作用域
5. 从 `oidc-server.tokens` 配置令牌 TTL
6. 用 `TokenResponseType` 替换令牌响应类型（id_token 注入）
7. 注册 OIDC + Passport 路由

通过 `config('oidc-server.configure_passport', false)` 禁用自动配置。

## id_token 注入 — TokenResponseType

`TokenResponseType` 扩展了 League OAuth2 Server 的 `BearerTokenResponse`：

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

标准 OAuth2 响应：
```json
{ "access_token": "...", "refresh_token": "..." }
```

变为 OIDC 响应：
```json
{ "access_token": "...", "refresh_token": "...", "id_token": "..." }
```

## JWT 生成 — IdTokenService

通过 `lcobucci/jwt` 使用 **RS256 非对称签名**：

- 私钥：`storage/oauth-private.key`（签名令牌）
- 公钥：`storage/oauth-public.key`（通过 JWKS 端点公开）

JWT 配置采用延迟加载（首次使用时初始化，而非启动时）。

### 令牌声明

| 声明 | 来源 | 描述 |
|-------|--------|-------------|
| `iss` | `config('oidc-server.issuer')` | 发行者 URL |
| `aud` | Client ID | 受众 |
| `sub` | `$user->getOidcSubject()` | 主体标识符 |
| `iat` | Current time | 签发时间 |
| `exp` | Access token expiry | 过期时间 |
| `auth_time` | Current timestamp | 认证时间 |
| `nonce` | Request parameter | 重放保护 |

根据请求的作用域添加额外声明（详见[声明解析](claims-resolution.md)）。

## 自定义客户端模型 — OidcClient

```php
class OidcClient extends BaseClient
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return $this->firstParty();
    }
}
```

第一方客户端（`first_party = true`）跳过授权提示。第三方客户端显示同意屏幕。通过 `config('oidc-server.client_model')` 覆盖。

## 数据流

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
