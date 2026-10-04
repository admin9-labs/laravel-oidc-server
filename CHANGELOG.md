# Changelog

All notable changes to `admin9/laravel-oidc-server` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-04

### Changed

- Promote the accepted `2.0.0-rc.1` runtime unchanged to stable. The host authentication contract, shared Redis state, supported Laravel/Passport combinations and breaking changes below remain in force.
- Require coordinated host integration and rollout: old code/refresh formats have no compatibility path, and existing access tokens/database records are not automatically revoked. No package database migration is added.
- Publish [stable release scope and known limits](docs/releasing-2.0.0.md), including fixed-RC host installation acceptance and the completed minimal real Member-to-RP identity verification. These do not certify full host test suites, every deployment topology or business member login integration.

See the [English](docs/upgrading-to-2.0.0.md) / [中文](docs/zh-CN/upgrading-to-2.0.0.md) upgrade guides before adopting 2.0. The final stable commit requires its own remote CI/dist gates and exact Packagist installation verification; immutable evidence is attached to the GitHub Release.

## [2.0.0-rc.1] - 2026-10-04

### Changed

- 2.0 requires explicit host authentication records for every authorization-code transaction, including requests without `openid`, and a host handler for challenge-bound reauthentication when needed. Authentication time is frozen in the code and preserved through refresh rotation.
- Shared server-side sessions, session blocking and authoritative Redis atomic state are required. A Redis restart or promotion changes `run_id`, invalidating old freshness session state and outstanding code/refresh credentials, including previously issued refresh tokens. Affected clients must authorize again; Redis Cluster and other topologies require separate acceptance.
- Package token endpoints support only authorization code, refresh token and client credentials. Password, device and custom user grants are rejected. Retained Passport authorization/token URLs, including custom prefixes with `ignore_passport_routes=false`, are protected package aliases; independent host OAuth requires its own controller, native server/response and envelope encryption key.
- Old code/refresh formats are rejected with `invalid_grant`, with no conversion or compatibility refresh path. Format rejection and Redis state loss do not automatically revoke existing database token records or already issued access tokens. No package database migration is required; rollback must gate both new authorization and retries of unrevoked legacy refresh tokens.

See the [English](docs/upgrading-to-2.0.0.md) / [中文](docs/zh-CN/upgrading-to-2.0.0.md) integration guides for host hooks, storage, coordinated cutover and rollback requirements. Local package checks and separate business-host acceptance are tracked in the [implementation evidence](docs/implementation-2.0.md).

### Fixed

- Make shipped default claim resolvers serializable for Laravel configuration caching while preserving email verification and update-time semantics. Previously published closures need replacement before `config:cache`.
- Avoid a private test-helper collision with current Testbench's public `query()` method; retain every authentication-freshness assertion.

- Return `invalid_request` for missing, blank or non-string refresh parameters while preserving client authentication precedence and `invalid_grant` for invalid credentials.
- Verify the exact `consent_required` response, callback/state preservation and absence of new credentials during silent authorization requiring consent.
- Update English/Chinese configuration guidance for authentication orchestration, retained routes, the freshness Redis connection and client credentials scope semantics.

### Added

- Remote `2.0` push gates for the seven-combination PHPUnit/Pest matrix and the existing real upstream SSO runner, with sanitized artifacts.
- A fixed-commit, public GitHub dist installation into an independent Laravel 13 host without package development or local path dependencies; validates platform requirements, exact archive content, provider discovery, cached configuration/routes, discovery/JWKS and the distributed consent view.
- Frozen RC scope and installation/release gates in [candidate release guidance](docs/releasing-2.0-rc.md).

## [1.2.2] - 2026-09-24

### Security

- Authenticate and authorize introspection, validate token material and client status, and preserve Passport 12/13 key and secret compatibility.
- Separate RP logout from CSRF-protected confirmation, bind hints to the current OIDC user, and exactly match registered redirect URIs.
- Require explicit consent by default, including historical automatic grants; enforce code/S256 at package endpoints.
- Restrict email disclosure and remove third-party scripts from the authorization view.
- Add security regressions and an explicit Laravel 11/12/13 and Passport 12/13 matrix.
- Bind nonce to the original encrypted authorization code; omit auth_time and explicitly reject unsupported max_age/Essential auth_time requests. Keep prompt=login under native Passport behavior.
- Reject pre-upgrade OIDC codes and omit ID Tokens when refreshing legacy grants without authentication context; document coordinated rollout and custom integration requirements.

