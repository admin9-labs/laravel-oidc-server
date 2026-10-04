# 架构

本文档说明 2.0 的宿主认证契约、授权事务和 Passport/League 签发适配。接入要求见 [2.0 升级指引](upgrading-to-2.0.0.md)，验收状态见[实施证据](../implementation-2.0.md)。

## 概述

本包控制器管理 `/oauth/authorize` 与 `/oauth/token`，通过独立 server 使用 Passport 仓库和 League grants：

- 通过显式宿主认证记录和一次性挑战执行认证新鲜度
- 为授权码事务保存原始参数、认证快照和单次批准凭证
- 验证 code/refresh 上下文后，为 `openid` 响应生成带真实 `auth_time` 的 ID Token
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
│   │   ├── AuthorizationFlow.php           ← 授权与新鲜度检查点
│   │   ├── AuthorizationTransactions.php   ← Session 事务和挑战证明
│   │   ├── SessionAuthenticationRecorder.php ← 显式认证事件
│   │   ├── RedisAtomicStateStore.php        ← 原子版本与一次性状态
│   │   ├── OidcAuthorizationServer.php     ← 受保护 grants 与独立 response
│   │   ├── TokenResponseType.php           ← 向令牌响应注入 id_token
│   │   ├── IdTokenService.php              ← JWT 生成（RS256）
│   │   └── ClaimsService.php               ← 统一声明解析
│   ├── Http/Controllers/OidcController.php ← OIDC 端点（6 个方法）
│   └── Models/OidcClient.php               ← 默认要求同意
├── config/oidc-server.php                         ← 包配置
├── resources/views/authorize.blade.php     ← 默认授权视图
└── routes/web.php                          ← 路由注册
```

## 服务提供者自动配置

`OidcServerServiceProvider` 在注册阶段绑定宿主契约并按配置关闭原生路由，随后在 boot 阶段配置服务和路由：

1. 调用 `Passport::ignoreRoutes()`（可通过 `oidc-server.ignore_passport_routes` 配置）
2. 设置授权视图（`oidc-server.authorization_view`）
3. 设置客户端模型（`oidc-server.client_model`）
4. 从 `oidc-server.scopes` 注册作用域
5. 从 `oidc-server.tokens` 配置令牌 TTL
6. 使用独立 `OidcAuthorizationServer`、受保护 code/refresh grants 和 `TokenResponseType`；宿主原生 OAuth server 单独配置
7. 注册 OIDC + Passport 路由

`configure_passport=false` 关闭作用域/模型/TTL 自动配置；包内 server 的认证与上下文检查继续启用。

## id_token 注入 — TokenResponseType

`TokenResponseType` 扩展了 League OAuth2 Server 的 `BearerTokenResponse`：

响应仅使用 grant 已验证的请求内上下文。刷新 envelope 原样保留上下文；ID Token 使用原认证时间，只有授权码兑换传入 nonce。Client credentials 没有浏览器认证上下文，也不会生成 ID Token。

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
| `auth_time` | 宿主显式认证记录 | code 签发时冻结，refresh 保留原值 |
| `nonce` | 原授权码中绑定的上下文 | 原样返回；刷新时省略 |

根据请求的作用域添加额外声明（详见[声明解析](claims-resolution.md)）。

## 自定义客户端模型 — OidcClient

```php
class OidcClient extends BaseClient
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return false;
    }
}
```

默认客户端要求确认，已有令牌不代表历史显式授权。自定义客户端模型属于运维显式信任策略，参见 [2.0 升级指引](upgrading-to-2.0.0.md)。

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
  │                                         │  9. Logout guard, rotate session, redirect back
  │ ←─────────────────────────────────────── │
```
