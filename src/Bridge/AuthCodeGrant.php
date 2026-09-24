<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Bridge;

use Admin9\OidcServer\Services\AuthorizationContext;
use League\OAuth2\Server\Grant\AuthCodeGrant as PassportAuthCodeGrant;

class AuthCodeGrant extends PassportAuthCodeGrant
{
    // The untyped parameter supports League 8 (Passport 12) and League 9.
    protected function encrypt($unencryptedData): string
    {
        $payload = json_decode($unencryptedData, true, 512, JSON_THROW_ON_ERROR);
        $context = request()->attributes->get(AuthorizationContext::ATTRIBUTE);
        if (in_array('openid', $payload['scopes'], true)
            && is_array($context) && ($context['client_id'] ?? null) === $payload['client_id']
            && ($context['identity'][2] ?? null) === (string) $payload['user_id']) {
            $payload['oidc'] = $context;
        }

        return parent::encrypt(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
