# 2.0 implementation evidence

This delivery implements the package scope of the [design at `48b01c5`](authentication-freshness-2.0.md). **The agreed completion boundary is local 2.0 package delivery; business-host integration is a separate acceptance stage.** Local checks include an actual upstream SSO provider, a Laravel HTTP host, an independent RP, concurrent processes and rollback rehearsal. This is not a production release or acceptance of a particular business host.

| Stage | Current evidence | Remaining condition |
| --- | --- | --- |
| 1. Contracts and extension points | `FreshnessExtensionPointsTest` verifies League 8/9 checks before persistence/revocation. A dedicated package server replaces global response/server hooks. `OAuthIsolationTest` verifies native controller/server/grants/response, personal access factory and a distinct envelope key. | Local compatibility runs and final affected-area checks passed; see the matrix below. |
| 2. Explicit authentication | Recorder tests cover implicit Login/remember/setUser, same-second generations, wrong identity, future time and guard isolation. The real HTTP host verifies password plus a TOTP factor before recording. Wrong second factor produced 401 and zero code/token rows. | Real local upstream SSO also passed; the business host must connect its own authentication stack. |
| 3. Transactions and challenges | Scalar session state, guest identity binding, challenge proof, one-time resume/approval, terminal failure, cancellation, capacity and guard logout. Real HTTP tests cover session rotation, late old-session saves, expired lock leases and killed workers. | Target deployment topology must meet the documented shared-state contract. |
| 4. Authorization freshness | Unknown time, zero/positive max_age, exact boundary, final-check expiry, consent expiry, stale forms, independent tabs, client/callback changes, deleted identity, trusted clients and silent errors. Failed registration cannot be revived by restoring the client. | Local compatibility runs and final affected-area checks passed; see the matrix below. |
| 5. Context v3 and grants | Strict keys/types/canonical identity, frozen authentication time, original nonce, session-independent exchange, early rejection of malformed/old envelopes, OAuth-only envelopes and grant boundaries. Real code/refresh races issue one result. | Local compatibility runs and final affected-area checks passed; see the matrix below. |
| 6. Integration boundaries | Guard-scoped Logout cleanup, discovery, protected retained aliases, manual Passport configuration, reused server instance isolation and explicit host OAuth key separation. | Target-host wiring remains to be reviewed against its own routes/configuration. |
| 7. Real integration | Real Laravel HTTP host, Redis, SQLite, password/TOTP, two independently authenticated guards, independent `openid-client` RP and two HTTP service groups. | A separate oidc-provider OP now verifies real credentials and supplies signed actual authentication time; see the SSO evidence below. Business-provider acceptance remains separate. |
| 8. Upgrade preparation | English/Chinese host/RP integration docs. Actual `48b01c5` baseline rollback, closed authorization/refresh gate, unrevoked legacy sample, negative control, and forward recovery exercised locally. | Target-host operational rehearsal is separate from the disposable local fixture. |

## Established implementation decisions

- Final authorization validation precedes the parent grant completion method, which persists code before encryption. Code context validation runs in the decrypt hook; atomic code consumption follows native client/callback/PKCE validation and precedes access-token persistence. Refresh context validation/consumption follows native old-refresh validation and precedes revocation/persistence.
- Package controllers use `OidcAuthorizationServer`. Native host controllers use their own server/response. Retained Passport controller routes are protected aliases. Separate host OAuth recipes use a distinct envelope key so they cannot become an alternate redemption path for package materials.
- `FreshClientRepository` bypasses Passport 13's memoized client lookup. Tests revoke a trusted client inside its consent decision and verify that final validation prevents persistence.
- Positive Redis state uses compare-and-replace, never missing-means-unused semantics. A shared session revision survives ID rotation but rejects delayed snapshots. State is bound to Redis `run_id`, checked again inside Lua; restoring a pre-consumption snapshot after Redis restart cannot resurrect a credential. An unidentifiable/changing server fails closed. Same-process manual state rollback is not supported.
- Authentication snapshots are the sole source of time/generation for approval. Challenge proof contains only challenge ID and snapshot generation. Direct reuse of an adequate record creates no challenge proof or new authentication event.
- Transactions last 600 seconds, challenges at most 300 seconds within that deadline, with 10 live transactions per session. New challenges invalidate old approval/resume credentials without extending the transaction. Code/refresh state lives through envelope expiry plus 60 seconds; premature loss still rejects redemption.
- Supported auth_time claim requests are null, empty options and boolean essential. Value/values constraints are rejected. Other claims remain scope/resolver based; general individual-claims selection is not advertised. `auth_time` is advertised and returned truthfully in package ID Tokens.
- Password/device/custom user grants are rejected at package token routes. Client credentials and host personal tokens retain their OAuth behavior without ID Tokens or browser authentication requirements.

