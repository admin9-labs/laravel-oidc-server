# 故障排除

本指南涵盖了使用 OIDC 服务器时的常见错误及其解决方案。

## 缺少 Passport 密钥

**错误**: `LogicException: Unable to read key from file` 或 `file_get_contents(storage/oauth-public.key): failed to open stream`

**原因**: 未生成 Passport RSA 密钥。

**解决方案**:
```bash
php artisan passport:keys
```

这将创建 `storage/oauth-private.key` 和 `storage/oauth-public.key`。确保 Web 服务器可以读取它们，并将其排除在版本控制之外。

## 发现端点返回 404

**原因**: 包路由未注册。

**检查**:
1. 验证服务提供者已自动发现: `php artisan package:discover`
2. 检查 `config/oidc-server.php` 中是否有 `'routes' => ['enabled' => true]`
3. 清除路由缓存: `php artisan route:clear`

## 令牌响应中未返回 id_token

**原因**: 未请求 `openid` 作用域。

**解决方案**: 确保客户端在 `scope` 参数中包含 `openid`:
```
GET /oauth/authorize?scope=openid+profile+email&...
```

同时验证 User 模型实现了 `OidcUserInterface`:
```php
class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims;
}
```

## UserInfo 返回空声明

**原因**: 未能为请求的作用域解析声明。

**检查**:
1. 验证 `config/oidc-server.php` 的 `scopes` 部分将作用域映射到声明
2. 验证 `default_claims_map` 将声明名称映射到模型属性
3. 如果使用自定义声明，检查 `claims_resolver` 配置或 `resolveOidcClaim()` 重写

## Introspect/Revoke 返回 401

**原因**: 客户端认证失败。

**检查**:
1. 验证 `client_id` 和 `client_secret` 是否正确
2. 尝试两种认证方法:
   - Basic Auth: `Authorization: Basic base64(client_id:client_secret)`
   - POST body: `client_id=...&client_secret=...`
3. 确保客户端不是公共客户端（公共客户端无法进行认证）

## 登出后未重定向回来

**原因**: `post_logout_redirect_uri` 验证失败。

**检查**:
1. URI 必须匹配客户端已注册的重定向 URI（协议 + 主机 + 端口）
2. 提供 `id_token_hint`，以便服务器可以识别客户端并根据其注册的重定向 URI 进行验证
3. 如果没有 `id_token_hint`，则只允许匹配 `config('app.url')` 的 URI

## Passport 路由冲突

**原因**: Passport 和此包都注册了路由。

**解决方案**: 该包默认调用 `Passport::ignoreRoutes()`。如果您需要 Passport 自己的路由:

```php
// config/oidc-server.php
'ignore_passport_routes' => false,
```

## 令牌 TTL 未生效

**原因**: 配置缓存已过期。

**解决方案**:
```bash
php artisan config:clear
```

或通过环境变量设置:
```env
OIDC_ACCESS_TOKEN_TTL=900
OIDC_REFRESH_TOKEN_TTL=604800
OIDC_ID_TOKEN_TTL=900
```
