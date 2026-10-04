# 2.0.0-rc.1 frozen scope and release gates

The 2.0 feature scope is frozen for this candidate. Only release-blocking corrections may enter before tagging; every such correction needs affected-area verification and all final-commit remote gates. This candidate does not authorize a stable 2.0.0 release or production rollout.

## Supported versions

| PHP exercised by CI | Laravel | Passport |
| --- | --- | --- |
| 8.2 | 11 | 12, 13 |
| 8.2, 8.3 | 12 | 12, 13 |
| 8.3 | 13 | 13 |

The manifest remains PHP `^8.2` and Passport `^12.0|^13.0`; Laravel and Passport enforce their own upstream PHP/framework requirements. The matrix resolves supported current patches; it does not certify every historical patch or future PHP release. Laravel 13 requires PHP 8.3+ and Passport 13. Laravel 11 retains the pre-existing CI advisory-policy exception solely for compatibility resolution: this is not security approval of an unsupported framework or a production install recommendation. No new exception is permitted to make RC gates pass.

## Features and integration contract

Frozen features include explicit real authentication records, transaction-bound reauthentication, `auth_time` and `max_age`, isolated guards, strict v3 authorization/refresh contexts, authoritative Redis atomic state, and protected Passport route aliases. Existing discovery, JWKS, UserInfo, introspection, revocation, logout, claims and client credentials remain in scope. The [English](upgrading-to-2.0.0.md) and [Chinese](zh-CN/upgrading-to-2.0.0.md) upgrade guides are authoritative for host hooks, actual upstream authentication, consent forms and rollout.

Hosts must install/configure Passport and signing keys; record only completed authentication; bind a reauthentication handler; use shared server-side sessions, shared atomic session locks and an authoritative Redis connection permitting `INFO server` and Lua. Both OIDC and OAuth-only authorization-code flows require this contract. Old code/refresh formats fail with `invalid_grant`; no compatibility refresh or package database migration is supplied. Existing access tokens and database token rows are not automatically revoked. Package password/device/custom user grants are rejected; separate host OAuth needs its own native controller/server/response and distinct envelope key.

## Known limits

- Redis restart/promotion invalidates old freshness session/code/refresh state. Cluster, other clients/topologies, real business IdPs/RPs, production throughput and fleet cutover/rollback require host acceptance.
- Missing positive state fails closed. A crash after one-time consumption may burn a credential; later signing/database/transport failures do not promise rollback.
- Transactions last 600 seconds; challenges at most 300 seconds; each session holds at most 10 live transactions. These limits are fixed.
- General individual-claims selection is not advertised. Supported `auth_time` options do not include value/values constraints. The configured ID Token TTL remains separate follow-up work; this candidate does not activate it.
- The SSO fixture uses a disposable loopback OP and independent RP. Its protocol assertions and prior browser acceptance do not replace actual business-host/provider acceptance.

## Immutable release gates

1. Push the candidate to `2.0`, leaving `main` unchanged. Record its full SHA. The seven PHPUnit/Pest jobs, independent clean-dist installation and real upstream SSO must all succeed for that SHA. Retain remote run URLs and sanitized artifacts outside the immutable candidate checkout.
2. Clean-dist installation uses only the candidate's tracked public GitHub archive and declared production dependencies in a new Laravel host and Composer home/cache. Archive equality, no local path/source checkout or development dependencies, platform checks, provider discovery, cached routes/configuration, discovery/JWKS and distributed consent view must pass.
3. Re-read remote tags/releases and distribution state. Never replace a published tag. Create annotated `v2.0.0-rc.1` at the accepted SHA and a GitHub prerelease, not a stable/latest release. Read back both tag target and Release state.
4. Verify Packagist's exact RC reference and install `admin9/laravel-oidc-server:2.0.0-rc.1` without a custom repository. If indexing is pending, report it explicitly; an isolated VCS repository using the public GitHub tag is an interim fixed-version source, never a local path link.

Consumer constraint: `"admin9/laravel-oidc-server": "2.0.0-rc.1"`. A stable-default Composer project accepts this explicitly selected prerelease; do not change global minimum stability. Preserve the generated lockfile and check its source/dist reference against the release SHA. Host acceptance occurs separately in an isolated environment, without editing installed package source.

[Historical implementation evidence](implementation-2.0.md) records the earlier package and browser checks. Current business-host evidence is separate and includes known UI/baseline test limitations. It must not be represented as full business-host or production approval.
