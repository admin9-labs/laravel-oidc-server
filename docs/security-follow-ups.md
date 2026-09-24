# Deferred OIDC work

The original Advisory security controls, refresh-token introspection and nonce binding are retained. v1.2.2 corrects false authentication-time claims by omitting auth_time and rejecting unsupported freshness requests. See the [upgrade guide](upgrading-to-1.2.2.md) for compatibility and rollout requirements.

## Full authentication freshness (separate future release)

OIDC Core section 15.1 requires complete OP implementations to support auth_time and max_age. Deferral is a documented capability gap, not an optional standard feature. This release does not record authentication time or manage reauthentication itself.

Acceptance: trustworthy login time for the intended guard, credential/SSO and remembered-session boundaries, imported sessions with unknown time, unchanged auth_time across refresh, max_age including zero and expiry during consent, prompt=none without interaction, same-second login proof, multi-guard isolation, session regeneration and real RP integration. Define the host login contract and old-token migration before enabling the capability; never fabricate time or silently ignore freshness requirements.

The earlier AuthenticationSession/generation/remember prototype and its regression cases remain design references only, not an implementation to restore wholesale. Any future work must use the current v2 nonce/identity context and account for native Passport prompt behavior. No future release number is assigned.

## ID Token TTL

`tokens.id_token_ttl` remains reserved and unused. ID Token expiry continues to follow the access token. Activating the setting is a separately versioned behavior change.

Acceptance: explicit/default/null/invalid values have documented behavior; assess published-config compatibility; test ID Token expiry independently of access-token expiry.
