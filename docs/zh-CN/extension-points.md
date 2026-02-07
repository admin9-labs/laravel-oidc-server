# 扩展点

本文档描述如何自定义和扩展 OIDC 服务器的行为以适应您的应用程序需求。

## 接口

### OidcUserInterface

您的 User 模型必须实现此接口：

```php
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;

class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims;
}
```

该接口要求：
- `getOidcSubject(): string` — 返回 OIDC 主体标识符（默认：主键）
- `getOidcClaims(array $scopes): array` — 返回给定作用域的声明

这两个方法都由 `HasOidcClaims` trait 提供，并具有合理的默认值。

## 自定义点

### 自定义声明解析

有三种方式可以自定义声明的解析方式（详见 [Claims Resolution](claims-resolution.md)）：

1. **基于配置** — `oidc-server.claims_resolver` 将声明名称映射到属性/可调用对象
2. **模型覆盖** — 在您的 User 模型中覆盖 `resolveOidcClaim()` 方法
3. **默认映射** — `oidc-server.default_claims_map` 用于回退解析

### 自定义授权视图

发布并自定义授权同意屏幕：

```bash
php artisan vendor:publish --tag=oidc-server-views
```

或指向您自己的视图：

```php
// config/oidc-server.php
'authorization_view' => 'auth.oauth.authorize',
```

该视图接收 `$client`、`$scopes`、`$request` 和 `$authToken` 变量。

### 自定义客户端模型

替换默认的 `OidcClient` 模型以自定义授权行为：

```php
// config/oidc-server.php
'client_model' => \App\Models\MyOAuthClient::class,
```

您的模型应该继承 `Laravel\Passport\Client`。

### Passport 配置控制

禁用自动配置以自行管理 Passport：

```php
// config/oidc-server.php
'configure_passport' => false,
'ignore_passport_routes' => false,
```

### 路由中间件

为每个端点组自定义中间件：

```php
// config/oidc-server.php
'routes' => [
    'enabled' => true,
    'discovery_middleware' => ['throttle:60,1'],
    'token_middleware' => ['throttle:10,1'],
    'userinfo_middleware' => ['auth:api'],
],
```

### 自定义作用域

添加自定义作用域及其关联的声明：

```php
// config/oidc-server.php
'scopes' => [
    'openid'  => ['description' => 'OpenID Connect', 'claims' => ['sub']],
    'profile' => ['description' => 'Profile info', 'claims' => ['name', 'picture']],
    'phone'   => ['description' => 'Phone number', 'claims' => ['phone_number']],
],
```

### 令牌生命周期

```php
// config/oidc-server.php
'tokens' => [
    'access_token_ttl'  => (int) env('OIDC_ACCESS_TOKEN_TTL', 900),    // 15 分钟
    'refresh_token_ttl' => (int) env('OIDC_REFRESH_TOKEN_TTL', 604800), // 7 天
    'id_token_ttl'      => (int) env('OIDC_ID_TOKEN_TTL', 900),        // 15 分钟
],
```

## 禁用路由

如果要自己注册路由而不是使用包的路由：

```php
// config/oidc-server.php
'routes' => [
    'enabled' => false,
],
```

然后在您的应用程序路由文件中手动注册路由。
