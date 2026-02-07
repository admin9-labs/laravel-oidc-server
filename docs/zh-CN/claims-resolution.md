# 声明解析

本文档说明如何使用三层解析机制从作用域解析用户声明。

## 作用域 → 声明映射

声明在 `config/oidc-server.php` 中按作用域分组:

| 作用域 | 声明 |
|-------|--------|
| `openid` | `sub` |
| `profile` | `name`, `nickname`, `picture`, `updated_at` |
| `email` | `email`, `email_verified` |

当使用 `scope=openid profile email` 颁发令牌时，UserInfo 端点和 id_token 将包含这些作用域的所有声明。

## 三层解析

在解析声明值时，`HasOidcClaims` trait 按顺序检查三层:

### 第一层: 配置解析器 (`oidc-server.claims_resolver`)

将声明名称映射到模型属性或可调用对象:

```php
// config/oidc-server.php
'claims_resolver' => [
    'nickname' => 'public_name',                    // 字符串 → 模型属性
    'picture'  => fn($user) => $user->avatar_url,   // 可调用对象
],
```

### 第二层: 模型重写 (`resolveOidcClaim()`)

在 User 模型中重写该方法以实现自定义逻辑:

```php
protected function resolveOidcClaim(string $claim): mixed
{
    return match ($claim) {
        'nickname' => $this->display_name ?? $this->name,
        'picture'  => $this->getAvatarUrl(),
        default    => parent::resolveOidcClaim($claim),
    };
}
```

### 第三层: 默认声明映射 (`oidc-server.default_claims_map`)

标准声明的后备映射:

```php
// config/oidc-server.php
'default_claims_map' => [
    'name'           => 'name',                                    // 模型属性
    'email'          => 'email',                                   // 模型属性
    'email_verified' => fn($user) => $user->email_verified_at !== null,
    'updated_at'     => fn($user) => $user->updated_at?->timestamp,
],
```

如果没有任何层解析该声明，则返回 `null` 并省略该声明。

## 解析流程

```
resolveOidcClaim('email')
  │
  ├─ 1. 检查 config('oidc-server.claims_resolver')['email']
  │     → 找到? 返回值 (字符串=属性, 可调用对象=调用)
  │
  ├─ 2. 检查声明是否为 'sub'
  │     → 是? 返回 getOidcSubject() (默认: 模型主键)
  │
  ├─ 3. 检查 config('oidc-server.default_claims_map')['email']
  │     → 找到? 返回值 (字符串=属性, 可调用对象=调用)
  │
  └─ 4. 返回 null (省略声明)
```

## 自定义主体标识符

在 User 模型中重写 `getOidcSubject()`:

```php
public function getOidcSubject(): string
{
    return (string) $this->uuid; // 使用 UUID 而不是自增 ID
}
```

## 添加自定义作用域和声明

```php
// config/oidc-server.php
'scopes' => [
    'openid'  => ['description' => '...', 'claims' => ['sub']],
    'profile' => ['description' => '...', 'claims' => ['name', 'nickname', 'picture', 'updated_at']],
    'email'   => ['description' => '...', 'claims' => ['email', 'email_verified']],
    'phone'   => ['description' => 'Access phone number', 'claims' => ['phone_number']],
],

'claims_resolver' => [
    'phone_number' => 'phone',
],
```
