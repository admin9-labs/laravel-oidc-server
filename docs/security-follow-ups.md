# Deferred OIDC work

The original Advisory security controls, refresh-token introspection and nonce binding are retained. v1.2.2 corrects false authentication-time claims by omitting auth_time and rejecting unsupported freshness requests. See the [upgrade guide](upgrading-to-1.2.2.md) for compatibility and rollout requirements.

## Full authentication freshness (planned for 2.0.0)

OIDC Core section 15.1 requires complete OP implementations to support auth_time and max_age. Deferral is a documented capability gap, not an optional standard feature. This release does not record authentication time or manage reauthentication itself.

Acceptance: trustworthy login time for the intended guard, credential/SSO and remembered-session boundaries, imported sessions with unknown time, unchanged auth_time across refresh, max_age including zero and expiry during consent, prompt=none without interaction, same-second login proof, multi-guard isolation, session regeneration and real RP integration. Define the host login contract and old-token migration before enabling the capability; never fabricate time or silently ignore freshness requirements.

The [2.0.0 authentication freshness proposal](authentication-freshness-2.0.md) sets out the intended host contract, transaction flow, compatibility rules, and release gates. It is a proposed design, not current package behavior.

The 2.0.0 design must retain the nonce and identity-binding guarantees from v1.2.2 while replacing Passport's native `prompt=login` session flow.

## ID Token TTL

`tokens.id_token_ttl` remains reserved and unused. ID Token expiry continues to follow the access token. Activating the setting is a separately versioned behavior change.

Acceptance: explicit/default/null/invalid values have documented behavior; assess published-config compatibility; test ID Token expiry independently of access-token expiry.
