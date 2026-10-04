<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

class AuthorizationContext
{
    public const ATTRIBUTE = 'oidc.authorization_context';

    public const VERSION = 3;

    public const TOKEN_ATTRIBUTE = 'oidc.verified_token_context';

    public function parameterError(string $query, array $parameters): ?string
    {
        $seen = [];
        $separator = preg_quote(ini_get('arg_separator.input') ?: '&', '/');
        foreach (preg_split('/['.$separator.']/', $query) as $pair) {
            $key = urldecode(explode('=', $pair, 2)[0]);
            parse_str($pair, $parsed);
            foreach (['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'nonce', 'max_age', 'prompt', 'claims', 'code_challenge', 'code_challenge_method'] as $name) {
                if (! array_key_exists($name, $parsed)) {
                    continue;
                }
                // Reject duplicates and PHP's normalized aliases (dots, spaces, brackets, NULs).
                if ($key !== $name || isset($seen[$name])) {
                    return 'Invalid or repeated '.$name.' parameter.';
                }
                $seen[$name] = true;
            }
        }
        if (array_key_exists('max_age', $parameters)) {
            $age = $parameters['max_age'];
            if (! is_string($age) || ! preg_match('/\A[0-9]+\z/', $age)) {
                return 'max_age must be a non-negative decimal integer.';
            }
            $normalized = ltrim($age, '0') ?: '0';
            if (strlen($normalized) > strlen((string) PHP_INT_MAX)
                || (strlen($normalized) === strlen((string) PHP_INT_MAX) && strcmp($normalized, (string) PHP_INT_MAX) > 0)) {
                return 'max_age is too large.';
            }
        }
        if (isset($parameters['state']) && ! is_string($parameters['state'])) {
            return 'state must be a string.';
        }
        $nonce = $parameters['nonce'] ?? null;
        $prompt = $parameters['prompt'] ?? '';
        if (($nonce !== null && (! is_string($nonce) || ! mb_check_encoding($nonce, 'UTF-8'))) || ! is_string($prompt)) {
            return 'Invalid nonce or prompt parameter.';
        }
        $prompts = preg_split('/ +/', trim($prompt), -1, PREG_SPLIT_NO_EMPTY);
        if (array_diff($prompts, ['none', 'login', 'consent']) !== [] || count(array_unique($prompts)) !== count($prompts)) {
            return 'Unsupported or repeated prompt value.';
        }
        if (in_array('none', $prompts, true) && count($prompts) > 1) {
            return 'prompt=none cannot be combined with another prompt.';
        }
        if (! array_key_exists('claims', $parameters)) {
            return null;
        }
        if (! is_string($parameters['claims'])) {
            return 'claims must be a JSON object.';
        }
        try {
            $claims = json_decode($parameters['claims'], false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'claims must be a JSON object.';
        }
        if (! $claims instanceof \stdClass) {
            return 'claims must be a JSON object.';
        }
        foreach (['id_token', 'userinfo'] as $section) {
            if (! property_exists($claims, $section)) {
                continue;
            }
            if (! $claims->{$section} instanceof \stdClass) {
                return 'Invalid claims section.';
            }
            foreach ($claims->{$section} as $name => $options) {
                if ($options === null) {
                    continue;
                }
                if (! $options instanceof \stdClass
                    || (property_exists($options, 'essential') && ! is_bool($options->essential))) {
                    return 'Invalid claim requirements.';
                }
                if ($name === 'auth_time' && (array_diff(array_keys((array) $options), ['essential']) !== []
                    || ($section === 'userinfo' && ($options->essential ?? false)))) {
                    return 'Unsupported auth_time claim constraint. Request auth_time in id_token using essential or null.';
                }
            }
        }

        return null;
    }

    public function forToken(AccessTokenEntityInterface $token): ?array
    {
        $context = request()->attributes->get(self::TOKEN_ATTRIBUTE);
        if (! is_array($context) || $context['client_id'] !== $token->getClient()->getIdentifier()
            || $context['identity'][2] !== (string) $token->getUserIdentifier()) {
            return null;
        }

        return $context;
    }

    public function validateTokenPayload(array $payload): array
    {
        if (! $this->validPayload($payload)) {
            throw OAuthServerException::invalidGrant('Invalid authentication context. Restart authorization.');
        }
        request()->attributes->set(self::TOKEN_ATTRIBUTE, $payload['oidc']);

        return $payload['oidc'];
    }

    public function decodeEnvelope(string $json, string $kind): array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw OAuthServerException::invalidGrant('Invalid token envelope.');
        }
        if (! is_array($payload) || ! is_string($payload['client_id'] ?? null)
            || ! is_string($payload['user_id'] ?? null) || ! is_int($payload['expire_time'] ?? null)
            || ! is_array($payload['scopes'] ?? null) || ! array_is_list($payload['scopes'])) {
            throw OAuthServerException::invalidGrant('Invalid token envelope.');
        }
        foreach ($payload['scopes'] as $scope) {
            if (! is_string($scope) || $scope === '') {
                throw OAuthServerException::invalidGrant('Invalid token scope.');
            }
        }
        foreach ($kind === 'code' ? ['auth_code_id'] : ['access_token_id', 'refresh_token_id'] as $key) {
            if (! is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw OAuthServerException::invalidGrant('Invalid token identifier.');
            }
        }
        if ($kind === 'code' && (! array_key_exists('redirect_uri', $payload)
            || ($payload['redirect_uri'] !== null && ! is_string($payload['redirect_uri']))
            || (isset($payload['code_challenge']) && (! is_string($payload['code_challenge']) || ($payload['code_challenge_method'] ?? null) !== 'S256')))) {
            throw OAuthServerException::invalidGrant('Invalid authorization code binding.');
        }

        return $payload;
    }