## Package compatibility evidence

Disposable dependency checkouts keep the main vendor tree unchanged. CI runs both PHPUnit and Pest; the contribution guide permits either runner for local tests. Version-specific skips are intentional: Passport 12 skips Passport-13-specific client/hasher tests; Passport 13 skips the Passport-12 hashed-secret test. They are exercised in the corresponding other major. The following matrix records the `b803b80` baseline; subsequent review fixes are documented separately below.

| PHP / Laravel / Passport / League | Completed evidence | Latest delta |
| --- | --- | --- |
| 8.3.0 / 13.17.0 / 13.7.5 / 9.4.1 | Final full PHPUnit and Pest: 175 tests, 1413 assertions, 1 version skip. | Passed on final runtime code. |
| 8.2.13 / 12.69.3 / 12.4.3 / 8.5.5 | Full PHPUnit and Pest: 170 tests, 1336 assertions, 3 version skips. Final affected-area run: 37 tests / 341 assertions. | Later changes covered by the final run; unaffected earlier results retained. |
| 8.3.0 / 12.69.3 / 12.4.3 / 8.5.5 | Full PHPUnit and Pest: 170 tests, 1336 assertions, 3 version skips. Final affected-area run: 37 / 341. | Later changes covered by the final run. |
| 8.2.13 / 11.57.0 / 12.4.3 / 8.5.5 | Full PHPUnit and Pest: 170 tests, 1336 assertions, 3 version skips. Final affected-area run: 37 / 341. | Later changes covered by the final run. |
| 8.2.13 / 12.69.3 / 13.8.0 / 9.4.1 | Full PHPUnit and Pest: 165 tests, 1300 assertions, 1 version skip. Final affected-area run: 37 / 341. | Later changes covered by the final run. |
| 8.2.13 / 11.57.0 / 13.8.0 / 9.4.1 | Full PHPUnit and Pest: 165 tests, 1300 assertions, 1 version skip. Final affected-area run: 37 / 341. | Later changes covered by the final run. |
| 8.3.0 / 12.69.3 / 13.8.0 / 9.4.1 | Final full PHPUnit and Pest: 175 tests, 1413 assertions, 1 version skip. | Passed on final runtime code. |

Laravel 11's compatibility solve uses the repository's existing advisory-policy exception in the disposable checkout. Composer reports four advisories affecting the framework; compatibility success is not a recommendation to deploy that framework version. No project/global Composer security setting was weakened.

Composer manifest validation, `git diff --check`, PHP syntax checks, Node syntax checks, and nine PHP documentation examples (in their stated context) passed. CI configuration now enables ext-redis and installs Redis for independent-process tests. Remote CI has not run because nothing has been pushed.

## Review fixes after b803b80

Three P2 findings are corrected: reconstructed authorization requests restore the exact original state; transactions distinguish a validated callback from whether redirect_uri was supplied, preserving OAuth code exchange without that originally omitted parameter; and SSO acceptance exceptions explicitly exit with status 1. The original callback remains pinned and is revalidated even when the parameter was omitted. If its registration becomes ambiguous or the original callback is removed, the flow fails locally.

Five regression tests cover spaces/empty/zero/absent state, confidential/public OAuth clients, explicit redirect binding, changed registration and guest reauthentication/continuation. The affected Passport 13 suite passed 55 tests / 633 assertions. The subsequent full Passport 13 PHPUnit suite passed 180 tests / 1457 assertions, with one Passport-12-only version skip. Passport 12 passed the other 54 cases; its one negative-request assertion was corrected for the existing native HTTP 400/401 difference and reran successfully (1 test / 12 assertions, 633 across all 55 cases).

The final real SSO runner passed its new connection-failure exit-status control followed by the actual password/SSO/code/refresh flow. Current results and runtime fingerprint are recorded under `review_fixes` in [local HTTP evidence](local-http-evidence-2.0.json). The earlier matrix and operational rehearsals above remain historical baseline evidence, not claims of rerunning every combination after these fixes.

## Review adoption after d7fdfa8 (2026-10-04)

The reviewed delta adds refresh-parameter validation before the parent grant's exception normalization, exact silent-consent assertions, English/Chinese configuration and upgrade notices, and the manual `Integration SSO` workflow. Client authentication still precedes parameter validation; nonempty invalid credentials retain `invalid_grant`. Client credentials scope behavior and the Redis/grant design are unchanged.

The final documentation check also corrected the retained `auth:member_web` middleware example: package authorization routes filter built-in `auth`, `auth:*` and `Authenticate` class entries. Only remaining custom authentication middleware can override silent authorization handling. This documentation-only correction preserves the runtime and verification results below.

