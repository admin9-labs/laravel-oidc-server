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
    */
    'routes' => [
        'enabled' => true,
        'discovery_middleware' => [],
        'token_middleware' => [],
        'userinfo_middleware' => ['auth:api'],
    ],
];
