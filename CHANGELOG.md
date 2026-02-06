# Changelog

All notable changes to `laravel-oidc-server` will be documented in this file.

## [Unreleased]

### Changed
- **BREAKING**: Config key renamed from `oidc` to `oidc-server`. Update your published config file name and any `config('oidc.*')` references.
- Extracted `resolveNonce()` method in `TokenResponseType` for easier override/testing.
- Enhanced config comments for `userinfo_middleware`, `default_claims_map`, and `routes`.

### Added
- Event system: `OidcTokenIssued`, `OidcUserInfoRequested`, `OidcLogoutInitiated`.
- Input validation for `token_type_hint` on introspect/revoke endpoints (RFC 7662/7009).
- Security comment on `id_token_hint` parsing in logout endpoint.
- `.gitattributes` for cleaner package installs.