The code baseline is `d7fdfa8` plus this review-adoption delta. The only changed runtime source, `src/Bridge/RefreshTokenGrant.php`, has SHA256 `b69a995a5d1ba494c741c70f7f3b31dc6b008c091a5b2c52d06b3b2979c0cfaf`. The isolated Passport 12 checkout's tracked source and tests matched the working tree byte-for-byte.

| Environment | Executed checks | Result |
| --- | --- | --- |
| PHP 8.3.0 / Laravel 13.17.0 / Passport 13.7.5 / League 9.4.1 / Testbench 11.1.0 / PHPUnit 12.5.30 | Eight affected feature-test classes, including the added invalid-credential and scope/context checks | 95 tests / 1180 assertions across two sequential runs; 1 expected Passport-12-only skip; no failures/errors. |
| PHP 8.2.13 / Laravel 12.69.3 / Passport 12.4.3 / League 8.5.5 / Testbench 10.12.0 / PHPUnit 11.5.56 | Targeted token security, consent, context/scope, legacy refresh, manual configuration and authentication-freshness regressions in a disposable dependency copy | 43 tests / 564 assertions; 2 expected Passport-13-only hasher skips; no failures/errors. |
| Current PHP 8.3 environment / Node 22.23.2 / oidc-provider 9.12.2 / openid-client 6.8.8 | `bash tests/Integration/run-sso.sh`, using the committed npm lockfile and private Redis/HTTP fixtures | Connection-failure exit-status control and the full real SSO scenario passed. |

Before the runtime fix, all eight new parameter cases failed with actual `invalid_grant` instead of expected `invalid_request`. Afterward, missing, null, empty, whitespace, array, numeric and boolean inputs all preserve the existing token rows and usable refresh state, with a successful valid retry. Wrong client secrets still return `invalid_client` first. Corrupt, expired, differently bound, consumed and revoked refresh credentials retain `invalid_grant`; the existing legacy-format and invalid-scope regressions also pass. Silent consent checks now assert the exact callback, unchanged state, `consent_required`, no code and unchanged code/token counts.

The SSO run rejected wrong credentials, stale silent restoration, callback replay and unrelated state; forced fresh authentication, independent RP validation, refresh preserving `auth_time` without nonce, and Admin/Member isolation passed. Only the sanitized `sso-results.json` is eligible for the new workflow artifact.

Actionlint 1.7.12, PHP syntax checks on both PHP versions, shell syntax, Composer manifest validation, bilingual configuration/link checks and `git diff --check` passed. Test processes ran sequentially. The earlier full matrix and fault/rollback rehearsals remain historical evidence; they were not rerun for this delta. The new remote SSO workflow and the final remote compatibility matrix are **pending**: no push, remote workflow dispatch, tag, release or deployment was performed.

## Real HTTP and independent RP evidence

The reproducible fixture is under `tests/Integration` with its own README. It uses private temporary runtime files and a separate SQLite database. Its test users/keys are not production identities.

Sanitized machine-readable results and reproducible SHA256 fingerprints of the runtime source are retained in [local HTTP evidence](local-http-evidence-2.0.json). Bearer tokens, session cookies, challenge credentials and private keys are excluded. The `b803b80` baseline's final PHPUnit run passed 175 tests / 1413 assertions with the Passport-12-only hashed-secret case skipped on Passport 13. Baseline runtime files matched the earlier compatibility checkouts byte-for-byte; their unaffected PHPUnit/Pest evidence is retained.

Browser acceptance used ego-browser and the independent RP used `openid-client 6.8.8`, with ID Token signature verification enabled. Observed:

- A correct password with an invalid TOTP factor returned 401, with no code/access-token rows.
- Successful max_age=0 and positive max_age authorization passed discovery, S256, state, nonce, issuer/audience and JWT signature checks.
- Refresh retained the original authentication time, produced a later iat, and omitted nonce.
- Admin remained logged in after Member reauthentication and session rotation.
- Silent authorization produced consent_required for a known record; silent max_age=0 produced login_required without host interaction.
- The RP received invalid_grant for a legacy refresh and started a new authorization successfully.

An initial max_age=0 run remained on consent too long during inspection and was rejected by the RP's own age check. It is not counted as a pass. A continuous rerun passed. The OP does not reapply original max_age at token exchange, while an RP library may independently validate age; the integration guide records this distinction without changing authentication times.

`concurrency.php` sends requests from independent PHP processes to two HTTP service groups sharing Redis and SQLite. Ten cases passed:

