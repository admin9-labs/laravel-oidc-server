<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Http\Middleware;

use Admin9\OidcServer\Services\AuthorizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceAuthorizationPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('HEAD')) {
            return response('', 200);
        }
        // Request attributes, never mutable singleton/grant fields, own issuance context.
        foreach ([AuthorizationContext::ATTRIBUTE, AuthorizationContext::TOKEN_ATTRIBUTE, 'oidc.code_payload', 'oidc.transaction_id'] as $attribute) {
            $request->attributes->remove($attribute);
        }
        if ($request->route()->getActionMethod() === 'issueToken') {
            if (! in_array($request->input('grant_type'), ['authorization_code', 'refresh_token', 'client_credentials'], true)) {
                return response()->json(['error' => 'unsupported_grant_type'], 400);
            }
        } elseif (! $request->hasSession() || in_array(config('session.driver'), ['cookie', 'array', null], true)) {
            return response()->json(['error' => 'server_error', 'error_description' => 'OIDC requires a shared server-side session store and session blocking.'], 503);
        }

        return $next($request);
    }
}
