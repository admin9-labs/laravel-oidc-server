# Security follow-ups

The existing advisory controls, refresh introspection, exact callbacks, nonce and identity binding remain required. The historical [1.2.2 guide](upgrading-to-1.2.2.md) describes that release's refusal to invent authentication time.

The 2.0 implementation introduces the explicit authentication contract and transaction-bound reauthentication specified in the [design](authentication-freshness-2.0.md). See the [host integration and upgrade guide](upgrading-to-2.0.0.md). Authentication time is not inferred from framework login events or restored sessions.

The [implementation evidence](implementation-2.0.md) records completed local package tests, compatibility runs, actual upstream SSO and independent RP checks, and full HTTP concurrency/fault-injection acceptance. The agreed local delivery excludes business-host deployment, which has its own explicit acceptance checklist.

Authentication/challenge binding, checks before code/token persistence and refresh revocation, and replay prevention across session ID rotation passed the local checks. Verify the host wiring and operational topology before production acceptance. Required storage must not roll back acknowledged state transitions; a missing positive record always fails closed.