| Scenario | Observed result |
| --- | --- |
| Challenge completion competition | One 302, competing old-session requests rejected; no token rows. |
| Approval competition | One 302 and one code; competing requests 400. |
| Code redemption competition | One 200 and one access/refresh pair; competing requests 400. |
| Refresh competition | One 200 and one new pair; competing requests 400. |
| Session lock lease expires during issuance | A second request rejected before the first finished its 12-second critical section; one code only. |
| Pre-rotation request saves its old session late | Restored old snapshot rejected with 400; the valid new session issued once. |
| Positive refresh state deleted | invalid_grant; no new token and no old-refresh revocation. |
| Worker SIGKILL after code consumption | Worker absence verified; retry on the peer returned invalid_grant; no new token. |
| Worker SIGKILL after challenge completion | Worker absence verified; old session retry rejected; no token. |
| Worker SIGKILL after approval consumption | Worker absence verified; peer retry 400; no code/token. |

The first process-fault prototype used PHP exit(), which only ended the request in the built-in server. Those observations were insufficient. The reported cases were rerun with SIGKILL and verified process termination.

`RedisAtomicStateTest` additionally passed four tests / 23 assertions on real Redis: six-process atomic competition, delayed old revisions, early expiry/missing positive state, and actual restart from a snapshot containing the pre-consumption value. Storage identity prevents that stale value from being consumed after restart.

`storage-outage.php` stopped only the dedicated fixture Redis. Code, refresh and authorization failed with server errors; code/token counts remained unchanged and the valid refresh row was not revoked. This proves absence of an unlocked fallback at those entry points.

## Real upstream SSO evidence

`bash tests/Integration/run-sso.sh` creates a fresh local fixture using the committed npm lockfile, starts a separate `oidc-provider 9.12.2` OP and `openid-client 6.8.8` RP, runs `sso.php`, and stops its own processes. The provider verifies a real password and generates authentication time itself. No simulated clock, supplied login timestamp or forged ID Token is used. The callback verifies the actual code exchange and signed assertion before recording the exact upstream time.

The reproducible run passed wrong-password rejection, genuine SSO bootstrap, silent restoration returning a new signed assertion with an old auth_time, rejection before code/token issuance, callback replay/state mismatch, forced fresh credential authentication, downstream consent and RP signature/nonce/PKCE validation, refresh preserving the original upstream time, and Admin/Member isolation. Sanitized timestamps and row-count outcomes are in [local HTTP evidence](local-http-evidence-2.0.json).

The same silent-rejection and forced-login/consent/refresh flow passed in ego-browser. The first development attempt omitted the upstream client's require_auth_time setting and was correctly rejected by the callback verifier; only the corrected, completed runs count as acceptance. The fixture's loopback HTTP and in-memory upstream adapter are deliberately local; they do not substitute for business-host/provider deployment checks.

## Local rollback and forward recovery

The old package source was exported directly from `48b01c5` into a disposable directory. The fixture gate was closed, 2.0 HTTP workers stopped, and only the baseline server started. The gate blocked both new authorization and an unrevoked old-format refresh without modifying the database. A controlled open-gate check then proved the baseline could still refresh the old material and still rejected max_age. The gate was closed again.

The old server was stopped before starting both 2.0 service groups. Discovery on both nodes confirmed authentication-time support before reopening. The rollback-produced refresh was rejected with invalid_grant without revoking its row; new authenticated authorization succeeded with real auth_time. This is a local rehearsal, not a production deployment or target-fleet acceptance.

## Business-host acceptance, outside this local delivery

Before deploying this package into a business host, verify these concrete items in that host:

- Bind its recorder/reauthentication handler after every required factor; verify the actual upstream provider's issuer, keys, audience, state/nonce and subject mapping. Confirm forced authentication is honored and silent/remember restoration never gets recorded as a new event. Match the challenge even for events in the same second; do not substitute callback arrival time or adjust timestamps for clock skew.
- Exercise Member/Admin isolation, session rotation, logout, retained Passport aliases, custom middleware, published consent views and any independent OAuth controller/server/envelope key.
- Validate the deployed shared sessions, blocking, Redis INFO/Lua permissions and authoritative-server contract, persistence/failover behavior and synchronized clocks across all nodes. Run the lease-expiry, stale-session, missing-state and process-failure checks on that topology.
- Verify each real RP checks signatures/state/nonce/PKCE, consumes auth_time/max_age correctly and restarts authorization after legacy invalid_grant. Test its own consent timing and refresh behavior.
- Rehearse draining authorization/token traffic, coordinated worker/config/view-cache replacement, a rollback gate covering both authorization and unrevoked legacy refresh, and forward recovery. Decide token revocation separately; deployment itself does not revoke previously issued tokens.

These are required before business-host production acceptance, not unfinished package implementation. No push, release, deployment to a user environment, business migration or production token-revocation operation has been performed. Test fixtures create and rotate only their own disposable credentials.
