# 声明解析

本文档说明如何通过模型重写和 trait 的回退规则解析作用域中的用户声明。

## 作用域 → 声明映射

声明在 `config/oidc-server.php` 中按作用域分组:

| 作用域 | 声明 |
|-------|--------|
| `openid` | `sub` |
| `profile` | `name`, `nickname`, `picture`, `updated_at` |
| `email` | `email`, `email_verified` |

当使用 `scope=openid profile email` 颁发用户令牌时，UserInfo 端点和 id_token 可包含这些作用域的声明。解析器返回 `null` 的声明会被省略。

## 解析顺序

若 User 模型重写了 `resolveOidcClaim()`，该方法先处理声明；未处理的声明需回退到保留的 trait 方法。没有模型重写时，直接从 trait 开始解析。

### 模型重写 (`resolveOidcClaim()`)

为 trait 方法保留别名，再重写指定声明:

```php
use Admin9\OidcServer\Contracts\OidcUserInterface;
use Admin9\OidcServer\Concerns\HasOidcClaims;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims {
        resolveOidcClaim as protected resolveDefaultOidcClaim;
    }

    protected function resolveOidcClaim(string $claim): mixed
    {
        return match ($claim) {
            'nickname' => $this->display_name ?? $this->name,
            'picture' => $this->avatar_url,
            default => $this->resolveDefaultOidcClaim($claim),
        };
    }
}
```

Laravel 的基础 `Authenticatable` 模型没有 `resolveOidcClaim()` 方法，不能通过 `parent::resolveOidcClaim()` 回退。上述示例先处理 `nickname` 和 `picture`，其余声明才通过 trait 别名读取配置。

### Trait 回退: 配置解析器 (`oidc-server.claims_resolver`)

Trait 首先检查配置解析器，将声明名称映射到模型属性或可调用对象:

```php
// config/oidc-server.php
'claims_resolver' => [
    'nickname' => 'public_name',                    // 字符串 → 模型属性
    'picture' => 'avatar_url',
],
```

使用 `php artisan config:cache` 时，应采用属性字符串或静态 callable 数组；配置闭包无法序列化。配置解析器返回 `null` 时会省略该声明，不会继续读取其他映射。

### Trait 回退: 默认声明映射 (`oidc-server.default_claims_map`)

没有配置解析器时，trait 通过 `getOidcSubject()` 解析 `sub`，其余声明再读取默认映射:

```php
// config/oidc-server.php
'default_claims_map' => [
    'name'           => 'name',                                    // 模型属性
    'email'          => 'email',                                   // 模型属性
    'email_verified' => [\Admin9\OidcServer\Services\DefaultClaims::class, 'emailVerified'],
    'updated_at'     => [\Admin9\OidcServer\Services\DefaultClaims::class, 'updatedAt'],
],
```

如果没有映射解析该声明，则返回 `null` 并省略该声明。

## 解析流程

```
resolveOidcClaim($claim)
  │
  ├─ 模型重写（若已定义）
  │     → 已处理? 返回声明值
  │     → 否则调用 resolveDefaultOidcClaim($claim)
  │
  ├─ 1. 检查 config('oidc-server.claims_resolver')[$claim]
  │     → 找到? 返回值 (字符串=属性, 可调用对象=调用)
  │
  ├─ 2. 检查声明是否为 'sub'
  │     → 是? 返回 getOidcSubject() (默认: 模型主键)
  │
  ├─ 3. 检查 config('oidc-server.default_claims_map')[$claim]
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
