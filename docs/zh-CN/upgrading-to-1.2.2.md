# v1.2.2 安全修复升级说明

本版保留原 Advisory 前 8 项安全修复及 refresh-token 内省修复，同时修正 nonce 绑定、停止输出虚假的认证时间。完整认证时间追踪与 max_age 执行，以及独立的 ID Token TTL 配置，继续延期。

本次不修改数据库结构。部署前检查宿主覆盖的路由、控制器、客户端模型、中间件与已发布模板。

默认在 provider boot 前关闭 Passport 自带路由。若设置 ignore_passport_routes=false 保留独立前缀，其授权、确认和兑换路由也应用相同安全策略。中间件校验独立于 configure_passport，但授权上下文的 grant/response 注册需要自动配置或下述等效手动接入。宿主另行编写的 OAuth 控制器需单独核查。

## 令牌接口

- introspection 要求有效的机密客户端凭证，默认仅查询自身令牌。
- 独立资源服务器可通过 `introspection_allowed_clients` 显式授权：查询方客户端 ID => 允许查询的令牌所属客户端 ID 数组，ID 使用字符串。此配置不允许跨客户端撤销。
- 公共客户端可以凭实际令牌撤销自己的令牌；仅有 client_id 不能调用 introspection。
- 不再接受裸数据库 ID 或未验签 JWT；refresh token 必须通过 Passport 认证解密，并匹配客户端、访问令牌和刷新令牌关联。
- 缺失、错误或未知 token_type_hint 不妨碍查找其他令牌类型。未授予 email scope 时不返回 username。
- 禁止混用 Basic 与请求体客户端凭证。兼容 Passport 12 明文/可选哈希 secret 和 Passport 13 哈希 secret；Passport 13 使用配置的 Laravel Hasher，包括自定义驱动。
- 签发、验签及 JWKS 遵循 Passport 配置密钥和自定义密钥目录；验签不依赖私钥，各节点应保持密钥一致。

## 退出流程

GET、POST `/oauth/logout` 是无需 OP CSRF token 的协议入口。只有签名有效、未过期、issuer 正确、audience 对应单个有效客户端且 subject 匹配当前 guard 用户 OIDC 身份的 ID Token 才能直接退出。Access Token 不能作为 hint，显式 client_id 必须匹配。

其他请求显示确认页；过期但其余信息有效的 hint 可用于识别 RP，仍需确认。本次绑定当前 guard/模型/用户，没有发行 sid 或实现每个 RP 登录会话的完整跟踪。

确认页提交 POST `/oauth/logout/confirm`，保留 web CSRF 校验，并使用服务端保存、五分钟有效、一次性消费的确认值。不要把该路由加入宿主 CSRF 例外。回调与 state 保存于服务端，确认时重新核对用户身份和客户端登记。HEAD 不退出，也不覆盖待确认状态。

`post_logout_redirect_uris` 按客户端 ID 配置完整回调字符串数组，包括 query、fragment 和尾斜杠。未配置时精确复用该客户端 OAuth 回调；显式空数组禁止该客户端退出跳转。

原 `post_logout_redirect_uris_supported` 仅用于未指定客户端、经过本地确认的退出，不并入其他客户端白名单。同域名任意路径不再自动获准，无有效目标时返回 `/`。state 添加到 query、位于 fragment 之前。

依赖无签名 GET 立即退出的调用方需支持 200 确认页。其他 guard 登录仍保留。

## Consent 和历史授权

默认客户端模型不再跳过确认，包授权控制器也不再把已有令牌当作显式同意。旧记录无法可靠区分显式授权和自动授权。

无表结构迁移的安全默认是每次授权都重新确认，包括已有同意和刷新令牌的场景；prompt=none 不因旧令牌自动成功。宿主自定义客户端模型的 skipsAuthorization() 属于显式运维信任扩展，应单独核查。

部署前已打开的授权页必须重新开始。提交授权要求当前非空 auth_token、经过新策略校验的授权页及同一 guard/模型/用户。包端点限制 code/S256，旧配置不能恢复 implicit/plain，旧 plain PKCE code 也不能兑换。

安装补丁不会自动撤销令牌，也不能收回已披露数据。历史授权处置：

1. 盘点受影响 RP 客户端 ID，区分运维明确信任的集成；旧记录不能自动准确区分显式同意。
2. 暂停选定客户端的签发和刷新，排空旧 worker，再记录 UTC 截止时间。先审核这些客户端截止时间之前的访问令牌及全部关联刷新令牌。在允许刷新时仅按 created_at 截断会被新刷新令牌跨过。
3. 在 Passport 数据库连接中，通过配置的模型/仓库，以事务撤销已审核访问令牌及**全部关联刷新令牌**。大批量可分批处理，但持续阻止签发；不要删除客户端或清理无关会话。
4. 验证自省和真实刷新拒绝抽查令牌，再恢复客户端并要求重新授权。

该生产数据操作需部署阶段单独授权，应保留审核目标/数量并告知重新授权影响。回滚代码不能恢复已撤销令牌。

## Nonce 与认证新鲜度

原始授权请求的 nonce 原样保留，包括 `"0"`、空白和空字符串，与客户端及用户一起写入 Passport 认证加密的授权码。批准和兑换请求不能替换它。旧客户端若只在 /oauth/token 传 nonce，必须改为在 /oauth/authorize 传入。刷新 ID Token 不包含 nonce；自定义 claims 不能覆盖协议字段。

