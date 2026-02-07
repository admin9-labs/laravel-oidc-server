# Troubleshooting

This guide covers common errors and their solutions when working with the OIDC server.

## Missing Passport Keys

**Error**: `LogicException: Unable to read key from file` or `file_get_contents(storage/oauth-public.key): failed to open stream`

**Cause**: Passport RSA keys have not been generated.

**Solution**:
```bash
php artisan passport:keys
```

This creates `storage/oauth-private.key` and `storage/oauth-public.key`. Ensure they are readable by the web server and excluded from version control.

## Discovery Endpoint Returns 404

**Cause**: Package routes are not registered.

**Check**:
1. Verify the service provider is auto-discovered: `php artisan package:discover`
2. Check `config/oidc-server.php` has `'routes' => ['enabled' => true]`
3. Clear route cache: `php artisan route:clear`

## id_token Not Returned in Token Response

**Cause**: The `openid` scope was not requested.

**Solution**: Ensure the client includes `openid` in the `scope` parameter:
```
GET /oauth/authorize?scope=openid+profile+email&...
```

Also verify the User model implements `OidcUserInterface`:
```php
class User extends Authenticatable implements OidcUserInterface
{
    use HasOidcClaims;
}
```

## UserInfo Returns Empty Claims

**Cause**: Claims are not resolving for the requested scopes.

**Check**:
1. Verify `config/oidc-server.php` `scopes` section maps scopes to claims
2. Verify `default_claims_map` maps claim names to model attributes
3. If using custom claims, check `claims_resolver` config or `resolveOidcClaim()` override

## Introspect/Revoke Returns 401

**Cause**: Client authentication failed.

**Check**:
1. Verify `client_id` and `client_secret` are correct
2. Try both auth methods:
   - Basic Auth: `Authorization: Basic base64(client_id:client_secret)`
   - POST body: `client_id=...&client_secret=...`
3. Ensure the client is not a public client (public clients cannot authenticate)

## Logout Does Not Redirect Back

**Cause**: `post_logout_redirect_uri` validation failed.

**Check**:
1. The URI must match a registered redirect URI for the client (scheme + host + port)
2. Provide `id_token_hint` so the server can identify the client and validate against its registered redirect URIs
3. Without `id_token_hint`, only URIs matching `config('app.url')` are allowed

## Passport Routes Conflict

**Cause**: Both Passport and this package register routes.

**Solution**: The package calls `Passport::ignoreRoutes()` by default. If you need Passport's own routes:

```php
// config/oidc-server.php
'ignore_passport_routes' => false,
```

## Token TTL Not Taking Effect

**Cause**: Config cache is stale.

**Solution**:
```bash
php artisan config:clear
```

Or set via environment variables:
```env
OIDC_ACCESS_TOKEN_TTL=900
OIDC_REFRESH_TOKEN_TTL=604800
OIDC_ID_TOKEN_TTL=900
```
