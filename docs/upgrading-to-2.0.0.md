# Upgrading to 2.0.0

[English](upgrading-to-2.0.0.md) | [简体中文](zh-CN/upgrading-to-2.0.0.md)

**2.0 stable package integration guide. Historical package checks are recorded in [implementation evidence](implementation-2.0.md); subsequent host/RP acceptance and remaining limits are in [stable release scope](releasing-2.0.0.md). Publishing the package does not authorize or certify a production deployment.**

2.0 requires an explicit host authentication contract for every authorization-code transaction, including OAuth requests without `openid`. A Laravel login, remembered user, `setUser()`, session creation, or successful consent does not establish an authentication time. Existing sessions without a record must authenticate again. Old code/refresh envelopes are rejected with `invalid_grant`; there is no conversion or compatibility refresh path.


Before running `config:cache`, update previously published `default_claims_map` closures to the new static callable arrays. Custom claim resolvers must also be serializable; see [claims configuration](configuration.md#default_claims_map).
## Storage and routes

Use server-side sessions shared by all application nodes. Cookie and array session drivers are rejected on package authorization routes. Use Laravel session blocking on host login, reauthentication and logout routes; package authorize, approve, deny, continue and logout routes already call `block()`. The cache store used by Laravel session blocking must support shared atomic locks. Keep CSRF and host authentication rate limits on the host POST routes.

Configure the shared Redis connection used for positive, atomic freshness state:

```php
// config/oidc-server.php
'freshness' => ['redis_connection' => 'default'],
```

The connection must be available to every web/token worker, with consistent issuer/configuration. The tested client is `ext-redis`. The Redis connection must identify one authoritative writable server and allow `INFO server` and Lua evaluation (including INFO inside Lua). State keys are bound to that server’s `run_id`, which is rechecked inside each atomic script. A restart or promotion of another Redis process invalidates all old session/code/refresh state, even if an older snapshot restores an unconsumed value. If server identity cannot be established or changes between lookup and execution, issuance fails. Redis Cluster and other client/topology combinations need their own acceptance; do not route around an identity failure. Never restore a stale snapshot into the same running Redis process, or override its state manually. Configure availability/persistence to avoid unnecessary reauthentication; it is not a substitute for atomic consumption.

Session data contains scalar records and transactions. Redis contains a random session revision and positive one-time code/refresh state. Every session mutation compares and advances its revision atomically; old session IDs and delayed saves cannot advance a newer revision. Code/refresh consumption compares the original envelope fingerprint atomically before token side effects. A missing/expired record is rejected, never treated as unused. Redis restart/failover also invalidates outstanding proofs and credentials by changing the storage namespace. Loss of session revision state requires a new authorization and real authentication. Loss of token state requires the RP to authorize again. A process that stops after consumption may burn that credential; retry does not issue a second result. There is no promise of rolling back a later signing, database, process, or transport failure.

The implementation uses 10-minute transactions, 5-minute challenges bounded by the transaction expiry, and at most 10 live transactions per session. Starting a new challenge never extends the transaction. These limits are intentionally not configurable. No package database migration is required.

## Record real authentication

After the host has verified the selected authentication method **and every required factor**, establish the selected guard, rotate the session while preserving its data, then record the event:

```php
use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Illuminate\Support\Facades\Auth;

// $user has completed this host's entire authentication policy.
Auth::guard('member')->login($user);
$request->session()->regenerate(true);
app(AuthenticationRecorder::class)->markAuthenticated('member', $user);
```

Omitting the time records the call time. An upstream SSO integration must supply its verified **actual** authentication time as `DateTimeImmutable`; callback receipt time is not a substitute. Do not pass null for an unknown upstream time: null selects the local call time. Do not call the recorder on remember-cookie restoration, silent SSO restoration, partial MFA, or a framework Login event listener. A normal pre-existing record is reused without changing its time or generation.

`current($guard)` returns the record only when it matches the current guard user. `forget($guard)` removes that guard's authentication and pending transactions. The package listens to Laravel's Logout event. Custom logout code that does not dispatch that event must call `forget()` while the request still has its session. Other guards' authentication and application sessions are preserved.

## Reauthentication handler

Bind the host's redirect implementation to `ReauthenticationHandler` in its service provider:

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

The handler receives an immutable value locator. Its properties do not confer authority: the package always reloads and validates the session transaction. Do not put RP callbacks, scopes, nonce, state or PKCE in host form fields. Forms carry only the opaque `transaction`, `challenge`, CSRF token and host authentication inputs. Use `Cache-Control: no-store` and `Referrer-Policy: no-referrer` on host pages; do not log these credentials or send them to analytics.

Register GET and POST host routes in `web`, with `block()`; put an appropriate authentication throttle on POST. They must be reachable by guests as well as logged-in users. A missing handler is an integration error; the package does not fall back to Passport's native login flow.

The completion order is:

```php
use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Admin9\OidcServer\Contracts\ReauthenticationService;
use Illuminate\Support\Facades\Auth;

$service = app(ReauthenticationService::class);
$challenge = $service->challenge(
    $request, $request->string('transaction')->toString(), $request->string('challenge')->toString(),
);

// Host-owned operation: verify credentials/SSO and ALL required factors.
// The result must contain the verified user and actual authentication time.
$result = $hostAuthenticator->authenticateForChallenge($request, $challenge);
if (! $result->authenticatedAt instanceof \DateTimeImmutable) {
    throw new \LogicException('A verified authentication time is required for this host result.');
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

`$hostAuthenticator` is your existing authentication implementation, not a package API. The authenticated identity must match an already-bound transaction. A guest transaction binds its first successful identity once. The reported authentication time must be at or after challenge creation and no later than recording/completion. Same-second authentication is allowed, but still requires a new random generation and this transaction's challenge. A signed older SSO event with a fresh local generation is insufficient. Clock skew must be fixed; do not adjust reported times to pass the check.

`complete()` verifies and consumes the proof; it cannot authenticate or create an authentication time. It returns the package's single-use continuation redirect. For cancellation, retrieve the challenge and return `$service->cancel($request, $challenge)` from a CSRF-protected, blocked POST. Both completion and cancellation revalidate the client registration before returning an RP redirect.

## Consent and freshness behavior

Republish customized consent views. Their variables include `client`, `user`, `scopes`, `transactionId`, and `authToken`. Both approve and deny forms must send `transaction` and `auth_token`, plus CSRF; deny uses the DELETE method. Old `authRequest`, `authToken` session objects and old forms are not accepted. Do not forward authorization parameters through the form.

Each tab has its own transaction. A new authentication generation can invalidate another tab's consent. A positive `max_age` is checked at consent/resume and immediately before code persistence; expiry starts a new challenge and invalidates prior approval/continuation credentials. Successfully satisfying `prompt=login` or `max_age=0` does not repeatedly challenge the same transaction. `prompt=login consent` still displays consent. Trusted clients may skip consent, never authentication or final validation.

`prompt=none` never redirects to host authentication: insufficient authentication returns `login_required`, otherwise required consent returns `consent_required`. Combining `none` with another prompt is rejected. This release accepts `none`, `login`, and `consent`; other/repeated prompt values are rejected. `max_age` accepts representable non-negative decimal integers including leading zeros, and rejects negative, empty, fractional, exponent, duplicate and overflow values. Protocol strings such as state/nonce are preserved exactly.

The accepted ID Token `auth_time` claim requests are `null`, `{}`, and `{"essential":true|false}`. `value`, `values`, and other `auth_time` constraints are rejected; the package never fabricates or adjusts time to satisfy a requested value. Essential `userinfo.auth_time` is unsupported. UserInfo does not emit authentication time from custom claim resolvers. ID Tokens include the verified authentication time, including when it was not requested explicitly.

Other claims continue to follow granted scopes and the existing resolver. Discovery advertises `auth_time` in `claims_supported`; it does not advertise general individual-claims selection with `claims_parameter_supported`. General `sub`/`acr` value constraints are outside this authentication-freshness implementation. See the distinction in [OIDC Core individual claims requests](https://openid.net/specs/openid-connect-core-1_0.html#IndividualClaimsRequests).

Client revocation, callback removal, identity deletion or changed guard/provider/model/subject/issuer bindings terminate affected pending flows locally. An error is not redirected to a callback removed during interaction.

## Token and host OAuth boundaries

Package code and refresh envelopes require strict integer `oidc.v=3` plus the original nonce, identity, client, issuer, subject, authentication time and generation. Missing/unknown formats or extra/invalid context fields fail before new tokens or refresh revocation. Token requests cannot override this context. Token exchange is independent of browser sessions; it verifies the original identity in persistent storage.

The authorization's `max_age` is not an OP token TTL. An RP library may independently enforce authentication age when validating an ID Token; a delayed exchange can therefore be accepted by the OP but rejected by that RP. Do not change the recorded time to bypass the RP check. A code issued at authentication age 59 seconds under `max_age=60` may be redeemed at age 65, then refreshed later within its own lifetime. Refreshed ID Tokens retain the original `auth_time`, have a new `iat`, and omit nonce. Nonce may remain only inside the encrypted refresh context.

| Entry | Policy |
| --- | --- |
| Code and its refresh, with or without `openid` | Require the same authentication context; without `openid`, return OAuth tokens only. |
| Client credentials | No session/authentication record; no ID Token. |
| Passport personal access token factory | Remains host-owned; no new browser contract or ID Token. |
| Password, device, custom user grants at package routes | `unsupported_grant_type` before issuance. |
| Retained Passport authorization/token routes | Protected package aliases, including custom Passport prefixes. |

The package constructs `OidcAuthorizationServer` with its own grants and response, and no longer sets `Passport::$authorizationServerResponseType` or installs a global `afterResolving(AuthorizationServer::class)` hook. `configure_passport=false` leaves host scope/model/TTL configuration to the host while package server protections remain in force. Keep package controllers and `EnforceAuthorizationPolicy` on manually registered package routes, add `web` and `block()` to browser routes, and do not bind package grants/response globally.

A separate host OAuth entry must use a host-owned controller class (not the retained Passport controller aliases), its separately configured native server/grants and an explicit native `BearerTokenResponse`. Package tests verify the separate host controller, native server, response, code/refresh and personal-token factory on both Passport versions. Target-host acceptance is tracked separately in the implementation evidence. It must own its own issuance/refresh policy; pointing another URL at the protected package server does not create isolation.

### Minimal independent host server

Use a separate envelope encryption key, so the host entry cannot turn package materials into an alternate refresh/compatibility path. `OAuthIsolationTest` checks both directions with the same client credentials: native envelopes fail at package routes, and package code/refresh fail at the host route without consuming the valid package credential. Apply the host's own client/scope policy as well.

A host service provider can construct its own password/refresh server using shared Passport repositories/signing keys and a distinct envelope key:

```php
use Admin9\OidcServer\Services\PassportKeys;
use App\Http\Controllers\HostOAuthTokenController;
use Laravel\Passport\Bridge;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;

$this->app->singleton('host.oauth', function () {
    $keys = app(PassportKeys::class);
    $key = config('host-oauth.encryption_key'); // Host-owned, independently generated secret.
    if (! is_string($key) || strlen($key) < 32 || hash_equals($keys->encryptionKey(), $key)) {
        throw new \RuntimeException('Configure a distinct host OAuth envelope key.');
    }
    $server = new AuthorizationServer(
        app(Bridge\ClientRepository::class), app(Bridge\AccessTokenRepository::class),
        app(Bridge\ScopeRepository::class), new CryptKey($keys->key('private')->contents(), null, false),
        $key, new BearerTokenResponse,
    );
    $grants = [
        new PasswordGrant(app(Bridge\UserRepository::class), app(Bridge\RefreshTokenRepository::class)),
        new RefreshTokenGrant(app(Bridge\RefreshTokenRepository::class)),
    ];
    foreach ($grants as $grant) {
        $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $server->enableGrantType($grant, Passport::tokensExpireIn());
    }

    return $server;
});
$this->app->when(HostOAuthTokenController::class)
    ->needs(AuthorizationServer::class)->give(fn () => app('host.oauth'));
```

`HostOAuthTokenController` is a host-owned class, for example a subclass of Passport's token controller registered at a host-only URL. Configure the host's clients for the intended native grant and install its own throttling/access policy. Do not register this controller under a retained Passport controller class name, bind the native server globally, or reuse the package envelope key. Different signing domains/resource servers require matching host signing keys and resource-server configuration too. The package does not configure those host policies.

## Cutover and rollback

1. Integrate explicit authentication, handler, shared sessions/locks and durable atomic state. Test actual password, MFA and SSO integrations and both guards. Confirm RP handling of `login_required`, `consent_required`, and `invalid_grant` by starting a new authorization.
2. Deploy host integration before routing traffic to 2.0. Drain authorization/token requests, then switch all workers together. Do not mix old and new signing workers. Synchronize keys, issuer, guard/provider configuration, clocks, shared stores, published views, route/config caches and long-lived processes.
3. Reject old pending pages, codes and refresh tokens. Existing access/ID tokens are not automatically revoked and retain their original expiry/revocation rules. Rejection of an encrypted envelope does not revoke its database row.
4. Prefer a forward fix. Rollback requires draining and switching the entire fleet. 1.2.2 rejects `max_age`/Essential `auth_time` and cannot restore those guarantees merely by reauthorization. Keep affected clients' new authorization and old refresh retries closed until a capable service returns.
5. Rehearse the rollback gate with an unrevoked old-format refresh sample. A rollback may otherwise make that token usable again. Permanent revocation is a separate operational action; this package upgrade does not perform it.

This local implementation does not authorize deployment, data mutation, token revocation or release publication.
