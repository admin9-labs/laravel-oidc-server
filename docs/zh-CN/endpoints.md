# 端点参考

本文档提供了 `laravel-oidc-server` 包提供的所有 HTTP 端点的详细信息。

## 端点概览

| # | 方法 | 路径 | 用途 | 需要认证 |
|---|--------|------|---------|---------------|
| 1 | `GET` | `/.well-known/openid-configuration` | OIDC 发现 | 否 |
| 2 | `GET` | `/.well-known/jwks.json` | JSON Web 密钥集 | 否 |
| 3 | `GET` | `/oauth/authorize` | 授权 | 会话（用户登录） |
| 4 | `POST` | `/oauth/token` | 令牌颁发（注入 `id_token`） | 客户端凭证 |
| 5 | `GET\|POST` | `/oauth/userinfo` | 用户信息 | Bearer 令牌 |
| 6 | `POST` | `/oauth/introspect` | 令牌内省 | 客户端凭证 |
| 7 | `POST` | `/oauth/revoke` | 令牌撤销 | 客户端凭证 |
| 8 | `GET` | `/oauth/logout` | RP 发起的登出 | 无（可选 `id_token_hint`） |

---

## 1. OIDC 发现

返回 OpenID Connect 提供者配置文档，允许客户端动态发现所有端点 URL 和支持的功能。

