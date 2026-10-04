# 2.0.0 stable release scope

2.0.0 promotes the accepted `v2.0.0-rc.1` runtime at `07cdea8fd3601ebd684532ae79e71eef95bdf4d6` without new features or runtime changes. Only the stable changelog, README and release/upgrade guidance change. The [RC](releasing-2.0-rc.md) remains the historical frozen scope. Final-commit remote CI, distribution checks and post-publication exact installation evidence are attached to the stable GitHub Release.

## Supported versions and integration

| PHP exercised by CI | Laravel | Passport |
| --- | --- | --- |
| 8.2 | 11 | 12, 13 |
| 8.2, 8.3 | 12 | 12, 13 |
| 8.3 | 13 | 13 |

The manifest and dependency constraints are unchanged. Current supported patches are exercised, not every historical patch or future PHP version. Laravel 11 retains the existing advisory-policy exception solely for compatibility resolution; it is not a production security endorsement.

Follow the [English](upgrading-to-2.0.0.md) / [中文](zh-CN/upgrading-to-2.0.0.md) upgrade guide: Passport keys, explicit completed-authentication records, a transaction-bound reauthentication handler, shared server-side sessions/atomic locks and authoritative Redis allowing `INFO server` and Lua are required. Replace previously published default-claims closures before caching configuration. Old code/refresh formats fail with `invalid_grant`, with no conversion or compatibility refresh; existing access tokens and database token rows are not automatically revoked. No package database migration is supplied. Package password/device/custom user grants are rejected; independent host OAuth requires its own native controller/server/response and distinct envelope key.

## Accepted evidence and reuse

- The immutable RC passed seven remote PHPUnit/Pest combinations, independent real Redis process tests, real upstream SSO, and clean public GitHub dist and exact Packagist RC installations. Static default claim callables preserve their original values and support configuration caching.
- The fixed-RC Member host removed its local path repository and installed normally from Packagist into empty vendor. All 46 distributed files matched RC content. Targeted host checks passed 49 tests / 522 assertions and independent PostgreSQL concurrency passed 4 / 92. Cached-configuration browser login/authorization/refresh/logout and guard/CSRF cases passed.
- A minimal actual Member-to-mall RP flow passed login, consent, server-side code exchange, ID Token verification and UserInfo. The RP implementation was checked with 85 PHP 7.4 assertions and deployed at `198d6bc54bda5fd077b0bec42abd3edb079ea0b5`; the final browser result confirmed Member identity. Optional email was absent. This is the minimal identity-verification loop, not mall business-member authentication.

The unchanged runtime, tests, workflows and package manifest make RC and host evidence reusable within those scopes. The final stable documentation commit still runs the existing seven-combination matrix, independent dist installation and SSO workflows on its own SHA. Distributed README changes are rechecked against that exact archive. After tagging, a new Composer home/cache and empty Laravel host install exact `2.0.0` through Packagist without a custom VCS/path repository. Source/dist references, file equality, platform requirements, provider discovery, cached config/routes, discovery/JWKS and consent rendering must pass.

## Known limits

- Production shared-state topology, Redis failover/Cluster, issuance-lock throughput and fleet cutover/rollback remain separately unaccepted. The completed real RP identity flow does not generalize to every IdP/RP or topology.
- Redis restart/promotion invalidates previous freshness sessions and code/refresh state. Missing positive state fails closed; a crash after consumption may burn a credential. Later signing/database/transport failures do not promise rollback.
- Transactions last 600 seconds, challenges at most 300 seconds, and capacity is 10 live transactions per session. General individual-claims selection and `auth_time` value/values constraints are not advertised. Independent ID Token TTL activation remains deferred; expiry follows the access token.
- The host's historical full-suite baseline failures remain documented; targeted acceptance is not a full-suite pass. Blank HTML validation may still display a 422 JSON document. The later host UserInfo missing/invalid-credential 401 fix is separate application work, reported as locally tested and uncommitted; this package release does not claim it is deployed.
- Mall member creation/binding/mapping, business tokens, refresh/logout integration and business account-login changes are outside the completed minimal RP identity check. No such implementation, production deployment, business migration or token revocation is included in this package release operation.

## Stable consumption

```sh
composer require 'admin9/laravel-oidc-server:2.0.0' --with-all-dependencies
```

The exact Composer constraint is `"2.0.0"`; no prerelease stability flag or global minimum-stability change is needed. Remove local path sources, preserve the lockfile and verify its source/dist reference against the stable tag's full commit SHA. Never modify installed package source to make host acceptance pass.

The formal release uses an annotated `v2.0.0` tag and a GitHub stable/latest Release after final-commit gates succeed. Published tags are never replaced. `main` and the RC tag remain unchanged during this release; branch integration is a separate operation. Package publication does not certify or authorize production rollout.
