<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OIDC Issuer
    |--------------------------------------------------------------------------
    */
    'issuer' => env('OIDC_ISSUER', env('APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model class used to look up users when generating ID tokens.
    | Falls back to the default auth provider model if not set.
    |
    */
    'user_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Passport Auto-Configuration
    |--------------------------------------------------------------------------
    |
    | When true, the package will automatically configure Passport scopes,
    | token TTLs, response type, client model, and authorization view.
    | Set to false if you want to configure Passport yourself.
    |
    */
    'configure_passport' => true,

    /*
    |--------------------------------------------------------------------------
    | Ignore Passport Routes
    |--------------------------------------------------------------------------
    |
    | When true, the package will call Passport::ignoreRoutes() to prevent
    | Passport from registering its own routes. Set to false if you need
    | Passport's default routes alongside the OIDC routes.
    |
    */
    'ignore_passport_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Authorization View
    |--------------------------------------------------------------------------
    |
    | The Blade view used for the OAuth authorization prompt.
    | Publish and customize, or point to your own view.
    |
    */
    'authorization_view' => 'oidc-server::authorize',

    /*
    |--------------------------------------------------------------------------
    | Client Model
    |--------------------------------------------------------------------------
    |
    | The Passport Client model class. The default OidcClient skips the
    | authorization prompt for first-party clients.
    |
    */
    'client_model' => \Admin9\OidcServer\Models\OidcClient::class,

    /*
    |--------------------------------------------------------------------------
    | Supported Scopes
    |--------------------------------------------------------------------------
    */
    'scopes' => [
        'openid' => [
            'description' => 'OpenID Connect authentication',
            'claims' => ['sub'],
        ],
        'profile' => [
            'description' => 'Access user profile information',
            'claims' => ['name', 'nickname', 'picture', 'updated_at'],
        ],
        'email' => [
            'description' => 'Access user email address',
            'claims' => ['email', 'email_verified'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Scopes
    |--------------------------------------------------------------------------
    */
    'default_scopes' => ['openid'],

    /*
    |--------------------------------------------------------------------------
    | Claims Resolver
    |--------------------------------------------------------------------------
    |
    | Map claim names to model attributes or callables.
    | Example: 'nickname' => 'public_name'
    | Example: 'picture' => fn($user) => $user->avatar_url
    |
    */
    'claims_resolver' => [],

    /*
    |--------------------------------------------------------------------------
    | Default Claims Map
    |--------------------------------------------------------------------------
    |
    | Map claim names to model attributes or callables for the default
    | resolution in HasOidcClaims. These are used when no claims_resolver
    | entry exists for a claim. Override to match your User model's schema.
    |
    | String values are treated as model attribute names (e.g., 'name' => 'name').
    | Callables receive the user model as the first argument:
    |   'email_verified' => fn($user) => $user->email_verified_at !== null
    |
    */
    'default_claims_map' => [
        'name' => 'name',
        'email' => 'email',
        'email_verified' => fn ($user) => $user->email_verified_at !== null,
        'updated_at' => fn ($user) => $user->updated_at?->timestamp,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Configuration
    |--------------------------------------------------------------------------
    */
    'tokens' => [
        'access_token_ttl' => (int) env('OIDC_ACCESS_TOKEN_TTL', 900),
        'refresh_token_ttl' => (int) env('OIDC_REFRESH_TOKEN_TTL', 604800),
        'id_token_ttl' => (int) env('OIDC_ID_TOKEN_TTL', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Response Types
    |--------------------------------------------------------------------------
    */
    'response_types_supported' => [
        'code',
        'token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Grant Types
    |--------------------------------------------------------------------------
    */
    'grant_types_supported' => [
        'authorization_code',
        'refresh_token',
        'client_credentials',
        'urn:ietf:params:oauth:grant-type:device_code',
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Endpoint Auth Methods
    |--------------------------------------------------------------------------
    */
    'token_endpoint_auth_methods_supported' => [
        'client_secret_basic',
        'client_secret_post',
    ],

    /*
    |--------------------------------------------------------------------------
    | ID Token Signing Algorithms
    |--------------------------------------------------------------------------
    */
    'id_token_signing_alg_values_supported' => [
        'RS256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject Types
    |--------------------------------------------------------------------------
    */
    'subject_types_supported' => [
        'public',
    ],

    /*
    |--------------------------------------------------------------------------
    | PKCE Code Challenge Methods
    |--------------------------------------------------------------------------
    */
    'code_challenge_methods_supported' => [
        'S256',
        'plain',
    ],

    /*
    |--------------------------------------------------------------------------
    | Post Logout Redirect URIs
    |--------------------------------------------------------------------------
    */
    'post_logout_redirect_uris_supported' => [],

    /*
    |--------------------------------------------------------------------------
    | Routes Configuration
    |--------------------------------------------------------------------------
    |
    | Control route registration and per-group middleware.
    | - discovery_middleware: Applied to /.well-known/* endpoints
    | - token_middleware: Applied to /oauth/token, introspect, revoke, logout
    | - userinfo_middleware: Applied to /oauth/userinfo (default: auth:api,
    |   which requires a valid Passport access token)
    |
    */
    'routes' => [
        'enabled' => true,
        'discovery_middleware' => [],
        'token_middleware' => [],
        'userinfo_middleware' => ['auth:api'],
    ],
];
