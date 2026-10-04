# 升级到 2.0.0

[English](../upgrading-to-2.0.0.md) | [简体中文](upgrading-to-2.0.0.md)

**这是 2.0 扩展包接入指引。这不代表已经发布或部署。**

2.0 对本包的每次授权码事务都要求宿主显式记录真实认证，包括没有 `openid` 的 OAuth 请求。Laravel Login 事件、remember cookie 恢复、`setUser()`、session 创建和用户同意均不能产生可信认证时间。旧 session 没有记录时必须重新认证；旧 code/refresh 返回 `invalid_grant`，不转换，也不提供兼容刷新分支。

## 存储与路由

所有节点使用共享的服务端 session。包授权入口拒绝 cookie/array session。宿主登录、重新认证和退出路由必须启用 Laravel session blocking；包内 authorize、approve、deny、continue 和 logout 已调用 `block()`。session blocking 使用的 cache store 必须支持共享原子锁。宿主 POST 入口保留 CSRF 和认证限流。

```php
// config/oidc-server.php
'freshness' => ['redis_connection' => 'default'],
```

该连接由所有 web/token worker 共享；已验证的客户端是 `ext-redis`。连接必须指向一个权威可写 Redis server，并允许 `INFO server` 和 Lua（包括脚本内 INFO）。状态键绑定 Redis 的 `run_id`，每次原子脚本内再次核对。Redis 重启或提升另一进程后，即使旧快照恢复了“未消费”值，原 session/code/refresh 状态也全部失效。无法确认 server 身份，或查询到执行期间身份改变时，停止签发。Redis Cluster 及其他客户端/拓扑仍需各自验收，不能绕过身份检查。禁止向同一个仍在运行的 Redis 进程加载旧快照或人工覆盖状态；配置持久化和可用性以减少重新认证，但不能用它们替代原子消费。

session 保存标量认证记录和事务，Redis 保存随机 session 版本及 code/refresh 的正向一次性状态。每次 session 状态修改都原子比较并推进版本；旧 session ID 和延迟保存无法推进新版本。令牌在数据库副作用前原子消费原始 envelope 指纹。记录缺失或过期一律拒绝，不当作未使用。session 版本丢失后重新开始授权并真实认证；令牌状态丢失后 RP 重新授权。消费后进程退出可能使本次凭证不可重试，但不能签发第二次结果。签名、数据库、进程或传输的后续失败不承诺自动回滚。

事务有效期 10 分钟，挑战有效期最多 5 分钟且不超过事务期限，每个 session 最多 10 个待完成事务。新挑战不延长事务寿命。这些是固定实现限制，不增加配置开关。本包无需新增数据库迁移。

## 记录真实认证

宿主完成选定认证方式和**所有必需因子**后，建立指定 guard、保留其他 session 数据并轮换 ID，再记录认证：

```php
use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Illuminate\Support\Facades\Auth;

// $user 已完成宿主规定的全部认证步骤。
Auth::guard('member')->login($user);
$request->session()->regenerate(true);
app(AuthenticationRecorder::class)->markAuthenticated('member', $user);
```

不传时间时使用调用时刻。上游 SSO 必须传入经过验证的真实认证时间 `DateTimeImmutable`，不能改用回调接收时间。上游时间未知时不能传 null，因为 null 会选择本地调用时刻。remember 恢复、静默 SSO、未完成 MFA 或框架 Login 监听器均不能调用 recorder。已有有效记录正常复用时，原时间与代次不变。

`current($guard)` 只返回与当前 guard 用户匹配的记录。`forget($guard)` 清除该 guard 的记录及待完成事务。包监听 Laravel Logout 事件；自定义退出若不触发该事件，应在仍有 session 的请求中调用 `forget()`。其他 guard 的认证记录和应用登录状态保留。

## 重新认证入口

在宿主 provider 绑定 `ReauthenticationHandler`：

```php
use Admin9\OidcServer\Authentication\ReauthenticationChallenge;
use Admin9\OidcServer\Contracts\ReauthenticationHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class HostReauthenticationHandler implements ReauthenticationHandler
{
    public function redirect(Request $request, ReauthenticationChallenge $challenge): RedirectResponse
    {
        return redirect()->route('member.reauthenticate', [
            'transaction' => $challenge->transactionId,
            'challenge' => $challenge->token,
        ]);
    }
}

// AppServiceProvider::register()
$this->app->bind(ReauthenticationHandler::class, HostReauthenticationHandler::class);
```

