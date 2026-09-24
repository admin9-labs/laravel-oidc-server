# Upgrading to the v1.2.2 security changes

This release retains the original eight Advisory security fixes and the refresh-token introspection fix. It also corrects nonce binding and stops issuing false authentication times. Full authentication-time tracking and max_age enforcement, and the separate ID Token TTL setting, remain deferred.

No database schema migration is required. Review host overrides of routes, controllers, client models, middleware and published views.

Passport routes are disabled before providers boot by default. If `ignore_passport_routes=false` retains a separate Passport prefix, its authorization/approval/token routes receive the same security policy. Middleware checks are independent of `configure_passport`; context-aware grant/response registration requires automatic configuration or the equivalent manual setup below. Host-defined unrelated OAuth controllers need their own review.

## Token endpoints

- Introspection requires an active confidential client and permits only its own tokens by default.
- A separate confidential resource server can be authorized through `introspection_allowed_clients`: querying client ID => array of token-owning client IDs, all as strings. This never grants cross-client revocation permission.
- Public clients may revoke their own tokens by presenting the actual token; a client ID alone cannot authorize introspection.
- Raw IDs and unverified JWTs are rejected. Refresh tokens must be authenticated encrypted Passport envelopes with matching client/access/refresh identifiers.
- Missing, incorrect or unknown `token_type_hint` does not prevent lookup of the other token type.
- Without `email` scope, introspection omits `username`.
- Basic and body client credentials cannot be combined. Passport 12 plaintext/opt-in hashed secrets and Passport 13 hashed secrets are supported. Passport 13 uses the configured Laravel Hasher, including custom drivers.
- Signing, verification and JWKS honor Passport configured keys and `Passport::loadKeysFrom()`. Verification needs no private key. Use consistent keys on every node.

## Logout

GET and POST `/oauth/logout` are protocol entrypoints without an OP CSRF requirement. Only a signed, unexpired ID Token with the expected issuer, one active client audience and the current guard user's OIDC subject can end the session directly. Access tokens are not ID Token hints. An explicit `client_id` must agree with the hint.

Other requests render confirmation. Expired, otherwise valid hints can identify the RP for confirmation but cannot silently log out. This binds the current guard/model/user; it does not issue `sid` or track every RP login session.

The page submits POST `/oauth/logout/confirm`, protected by normal web CSRF checks and a single-use, five-minute server-side challenge. Keep this route out of host CSRF exceptions. Redirect/state are held on the server; user identity and client registration are checked again at confirmation. HEAD never logs out or replaces pending confirmation.

Register complete, exact URI strings (query, fragment and trailing slash included) in `post_logout_redirect_uris`: client ID => URI array. If no entry exists, that client's OAuth redirect URIs are reused with exact matching. An explicit empty entry disables redirects for that client.

`post_logout_redirect_uris_supported` is only the allowlist for local confirmed logout without a client. It is not combined with a client's registration. Arbitrary same-origin paths are no longer authorized. No permitted target means a redirect to `/`. State is inserted into the query before any registered fragment.

Callers expecting unsigned GET to log out immediately must handle a 200 confirmation page. Other guards' login state remains intact.

## Consent and historical grants

Default client models require consent. The package authorization controller no longer treats existing access tokens as evidence of explicit consent: existing rows cannot reliably distinguish explicit approval from historical automatic grants.

The no-schema policy asks for consent on every authorization request, even after approval or refresh. `prompt=none` cannot silently approve based on an existing token. A deliberately customized client model may still implement trusted-client `skipsAuthorization()`; audit such overrides separately.

Pending authorization pages from before deployment must restart. Approval requires the current nonempty auth_token, a policy-checked authorization page and the same guard/model/user. Package endpoints enforce code/S256, reject implicit/plain despite old published metadata config, and reject previously issued plain-PKCE codes.

Installing this patch does not revoke existing tokens or recover disclosed data. To invalidate affected historical grants:

1. Inventory affected RP client IDs, distinguishing deliberately trusted integrations. Existing rows do not identify which approvals were explicit.
2. Stop issuance/refresh for selected clients, drain old workers, then record a UTC cutoff. Review the access-token set for those clients at or before the cutoff and every linked refresh token. A cutoff alone is unsafe while refresh remains possible: refresh can create tokens after it.
3. Using configured Passport models/repositories and the Passport database connection, revoke the reviewed access-token set and **all linked refresh tokens** transactionally. Large sets may use bounded batches while issuance remains stopped. Do not delete clients or unrelated sessions.
4. Verify introspection and a real refresh reject samples, then reopen clients and require fresh authorization.

This deployment data operation requires separate authorization. Retain reviewed targets/counts and communicate reauthorization impact. Reverting code cannot restore revoked tokens.

## Nonce and authentication freshness

Authorization-request `nonce` is preserved exactly, including `"0"`, whitespace and the empty string, and bound to the client and user inside Passport's authenticated encrypted authorization code. Approval and token requests cannot replace it. Clients previously sending nonce only to `/oauth/token` must move it to `/oauth/authorize`. Refresh ID Tokens omit nonce. Custom claims cannot override protocol claims.

All ID Tokens omit `auth_time`, including after credential login, remember-cookie restoration, token refresh and direct `IdTokenService` calls. The package does not track Login/Logout events, login generations or authentication age. It does not substitute issuance time for authentication time. Discovery excludes `auth_time`, including values in older published scope configuration.

