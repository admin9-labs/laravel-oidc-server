<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\OidcUserInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationContext
{
    public const ATTRIBUTE = 'oidc.authorization_context';

    public const VERSION = 2;

    public function prepare(Request $request): ?Response
    {
        // Let Passport validate the client and callback before returning protocol errors.
        try {
            $authorization = app(AuthorizationServer::class)->validateAuthorizationRequest(app(ServerRequestInterface::class));
        } catch (OAuthServerException $exception) {
            $response = $exception->generateHttpResponse(app(ResponseInterface::class));

            return new Response((string) $response->getBody(), $response->getStatusCode(), $response->getHeaders());
        }

        // Preserve protocol strings before TrimStrings/ConvertEmptyStringsToNull.
        $query = (string) $request->server->get('QUERY_STRING', '');
        parse_str($query, $parameters);
        if ($error = $this->parameterError($query, $parameters)) {
            $redirect = $authorization->getRedirectUri() ?? (array) $authorization->getClient()->getRedirectUri();
            $redirect = is_array($redirect) ? $redirect[0] : $redirect;

            return redirect()->away($redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query([
                'error' => 'invalid_request', 'error_description' => $error,
                'state' => is_string($parameters['state'] ?? null) ? $parameters['state'] : $authorization->getState(),
            ]))->header('Cache-Control', 'no-store');
        }

        // Passport owns guest authentication and prompt=login, including its session policy.
        $guard = config('passport.guard') ?? Auth::getDefaultDriver();
        $user = Auth::guard($guard)->user();
        $request->attributes->set(self::ATTRIBUTE, [
            'v' => self::VERSION, 'nonce' => $parameters['nonce'] ?? null,
            'identity' => [$guard, $user ? get_class($user) : null, $user ? (string) $user->getAuthIdentifier() : null],
            'client_id' => $authorization->getClient()->getIdentifier(),
            'iss' => config('oidc-server.issuer', config('app.url')),
            'sub' => $user instanceof OidcUserInterface ? $user->getOidcSubject() : null,
        ]);

        return null;
    }

    protected function parameterError(string $query, array $parameters): ?string
    {
        $seen = [];
        $separator = preg_quote(ini_get('arg_separator.input') ?: '&', '/');
        foreach (preg_split('/['.$separator.']/', $query) as $pair) {
            $key = urldecode(explode('=', $pair, 2)[0]);
            parse_str($pair, $parsed);
            foreach (['nonce', 'max_age', 'prompt', 'claims'] as $name) {
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
            return 'max_age is not supported in this release.';
        }
        $nonce = $parameters['nonce'] ?? null;
        $prompt = $parameters['prompt'] ?? '';
        if (($nonce !== null && (! is_string($nonce) || ! mb_check_encoding($nonce, 'UTF-8'))) || ! is_string($prompt)) {
            return 'Invalid nonce or prompt parameter.';
        }
        $prompts = preg_split('/ +/', trim($prompt), -1, PREG_SPLIT_NO_EMPTY);
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
                if ($name === 'auth_time' && ($options->essential ?? false)) {
                    return 'Essential auth_time is not supported in this release.';
                }
            }
        }

        return null;
    }

    public function forToken(AccessTokenEntityInterface $token): ?array
    {
        $field = match (request()->input('grant_type')) {
            'authorization_code' => 'code',
            'refresh_token' => 'refresh_token',
            default => null,
        };
        $value = $field ? request()->input($field) : null;
        $cacheKey = 'oidc.token_context.'.hash('sha256', serialize([
            $field, $value, $token->getIdentifier(), $token->getClient()->getIdentifier(), $token->getUserIdentifier(),
        ]));
        if (request()->attributes->has($cacheKey)) {
            return request()->attributes->get($cacheKey);
        }
        $payload = is_string($value) ? app(TokenVerifier::class)->encryptedPayload($value) : null;
        $context = $payload['oidc'] ?? null;
        if (! is_array($context) || ! $this->validPayload($payload)
            || ($payload['client_id'] ?? null) !== $token->getClient()->getIdentifier()
            || (string) ($payload['user_id'] ?? '') !== (string) $token->getUserIdentifier()) {
            return null;
        }
        // The response needs the same identity context for its refresh envelope
        // and nonce. Decrypt once per token in this request, never across requests.
        request()->attributes->set($cacheKey, $context);

        return $context;
    }

    public function validPayload(array $payload): bool
    {
        $context = $payload['oidc'] ?? null;
        $valid = is_array($context) && ($context['v'] ?? null) === self::VERSION
            && array_key_exists('nonce', $context) && ($context['nonce'] === null || is_string($context['nonce']))
            && array_diff(array_keys($context), ['v', 'nonce', 'identity', 'client_id', 'iss', 'sub']) === []
            && is_array($context['identity'] ?? null) && count($context['identity']) === 3
            && is_string($context['identity'][0] ?? null) && is_string($context['identity'][1] ?? null)
            && ($context['identity'][2] ?? null) === (string) ($payload['user_id'] ?? '')
            && ($context['client_id'] ?? null) === ($payload['client_id'] ?? null)
            && is_string($context['iss'] ?? null) && is_string($context['sub'] ?? null);
        if (! $valid) {
            return false;
        }
        $guard = config('passport.guard') ?? config('auth.defaults.guard');
        $provider = config('auth.guards.'.$guard.'.provider');
        $model = config('oidc-server.user_model') ?? config('auth.providers.'.$provider.'.model');
        if ($context['identity'][0] !== $guard
            || $context['iss'] !== config('oidc-server.issuer', config('app.url'))) {
            return false;
        }
        $user = $model::find($context['identity'][2]);

        return $user instanceof OidcUserInterface && $context['identity'][1] === get_class($user)
            && $context['sub'] === $user->getOidcSubject();
    }
}
