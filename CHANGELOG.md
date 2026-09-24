# Changelog

All notable changes to `admin9/laravel-oidc-server` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