所有 ID Token 均省略 auth_time，包括真实凭证登录、remember-cookie 恢复、刷新和直接调用 IdTokenService 的场景。包不监听 Login/Logout、不保存登录代次或认证年龄，不再将签发时间冒充认证时间。Discovery 排除 auth_time，旧发布配置将它加入 scope claims 也不会恢复该声明。

授权请求只要包含 max_age，无论其值为何，均返回 invalid_request，不注销、不启动登录。claims 在 id_token 或 userinfo 中请求 Essential auth_time 时同样拒绝。畸形 JSON/声明结构、重复协议参数及归一化名称别名也会被拒绝。先由 Passport 验证客户端及回调，再向已验证回调返回错误、描述和原始 state；无效客户端/回调沿用上游错误，不跳向不可信地址。其他合法 claims 请求保持原有未实现行为，不新增完整 Claims 参数支持。

OIDC Core 要求完整 OP 支持认证时间与最大认证年龄。本版有明确能力限制，不宣称完全符合 OIDC。需要新鲜度的 RP 必须处理拒绝结果并采用独立验证的认证流程；不能通过删除敏感请求的 max_age 来满足原要求。已签发 ID Token 无法追溯修正。

普通未登录请求、prompt=none、prompt=login 交由当前 Passport 版本处理，包不剥离 login prompt，也不增加重新认证状态机。Passport 原生 prompt=login 可能清空整个共享 session，影响其他 guard；这是既有上游行为，只有本包 logout 端点提供 guard 隔离保证。保留 Passport 12/13 各自的原生 prompt 和错误行为。

Passport 每个 session 只保留一个待确认授权页。第二页覆盖第一页，旧提交被拒绝，不能混入另一事务的 nonce。已签发授权码彼此独立，可乱序兑换且无需浏览器会话。继续复用 grant/response 加密扩展点及 Passport 客户端、回调、PKCE、有效期和一次性校验，不新增持久化或表结构。

内部 oidc 信封采用版本 2，仅携带 nonce 与绑定的客户端、issuer、subject、guard/模型/用户身份，不携带认证时间或年龄。未发布候选中的版本 1 不做迁移；旧提交仅作为后续开发参考，不作为兼容部署版本。

| 升级前材料 | 兼容策略 |
| --- | --- |
| 待确认授权页，包括候选 v1 上下文 | 重新开始授权。 |
| 无 v2 上下文的 OIDC code | invalid_grant，重新授权，不重试旧码。 |
| 无 OIDC 上下文的纯 OAuth code | 继续遵循 Passport 校验及 S256 策略。 |
| Access Token / 已签发 ID Token | 原有效期和撤销状态不变；旧 auth_time 不能证明新鲜认证。 |
| 无上下文的旧 Refresh Token | 继续轮换 OAuth access/refresh token，不返回 id_token；需要 ID Token 时重新走授权码流程。 |
| code/refresh 携带候选 v1、未知版本或损坏上下文 | invalid_grant，不降级到无上下文的旧令牌分支。 |
| 新 Refresh Token | 轮换保留客户端和身份绑定；issuer、guard、模型或 subject 改变后重新授权。ID Token 省略 nonce/auth_time。 |

安全升级期间保持现有 issuer 和用户 provider 映射。检查显式 `user_model` 是否与 `passport.guard` 对应 provider（未配置时为默认 guard 的 provider）返回同一模型类；不同运行时类即使代表同一用户，在兑换带身份上下文的 OIDC code/refresh token 时也会返回 `invalid_grant`。旧令牌没有 provider 历史；若另行变更映射，应先撤销相关旧 access/refresh token，不能把旧 user ID 解释为另一套 provider 的用户。恢复签发前统一各节点时钟和 Passport 密钥。

password、personal-access 等没有 OIDC 授权上下文的自定义 grant，不会仅因 openid scope 而得到 ID Token。

若 configure_passport=false 或自建授权服务器，需以现有 Passport auth-code/refresh 仓库、十分钟 code TTL 和宿主 access/refresh TTL，通过 AuthorizationServer::enableGrantType() 注册 Admin9\OidcServer\Bridge\AuthCodeGrant，并安装 TokenResponseType。授权、批准、拒绝及 token 路由均保留 EnforceAuthorizationPolicy。默认注册只替换授权码 grant，不替换服务器或其他 grants；再次覆盖此 grant 的宿主必须保留加密扩展点和中间件，不应删除旧码拒绝策略来绕过接入问题。

## 发布和恢复

- 同步已发布配置与模板，避免宿主旧模板继续加载第三方脚本。包模板使用静态内联 CSS，严格 CSP 需要对应 hash/nonce 或宿主本地样式。
- 按宿主流程刷新 route/config/view 缓存并重启长期 worker。
- Discovery 固定宣告 code/S256，与包端点一致；宿主额外端点和自定义 grants 需单独核查。
- 恢复流量前确认资源服务器机密凭证及查询授权映射。
- 跑支持矩阵并验收授权、退出确认、UserInfo、JWKS 和真实 RP 集成。
- 优先向前修复；回滚旧代码会恢复漏洞，不能恢复已退出会话或已撤销授权。

所有授权和 token 节点应一起升级，或先暂停签发、排空旧 worker 再切换流量。旧 worker 刷新时会丢失新上下文，因此不能长期混跑或单节点回退。提前通知 RP 负责人一次性 code 重启以及旧 refresh 响应变化；维护者和真实 RP 验收后，再与报告者私下协调版本和 Advisory 的发布时间。本地候选提交不等于已发布修复。

完整认证新鲜度及独立的 id_token_ttl 待办见[后续工作](../security-follow-ups.md)，本次继续沿用 Access Token 到期时间。