挑战对象只是不可变定位值；包每次都从服务端 session 重新校验。宿主表单仅传不透明的 `transaction`、`challenge`、CSRF 和认证输入，不接受最终 RP 回调、scope、nonce、state 或 PKCE。页面设置 `Cache-Control: no-store`、`Referrer-Policy: no-referrer`，不要将凭证写入日志或分析服务。

宿主 GET/POST 路由放在 `web` 中，调用 `block()`，POST 配置认证限流。入口同时供游客和已登录用户访问。未绑定 handler 是接入错误，不会退回 Passport 原生登录流程。

完成顺序：

```php
use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Admin9\OidcServer\Contracts\ReauthenticationService;
use Illuminate\Support\Facades\Auth;

$service = app(ReauthenticationService::class);
$challenge = $service->challenge(
    $request, $request->string('transaction')->toString(), $request->string('challenge')->toString(),
);

// 宿主已有的认证实现：验证密码/SSO 和全部必需因子。
// 结果包含经过验证的用户与实际认证时间。
$result = $hostAuthenticator->authenticateForChallenge($request, $challenge);
if (! $result->authenticatedAt instanceof \DateTimeImmutable) {
    throw new \LogicException('宿主认证结果必须包含经过验证的实际认证时间。');
}

Auth::guard($challenge->guard)->login($result->user);
$request->session()->regenerate(true);
app(AuthenticationRecorder::class)->markAuthenticated(
    guard: $challenge->guard,
    user: $result->user,
    authenticatedAt: $result->authenticatedAt,
    challenge: $challenge,
);

return $service->complete($request, $challenge);
```

`$hostAuthenticator` 是宿主自己的服务，不是包 API。已有用户的事务不能换人；游客事务首次成功后一次性绑定身份。认证时间必须不早于挑战创建、不晚于记录和完成时刻；同秒相等仍需要新随机代次和本挑战证明。旧的已签名 SSO 事件加新代次不足以完成挑战。修复节点时钟，不要调整认证时间来通过检查。

`complete()` 只验证、消费证明并返回包控制的一次性继续地址，不能代替认证或补写时间。取消时，在受 CSRF 和 blocking 保护的 POST 中取回挑战，再返回 `$service->cancel($request, $challenge)`。完成与取消都会重新校验客户端注册信息。

## 同意页面和认证新鲜度

重新发布自定义 consent 视图。变量包括 `client`、`user`、`scopes`、`transactionId`、`authToken`；批准和拒绝表单都提交 `transaction`、`auth_token` 和 CSRF，拒绝使用 DELETE。旧的 session `authRequest`、`authToken` 对象及旧表单不接受。表单不转发授权参数。

每个标签页独立保存事务。新认证代次可能使其他页的 consent 失效。正数 `max_age` 在恢复、同意和紧邻 code 保存前检查；过期会创建新挑战并作废旧批准/恢复凭证。已完成的 `prompt=login` 或 `max_age=0` 不会单纯因为原参数仍在而重复挑战。`prompt=login consent` 仍需同意；trusted client 只能跳过同意，不能跳过认证和最终检查。

`prompt=none` 不进入宿主交互：认证不足返回 `login_required`，需要同意返回 `consent_required`。`none` 不能与其他 prompt 混用。本版接受 `none`、`login`、`consent`，拒绝其他或重复值。`max_age` 接受可表达的非负十进制整数及前导零，拒绝负数、空值、小数、指数、重复和溢出。state/nonce 保留原值。

ID Token 的 `auth_time` claim 请求接受 `null`、`{}` 和 `{"essential":true|false}`。`value`、`values` 及其他限定条件被拒绝，不伪造或修改真实时间。Essential `userinfo.auth_time` 不支持；UserInfo 不从自定义 claim resolver 输出认证时间。ID Token 始终包含经过验证的认证时间，即使请求没有显式要求。