    public function validPayload(array $payload): bool
    {
        $context = $payload['oidc'] ?? null;
        if (! is_array($context) || ($context['v'] ?? null) !== self::VERSION
            || ! $this->keys($context, ['v', 'nonce', 'identity', 'client_id', 'iss', 'sub', 'authentication'])
            || ($context['nonce'] !== null && (! is_string($context['nonce']) || ! mb_check_encoding($context['nonce'], 'UTF-8')))
            || ! is_array($context['identity']) || ! array_is_list($context['identity']) || count($context['identity']) !== 3
            || ! is_string($context['client_id']) || $context['client_id'] === ''
            || $context['client_id'] !== ($payload['client_id'] ?? null)
            || ! is_string($context['iss']) || ! is_string($context['sub']) || $context['sub'] === '') {
            return false;
        }
        foreach ($context['identity'] as $value) {
            if (! is_string($value) || $value === '') {
                return false;
            }
        }
        $authentication = $context['authentication'];
        if (! is_array($authentication) || ! $this->keys($authentication, ['auth_time', 'generation'])
            || ! is_int($authentication['auth_time']) || $authentication['auth_time'] < 0 || $authentication['auth_time'] > now()->timestamp
            || ! is_string($authentication['generation']) || ! preg_match('/\A[a-f0-9]{64}\z/', $authentication['generation'])
            || (! is_string($payload['user_id'] ?? null) && ! is_int($payload['user_id'] ?? null))
            || $context['identity'][2] !== (string) $payload['user_id']) {
            return false;
        }
        $guard = config('passport.guard') ?? config('auth.defaults.guard');
        $provider = config('auth.guards.'.$guard.'.provider');
        $providerModel = config('auth.providers.'.$provider.'.model');
        $model = config('oidc-server.user_model') ?? $providerModel;
        if (! is_string($model) || ! class_exists($model) || ! is_string($providerModel) || ! class_exists($providerModel)
            || (new \ReflectionClass($model))->getName() !== (new \ReflectionClass($providerModel))->getName()
            || $context['identity'][0] !== $guard || $context['iss'] !== config('oidc-server.issuer', config('app.url'))) {
            return false;
        }
        $user = $model::find($context['identity'][2]);

        return $user instanceof OidcUserInterface && $context['identity'][1] === get_class($user)
            && $context['identity'][2] === (string) $user->getAuthIdentifier()
            && $context['sub'] === $user->getOidcSubject();
    }

    private function keys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }
}
