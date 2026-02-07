# Changelog

All notable changes to `admin9/laravel-oidc-server` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