其他声明继续按授权作用域与现有 resolver 解析。Discovery 在 `claims_supported` 中公布 `auth_time`，未公布通用个人声明选择能力 `claims_parameter_supported`；通用 `sub`/`acr` 值限定不在本次认证新鲜度实现范围内。相关区别见 [OIDC Core 个人声明请求](https://openid.net/specs/openid-connect-core-1_0.html#IndividualClaimsRequests)。

客户端撤销、回调删除、原用户删除或 guard/provider/model/subject/issuer 绑定改变时，受影响的待完成流程在本地失败，不向交互期间已移除的回调发送 code 或错误。

## Token 与宿主 OAuth 边界

本包 code/refresh 必须包含严格整数 `oidc.v=3`，以及原 nonce、身份、client、issuer、subject、真实认证时间和代次。旧/未知格式、缺失或额外的上下文字段在新 token 保存和旧 refresh 撤销前拒绝。token 请求不能改写上下文。兑换不依赖浏览器 session，但会重新核对持久化的原身份。

`max_age` 不是 OP 的 token TTL。RP 客户端库可能在验证 ID Token 时自行检查认证年龄，因此延迟兑换可能被 OP 接受、却被 RP 拒绝；不能修改原认证时间来绕过 RP 检查。例如认证年龄 59 秒时按 `max_age=60` 签发 code，可在 65 秒时兑换，再在 refresh 自身期限内刷新。新 ID Token 保留原 `auth_time`，使用新 `iat`，不含 nonce；原 nonce 只可留在加密 refresh 上下文内。

| 入口 | 行为 |
| --- | --- |
| 授权码及其 refresh，含或不含 `openid` | 统一认证契约；无 `openid` 时仅返回 OAuth token。 |
| Client credentials | 无浏览器认证要求，不返回 ID Token。 |
| Passport personal access token factory | 继续由宿主管理，不新增浏览器契约或 ID Token。 |
| 本包入口的 password/device/自定义用户 grant | 签发前返回 `unsupported_grant_type`。 |
| 保留的 Passport 授权/token 路由，包括自定义前缀 | 使用同一本包受保护控制器与策略。 |

包通过 `OidcAuthorizationServer` 构造自己的 grants/response，不再设置全局 `Passport::$authorizationServerResponseType`，也不再注册全局 `afterResolving(AuthorizationServer::class)`。`configure_passport=false` 仅把 scopes/model/TTL 配置交给宿主，本包 server 的检查始终启用。手工路由使用包控制器和 `EnforceAuthorizationPolicy`，浏览器路由另加 `web` 与 `block()`，不要把包 grants/response 绑定到全局 server。

宿主独立 OAuth 入口须使用自己的控制器类（不能使用被本包接管的 Passport 控制器别名）、独立配置的 native server/grants 及显式的原生 `BearerTokenResponse`。两版 Passport 的包测试已验证独立宿主控制器、原生 server/response、code/refresh 和 personal token factory；目标宿主接线验收另见实施证据。宿主自行管理其签发/刷新策略。增加 URL 但仍指向本包 server 不构成隔离。

### 宿主独立 server 的最小接法

宿主入口使用独立的 envelope 加密密钥，防止把本包材料当成另一条刷新或兼容路径。`OAuthIsolationTest` 使用同一客户端凭证验证双向隔离：原生材料在本包入口失败，本包 code/refresh 在独立宿主入口失败，且不会消费合法本包凭证。宿主仍须配置自己的客户端/作用域策略。

[英文指引中的完整示例](../upgrading-to-2.0.0.md#minimal-independent-host-server)在宿主 provider 绑定 `host.oauth`：显式构造原生 `AuthorizationServer`、`BearerTokenResponse`、所需的 password/refresh grants，以 `host-oauth.encryption_key` 提供至少 32 字节且不同于本包的独立密钥；通过上下文绑定仅注入宿主自有 `HostOAuthTokenController`。该宿主配置项不是包新增的配置。

宿主控制器可继承 Passport token controller，但必须使用自己的类名和 URL，不能使用被包接管的 Passport 控制器类名；不全局覆盖 server，也不复用包的 envelope 密钥。示例共享 Passport 仓库与签名域；若宿主需要独立签名域/资源服务器，还须配置配套签名密钥和资源验证。具体客户端授权、限流和认证策略由宿主负责。

## 切换和回滚

1. 接入显式认证、handler、共享 session/锁及满足持久化要求的原子存储。验证真实密码、MFA、SSO 和多 guard。确认 RP 能处理 `login_required`、`consent_required`、`invalid_grant` 并重新授权。
2. 先部署宿主接入，再排空授权/token 请求，整体切换所有 worker，禁止新旧签发节点混跑。同步密钥、issuer、guard/provider、时钟、共享存储、已发布视图、路由/配置缓存和长驻进程。
3. 旧待同意页、code、refresh 重新开始。已有 Access Token/ID Token 不会因部署自动撤销，仍遵守原到期与撤销规则。拒绝 envelope 不等于撤销数据库行。
4. 优先前向修复。回滚也须排空并整体切换；1.2.2 不支持 `max_age`/Essential `auth_time`，重新授权不能恢复这些能力。恢复具备相应能力的服务前，关闭相关客户端的新授权和旧 refresh 重试入口。
5. 用一个仍未撤销的旧格式 refresh 演练回滚门禁。否则回滚可能让它重新可用。永久撤销属于独立运营操作，升级本身不执行。

本次本地实现不代表部署、数据修改、令牌撤销或发布授权。