Authorization requests containing `max_age` are rejected with `invalid_request`, regardless of value, without logging out or starting login. The same applies to `claims` requesting Essential `auth_time` in `id_token` or `userinfo`. Malformed JSON/claim structures, duplicate protocol parameters and normalized parameter aliases are also rejected. The package first validates the client and callback using Passport, then redirects the error, a description and the original state to that callback. Invalid clients/callbacks receive the upstream error without an untrusted redirect. Other well-formed claims requests retain the existing unsupported behavior; this is not a general Claims-parameter implementation.

OIDC Core requires complete OP implementations to support authentication time and maximum authentication age. This release has a deliberately limited capability set and is not a claim of full OIDC compliance. RPs requiring freshness must handle the error and use an independently verified authentication flow; removing max_age from a sensitive request does not satisfy its requirement. Previously issued ID Tokens cannot be repaired retrospectively.

Ordinary guest authentication, `prompt=none` and `prompt=login` are handled by the installed Passport version. The package does not strip login prompts or add a reauthentication state machine. Passport's native `prompt=login` can invalidate the entire shared session, including other guards. This is the pre-existing upstream behavior; only this package's logout endpoint guarantees guard isolation. Passport 12 and 13 retain their respective native prompt/error behavior.

Passport keeps one pending consent page per session. A second page supersedes the first; an old approval is rejected rather than inheriting another transaction's nonce. Issued codes are independent and can be exchanged out of order without a browser session. The package uses the existing grant/response encryption hooks and retains Passport's client, redirect, PKCE, expiry and single-use checks, without adding persistence or schema changes.

The internal `oidc` envelope is version 2, containing only nonce and the bound client/issuer/subject/guard/model/user identity. It carries no authentication time or age. Version 1 from the unpublished candidate is not migrated; preserve the old commits only as development references, not as a compatible deployment.

| Pre-upgrade material | Upgrade behavior |
| --- | --- |
| Pending consent page, including candidate v1 context | Restart authorization. |
| OIDC authorization code without v2 context | `invalid_grant`; restart authorization instead of retrying the code. |
| OAuth-only authorization code without OIDC context | Continues under Passport validation and the S256 policy. |
| Access Token / already-issued ID Token | Original expiry/revocation state is unchanged; old auth_time is not evidence of fresh authentication. |
| Refresh Token without context | Continues rotating OAuth access/refresh tokens without id_token. Obtain a new authorization code for an ID Token. |
| Candidate v1, unknown or malformed OIDC context on code/refresh | `invalid_grant`; never downgraded to the context-free legacy path. |
| New Refresh Token | Preserves original client and identity binding across rotation; changing issuer, guard, model or subject requires fresh authorization. ID Tokens omit nonce/auth_time. |

Keep the existing issuer and user-provider mapping during this security upgrade. Verify that an explicit `user_model` uses the same model class returned by the `passport.guard` provider (or the default guard's provider); a different runtime class is rejected with `invalid_grant` when exchanging OIDC codes or refresh tokens carrying identity context, even if it represents the same user. Legacy tokens contain no provider history: if changing those mappings separately, revoke the affected legacy access/refresh tokens first rather than interpreting their user IDs in a different provider. Synchronize clocks and Passport keys across nodes before restoring issuance.

Password, personal-access and other custom grants without an OIDC authorization context do not receive an ID Token merely by requesting `openid`. These flows have not established this package's OIDC authentication transaction.

For `configure_passport=false` or a custom authorization server, register `Admin9\OidcServer\Bridge\AuthCodeGrant` with `AuthorizationServer::enableGrantType()` using the existing Passport auth-code/refresh repositories, a ten-minute code TTL and the host's access/refresh TTLs; also install `TokenResponseType`. Keep `EnforceAuthorizationPolicy` on authorization, approval, denial and token routes. The package's default registration replaces only the authorization-code grant, not the server or other grants. Hosts replacing this grant again must preserve the encryption hook and middleware. Do not remove the fail-closed legacy-code check to work around an incomplete integration.

## Deployment and recovery

- Update published config and views. Host copies of the old authorization template can retain the remote script. Bundled templates use inline static CSS; strict CSPs need its hash/nonce or a host-provided local stylesheet.
- Refresh host route/config/view caches and restart long-lived workers through the host deployment process.
- Discovery reports code/S256 consistently with package endpoints. Host-owned extra endpoints and custom grants need separate review.
- Verify separate resource-server credentials and explicit introspection mappings before restoring traffic.
- Run the supported matrix and smoke-test authorization, logout confirmation, UserInfo, JWKS and real RP integration.
- Prefer forward repair: rollback reintroduces vulnerabilities and cannot restore ended sessions or revoked grants. Already-issued tokens keep their original expiry.

Deploy all authorization/token nodes together or temporarily drain issuance before switching traffic: old workers drop the new context on refresh. Do not run a mixed fleet or roll back individual nodes. Communicate the one-time code restart and legacy refresh response change to RP owners before rollout. Coordinate the release and Advisory publication privately with the reporter, after maintainer/RP acceptance; the local candidate is not a published fix.

Full authentication freshness and ID Token TTL activation remain [separate open work](security-follow-ups.md). This change keeps ID Token expiry equal to Access Token expiry.