- **方法：** `GET`
- **路径：** `/.well-known/openid-configuration`
- **认证：** 无
- **中间件：** 可通过 `oidc-server.routes.discovery_middleware` 配置
- **缓存：** `Cache-Control: public, max-age=3600`
- **规范：** [OpenID Connect Discovery 1.0](https://openid.net/specs/openid-connect-discovery-1_0.html)

### 请求

```http
GET /.well-known/openid-configuration HTTP/1.1
Host: your-app.example.com
```

### 响应

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

## 2. JSON Web 密钥集（JWKS）

以 JWK 格式公开服务器的公共 RSA 密钥，以便客户端和资源服务器可以验证 ID 令牌和访问令牌的签名。

- **方法：** `GET`
- **路径：** `/.well-known/jwks.json`
- **认证：** 无
- **中间件：** 可通过 `oidc-server.routes.discovery_middleware` 配置
- **缓存：** `Cache-Control: public, max-age=86400`
- **规范：** [RFC 7517 -- JSON Web Key](https://datatracker.ietf.org/doc/html/rfc7517)

### 请求

```http
GET /.well-known/jwks.json HTTP/1.1
Host: your-app.example.com
```

### 响应

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

> `kid`（密钥 ID）派生自 `storage/oauth-public.key` 公钥文件的 SHA-256 哈希值。

---

## 3. 授权端点

启动 OAuth 2.0 授权码流程。用户会看到一个同意屏幕，批准后，会被重定向回客户端并携带授权码。

- **方法：** `GET`
- **路径：** `/oauth/authorize`
- **认证：** 用户会话（用户必须已登录）
- **中间件：** `web`（基于会话）
- **规范：** [RFC 6749 Section 4.1](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1)，[OpenID Connect Core 1.0 Section 3.1.2](https://openid.net/specs/openid-connect-core-1_0.html#AuthorizationEndpoint)

此端点由 Laravel Passport 内置的 `AuthorizationController` 提供。该包还注册了 `POST /oauth/authorize`（批准）和 `DELETE /oauth/authorize`（拒绝）用于处理同意表单提交。

### 请求

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

| 参数 | 必需 | 描述 |
|-----------|----------|-------------|
| `response_type` | 是 | 授权码流程必须为 `code` |
| `client_id` | 是 | OAuth 客户端标识符 |
| `redirect_uri` | 是 | 必须匹配客户端注册的重定向 URI |
| `scope` | 推荐 | 空格分隔的作用域列表（例如 `openid profile email`） |
| `state` | 推荐 | 用于 CSRF 保护的不透明值，原样返回 |
| `code_challenge` | 推荐 | PKCE 代码挑战（使用 PKCE 时必需） |
| `code_challenge_method` | 推荐 | `S256`（推荐）或 `plain` |
| `nonce` | 可选 | 传递到 ID 令牌的值，用于重放保护 |

### 响应（重定向）

```http
HTTP/1.1 302 Found
Location: https://client.example.com/callback?code=def50200abc...&state=random-csrf-string
```

---

## 4. 令牌端点

将授权码（或刷新令牌）交换为访问令牌。当存在 `openid` 作用域时，响应中会自动包含 `id_token`（签名的 JWT）。

- **方法：** `POST`
- **路径：** `/oauth/token`
- **认证：** 客户端凭证（`client_secret_basic` 或 `client_secret_post`）
- **中间件：** 可通过 `oidc-server.routes.token_middleware` 配置
- **规范：** [RFC 6749 Section 4.1.3](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1.3)，[OpenID Connect Core 1.0 Section 3.1.3](https://openid.net/specs/openid-connect-core-1_0.html#TokenEndpoint)

此端点由 Laravel Passport 内置的 `AccessTokenController` 提供。该包注入了一个自定义的 `TokenResponseType`，当授予 `openid` 作用域时，会附加 `id_token` 字段。

### 请求（授权码授予）

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

### 请求（刷新令牌授予）

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

### 响应

```json
{
  "token_type": "Bearer",
  "expires_in": 900,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...",
  "refresh_token": "def50200abc123...",
  "id_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9..."
}
```

> 只有在原始授权请求中包含 `openid` 作用域时，才会出现 `id_token` 字段。ID 令牌是一个签名的 JWT，包含诸如 `sub`、`iss`、`aud`、`exp`、`iat`、`auth_time` 和 `nonce` 等声明，以及从授予的作用域解析的任何其他声明（例如 `name`、`email`）。

---

## 5. 用户信息端点

根据授予访问令牌的作用域返回有关已认证用户的声明。

- **方法：** `GET` 或 `POST`
- **路径：** `/oauth/userinfo`
- **认证：** Bearer 令牌（`Authorization: Bearer <access_token>`）
- **中间件：** 可通过 `oidc-server.routes.userinfo_middleware` 配置（默认：`auth:api`）
- **规范：** [OpenID Connect Core 1.0 Section 5.3](https://openid.net/specs/openid-connect-core-1_0.html#UserInfo)

### 请求

```http
GET /oauth/userinfo HTTP/1.1
Host: your-app.example.com
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
```

### 响应（使用 `openid profile email` 作用域）

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

### 错误响应（无效或过期的令牌）

```json
HTTP/1.1 401 Unauthorized

{
  "error": "invalid_token",
  "error_description": "The access token is invalid or expired."
}
```

> 返回的声明取决于与访问令牌关联的作用域。如果用户模型未实现 `OidcUserInterface`，则仅返回 `sub` 声明（设置为用户的主键）。

---

## 6. 令牌内省

允许客户端确定访问令牌或刷新令牌当前是否处于活动状态，并检索有关它的元数据。

- **方法：** `POST`
- **路径：** `/oauth/introspect`
- **认证：** 客户端凭证（`client_secret_basic` 或 `client_secret_post`）
- **中间件：** 可通过 `oidc-server.routes.token_middleware` 配置
- **规范：** [RFC 7662 -- OAuth 2.0 Token Introspection](https://datatracker.ietf.org/doc/html/rfc7662)

### 请求

```http
POST /oauth/introspect HTTP/1.1
Host: your-app.example.com
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
&token_type_hint=access_token
```

| 参数 | 必需 | 描述 |
|-----------|----------|-------------|
| `token` | 是 | 要内省的令牌字符串 |
| `token_type_hint` | 可选 | `access_token`（默认）或 `refresh_token` |

### 响应（活动的访问令牌）

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

### 响应（活动的刷新令牌）

```json
{
  "active": true,
  "token_type": "refresh_token",
  "exp": 1700604800,
  "client_id": "your-client-id"
}
```

### 响应（非活动或未知的令牌）

```json
{
  "active": false
}
```

### 错误响应（客户端认证失败）

```json
HTTP/1.1 401 Unauthorized

{
  "error": "invalid_client",
  "error_description": "Client authentication failed."
}
```

---

## 7. 令牌撤销

撤销访问令牌或刷新令牌。当访问令牌被撤销时，所有关联的刷新令牌也会被撤销，反之亦然。

- **方法：** `POST`
- **路径：** `/oauth/revoke`
- **认证：** 客户端凭证（`client_secret_basic` 或 `client_secret_post`）
- **中间件：** 可通过 `oidc-server.routes.token_middleware` 配置
- **规范：** [RFC 7009 -- OAuth 2.0 Token Revocation](https://datatracker.ietf.org/doc/html/rfc7009)

### 请求

```http
POST /oauth/revoke HTTP/1.1
Host: your-app.example.com
Authorization: Basic base64(client_id:client_secret)
Content-Type: application/x-www-form-urlencoded

token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
&token_type_hint=access_token
```

| 参数 | 必需 | 描述 |
|-----------|----------|-------------|
| `token` | 是 | 要撤销的令牌字符串 |
| `token_type_hint` | 可选 | `access_token`（默认）或 `refresh_token` |

### 响应（成功）

```http
HTTP/1.1 200 OK
Content-Type: application/json

{}
```

> 根据 RFC 7009，无论令牌是否被找到或已被撤销，服务器都会返回 `200 OK`。唯一的错误情况是客户端认证失败（401）。

### 错误响应（客户端认证失败）

```json
HTTP/1.1 401 Unauthorized

{
  "error": "invalid_client",
  "error_description": "Client authentication failed."
}
```

---

## 8. RP 发起的登出

允许依赖方（客户端应用程序）将用户从 OpenID 提供者登出。服务器使用户的会话失效，并可选择重定向到登出后的 URI。

- **方法：** `GET`
- **路径：** `/oauth/logout`
- **认证：** 不需要（可选的 `id_token_hint` 用于客户端识别）
- **中间件：** 可通过 `oidc-server.routes.token_middleware` 配置
- **规范：** [OpenID Connect RP-Initiated Logout 1.0](https://openid.net/specs/openid-connect-rpinitiated-1_0.html)

### 请求

```http
GET /oauth/logout?id_token_hint=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...
    &post_logout_redirect_uri=https://client.example.com/logged-out
    &state=random-state-value HTTP/1.1
Host: your-app.example.com
```

| 参数 | 必需 | 描述 |
|-----------|----------|-------------|
| `id_token_hint` | 可选 | 先前颁发给客户端的 ID 令牌；用于识别客户端以进行重定向 URI 验证 |
| `post_logout_redirect_uri` | 可选 | 登出后将用户重定向到的 URL |
| `state` | 可选 | 传递到重定向 URI 的不透明值 |

### 响应（有效的登出后重定向）

```http
HTTP/1.1 302 Found
Location: https://client.example.com/logged-out?state=random-state-value
```

### 响应（无重定向或无效重定向）

```http
HTTP/1.1 302 Found
Location: /
```

> `post_logout_redirect_uri` 会根据客户端注册的重定向 URI（当提供 `id_token_hint` 时）或应用程序 URL 进行验证。如果验证失败，用户将被重定向到应用程序根目录（`/`）。

---

## 客户端认证方法

需要客户端认证的端点（令牌、内省、撤销）支持两种方法：

### HTTP 基本认证（`client_secret_basic`）

```http
Authorization: Basic base64(client_id:client_secret)
```

### POST 正文（`client_secret_post`）

```http
Content-Type: application/x-www-form-urlencoded

client_id=your-client-id&client_secret=your-client-secret
```

---

## OIDC 授权码流程生命周期

以下图表说明了通过此包提供的端点完成 OpenID Connect 授权码流程的完整生命周期：

```
User-Agent              Client App              OIDC Server
    |                       |                       |
    |  1. 点击"登录"         |                       |
    |---------------------->|                       |
    |                       |                       |
    |  2. 重定向到 /oauth/authorize                  |
    |<----------------------------------------------|
    |                       |                       |
    |  3. 用户认证并同意                             |
    |---------------------------------------------->|
    |                       |                       |
    |  4. 使用授权码重定向                           |
    |<----------------------------------------------|
    |                       |                       |
    |  5. 跟随重定向         |                       |
    |---------------------->|                       |
    |                       |                       |
    |                       |  6. POST /oauth/token |
    |                       |  (交换授权码)          |
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
    |                       |  9. 用户声明           |
    |                       |<----------------------|
    |                       |                       |
    |  10. 用户已登录        |                       |
    |<----------------------|                       |
    |                       |                       |
    :   ... 时间流逝 ...     :                       :
    |                       |                       |
    |  11. 点击"登出"        |                       |
    |---------------------->|                       |
    |                       |                       |
    |                       | 12. POST /oauth/revoke|
    |                       |     (撤销令牌)         |
    |                       |---------------------->|
    |                       |<----------------------|
    |                       |                       |
    |  13. 重定向到 /oauth/logout                    |
    |<----------------------------------------------|
    |                       |                       |
    |  14. 会话销毁，重定向回来                       |
    |---------------------------------------------->|
    |<----------------------------------------------|
    |                       |                       |
```

**整个生命周期中使用的支持端点：**

- `GET /.well-known/openid-configuration` -- 客户端在启动时发现所有端点 URL。
- `GET /.well-known/jwks.json` -- 客户端或资源服务器获取公钥以验证令牌签名。
- `POST /oauth/introspect` -- 资源服务器在服务器端验证令牌（本地 JWT 验证的替代方案）。

---

## 参考资料

| 规范 | URL |
|---------------|-----|
| OpenID Connect Core 1.0 | https://openid.net/specs/openid-connect-core-1_0.html |
| OpenID Connect Discovery 1.0 | https://openid.net/specs/openid-connect-discovery-1_0.html |
| OpenID Connect RP-Initiated Logout 1.0 | https://openid.net/specs/openid-connect-rpinitiated-1_0.html |
| RFC 6749 -- OAuth 2.0 Authorization Framework | https://datatracker.ietf.org/doc/html/rfc6749 |
| RFC 7009 -- OAuth 2.0 Token Revocation | https://datatracker.ietf.org/doc/html/rfc7009 |
| RFC 7517 -- JSON Web Key (JWK) | https://datatracker.ietf.org/doc/html/rfc7517 |
| RFC 7636 -- PKCE | https://datatracker.ietf.org/doc/html/rfc7636 |
| RFC 7662 -- OAuth 2.0 Token Introspection | https://datatracker.ietf.org/doc/html/rfc7662 |