See the [upgrade and historical-token procedure](docs/upgrading-to-1.2.2.md).
No database schema changes are required. Full authentication freshness and ID Token TTL activation remain
[separate open work](docs/security-follow-ups.md).

## [1.2.0] - 2026-06-30

### Added
- Laravel 13 support validation with CI coverage for Laravel 11, 12, and 13.
- GitHub Actions test matrix for PHPUnit and Pest across supported Laravel versions.

### Changed
- Expanded development dependency constraints to support Orchestra Testbench 11, Pest 4, and PHPUnit 12.
- Updated English and Chinese README requirements to list Laravel 11, 12, and 13.

## [1.1.1] - 2026-02-07

### Added
- Complete Chinese translation of all documentation in `docs/zh-CN/`
- Language switcher in root README.md for easy navigation between English and Chinese docs

### Changed
- Improved quick start guide with clearer step-by-step instructions
- Enhanced documentation consistency across all files
- Standardized document introductions and link formats
- Added installation verification URL in README

## [1.1.0] - 2026-02-07

### Added
- Unit tests for `IdTokenService` and `TokenResponseType`.
- Feature tests for OIDC endpoints (UserInfo, Introspect, Revoke, Logout) and claims resolution.
- `CONTRIBUTING.md` with development setup and contribution guidelines.
- `declare(strict_types=1)` to all PHP source files.
- `authors`, `homepage`, `support` fields to `composer.json`.

### Changed
- `composer.json`: `minimum-stability` from `dev` to `stable`.
- Removed all `Log::*` calls from `OidcController` — logging is the host application's responsibility; use events instead.

### Fixed
- Open redirect vulnerability in `isValidPostLogoutUri()` — path prefix matching now requires exact match or trailing `/`.
- Basic Auth parsing in `authenticateClient()` — added strict base64 decode validation and URL-decoding of credentials per RFC 6749.
- README: incorrect config path reference (`config/oidc.php` → `config/oidc-server.php`).
- `docs/configuration.md`: incorrect publish tag (`--tag=oidc-config` → `--tag=oidc-server-config`).
- `docs/configuration.md`: `token_middleware` description now lists all four covered endpoints.
- `docs/configuration.md`: `id_token_ttl` marked as reserved (not yet used in code).
- `docs/troubleshooting.md`: corrected logout redirect troubleshooting advice to match actual validation logic.
- `docs/architecture.md`: `exp` claim source corrected to "Access token expiry".

## [1.0.0] - 2026-02-07

### Added
- OIDC Discovery endpoint (`/.well-known/openid-configuration`).
- JWKS (JSON Web Key Set) endpoint for public key distribution.
- UserInfo endpoint with configurable middleware.
- Token Introspection endpoint per RFC 7662 with `token_type_hint` validation.
- Token Revocation endpoint per RFC 7009 with `token_type_hint` validation.
- RP-Initiated Logout endpoint with `id_token_hint` support.
- Automatic `id_token` injection into Passport token responses via `TokenResponseType`.
- `IdTokenService` for signing ID tokens with RSA keys from Passport.
- `ClaimsService` for resolving OIDC standard claims (profile, email, phone, address).
- `HasOidcClaims` trait and `OidcUserInterface` contract for User model integration.
- Configurable `default_claims_map` for mapping OIDC claims to User model attributes.
- Configurable `user_model` option with fallback to `auth.providers.users.model`.
- Configurable `ignore_passport_routes` option for conditional Passport route registration.
- Event system: `OidcTokenIssued`, `OidcUserInfoRequested`, `OidcLogoutInitiated`.
- `OidcClient` model extending Passport Client with OIDC-specific attributes.
- Publishable configuration file (`config/oidc-server.php`).
- Blade view for the authorize prompt.
- Documentation for architecture, configuration, endpoints, claims resolution, extension points, and troubleshooting.

### Changed
- **BREAKING:** Config key renamed from `oidc` to `oidc-server`. Update published config filename and all `config('oidc.*')` references accordingly.
- Extracted `resolveNonce()` method in `TokenResponseType` for improved testability.
- Enhanced config comments for `userinfo_middleware` and `default_claims_map`.

### Fixed
- Added `defuse/php-encryption` as an explicit Composer dependency.
