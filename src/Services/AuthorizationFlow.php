<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Services;

use Admin9\OidcServer\Contracts\AuthenticationRecorder;
use Admin9\OidcServer\Contracts\ReauthenticationHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthorizationFlow
{
    public function __construct(
        private OidcAuthorizationServer $server,
        private AuthorizationTransactions $transactions,
        private AuthorizationContext $contexts,
        private AuthenticationRecorder $recorder,
    ) {}

    public function authorize(Request $request, ServerRequestInterface $psr): Response
    {
        $query = (string) $request->server->get('QUERY_STRING', '');
        parse_str($query, $parameters);
        if (($parameters['response_type'] ?? null) !== 'code') {
            return response()->json(['error' => 'unsupported_response_type'], 400);
        }
        foreach (['client_id', 'redirect_uri', 'scope', 'code_challenge', 'code_challenge_method'] as $name) {
            if (array_key_exists($name, $parameters) && ! is_string($parameters[$name])) {
                throw OAuthServerException::invalidRequest($name);
            }
        }
        // Validate against exact raw strings before constructing any redirect or transaction.
        $authorization = $this->server->validateAuthorizationRequest($psr->withQueryParams($parameters));
        $redirect = $authorization->getRedirectUri() ?? (array) $authorization->getClient()->getRedirectUri();
        $redirect = is_array($redirect) ? $redirect[0] : $redirect;
        $error = $this->contexts->parameterError($query, $parameters);
        if (isset($parameters['code_challenge']) && ($parameters['code_challenge_method'] ?? null) !== 'S256') {
            $error = 'PKCE requires S256.';
        }
        if ($error !== null) {
            return $this->error(['redirect_uri' => $redirect, 'state' => is_string($parameters['state'] ?? null) ? $parameters['state'] : null], 'invalid_request', $error);
        }
        $parameters = array_intersect_key($parameters, array_flip([
            'client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'nonce', 'code_challenge', 'code_challenge_method',
        ]));
        $parameters['client_id'] = $authorization->getClient()->getIdentifier();
        $parameters['redirect_uri'] = $redirect;
        $parameters['scope'] = implode(' ', array_map(fn ($scope) => $scope->getIdentifier(), $authorization->getScopes()));
        parse_str($query, $raw);
        $requirements = ['max_age' => array_key_exists('max_age', $raw) ? (int) $raw['max_age'] : null,
            'prompts' => preg_split('/ +/', trim($raw['prompt'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)];
        $id = $this->transactions->create($request, $parameters, $requirements, $authorization->getRedirectUri() !== null);
        $tx = $this->transactions->get($request, $id);
        $record = $this->recorder->current($tx['guard']);
        $needsAuthentication = $record === null || in_array('login', $requirements['prompts'], true)
            || $requirements['max_age'] === 0
            || ($requirements['max_age'] !== null && now()->timestamp - $record->authTime > $requirements['max_age']);
        if ($needsAuthentication) {
            return $this->reauthenticate($request, $tx);
        }
        $this->transactions->update($request, $id, function (array &$tx) use ($record): void {
            $tx['authentication_snapshot'] = $record->toArray();
        });

        return $this->advance($request, $id);
    }

    public function advance(Request $request, string $id, bool $approved = false): Response
    {
        $tx = $this->transactions->get($request, $id);
        [$authorization, $client, $scopes] = $this->registration($tx);
        $this->transactions->assertSnapshot($request, $tx);
        if ($this->transactions->expiredAuthentication($tx)) {
            return $this->reauthenticate($request, $tx);
        }
        $user = Auth::guard($tx['guard'])->user();
        if ($approved || (! in_array('consent', $tx['requirements']['prompts'], true) && $client->skipsAuthorization($user, $scopes))) {
            $authorization->setUser(new User((string) $user->getAuthIdentifier()));
            $authorization->setAuthorizationApproved(true);
            $request->attributes->set('oidc.transaction_id', $id);

            return $this->response($this->server->completeAuthorizationRequest($authorization, app(ResponseInterface::class)));
        }
        if (in_array('none', $tx['requirements']['prompts'], true)) {
            $this->transactions->finish($request, $id, 'failed');

            return $this->error($tx['authorization'], 'consent_required');
        }
        $approval = FreshnessSession::random();
        $this->transactions->update($request, $id, function (array &$tx) use ($approval): void {
            $tx['state'] = 'awaiting_consent';
            $tx['approval_token_hash'] = hash('sha256', $approval);
        });

        return response()->view(config('oidc-server.authorization_view', 'oidc-server::authorize'), [
            'client' => $client, 'user' => $user, 'scopes' => $scopes, 'transactionId' => $id, 'authToken' => $approval,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function beforeCode($authorization): void
    {
        $request = request();
        $id = $request->attributes->get('oidc.transaction_id');
        if (! is_string($id)) {
            throw OAuthServerException::invalidGrant('Missing authorization transaction.');
        }
        $tx = $this->transactions->get($request, $id);
        $this->registration($tx);
        $this->transactions->assertSnapshot($request, $tx);
        if ($this->transactions->expiredAuthentication($tx)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException($this->reauthenticate($request, $tx));
        }
        if ($authorization->getClient()->getIdentifier() !== $tx['authorization']['client_id']
            || $authorization->getRedirectUri() !== ($tx['redirect_uri_provided'] ? $tx['authorization']['redirect_uri'] : null)
            || (string) $authorization->getUser()?->getIdentifier() !== $tx['expected_identity'][2]
            || $authorization->getState() !== ($tx['authorization']['state'] ?? null)
            || $authorization->getCodeChallenge() !== ($tx['authorization']['code_challenge'] ?? null)
            || $authorization->getCodeChallengeMethod() !== ($tx['authorization']['code_challenge_method'] ?? null)
            || implode(' ', array_map(fn ($scope) => $scope->getIdentifier(), $authorization->getScopes())) !== $tx['authorization']['scope']
            || ($tx['challenge'] !== null && ! $tx['challenge']['consumed'])) {
            throw OAuthServerException::invalidGrant('Authorization proof is no longer valid.');
        }
        $context = ['v' => AuthorizationContext::VERSION, 'nonce' => $tx['authorization']['nonce'] ?? null,
            'identity' => $tx['expected_identity'], 'client_id' => $tx['authorization']['client_id'],
            'iss' => config('oidc-server.issuer', config('app.url')), 'sub' => $tx['expected_subject'],
            'authentication' => array_intersect_key($tx['authentication_snapshot'], array_flip(['auth_time', 'generation']))];
        if (! $this->contexts->validPayload(['oidc' => $context, 'client_id' => $context['client_id'], 'user_id' => $context['identity'][2]])) {
            throw OAuthServerException::invalidGrant('The original identity mapping has changed.');
        }
        // Atomic session revision consumes this issuance before League persists the code.
        $this->transactions->finish($request, $id, 'issued');
        $request->attributes->set(AuthorizationContext::ATTRIBUTE, $context);
    }

    public function deny(Request $request, string $id): Response
    {
        $tx = $this->transactions->get($request, $id);
        $this->registration($tx);
        $this->transactions->assertSnapshot($request, $tx);
        $this->transactions->finish($request, $id, 'denied');

        return $this->error($tx['authorization'], 'access_denied');
    }

    public function registration(array $tx): array
    {
        $parameters = $tx['authorization'];
        if (! $tx['redirect_uri_provided']) {
            unset($parameters['redirect_uri']);
        }
        try {
            $authorization = $this->server->validateAuthorizationRequest(app(ServerRequestInterface::class)->withQueryParams($parameters));
        } catch (OAuthServerException $exception) {
            // Registration may have changed during interaction; never redirect an error to a removed URI.
            $this->transactions->invalidate(request(), $tx['id']);
            throw new HttpException(400, 'The client registration changed. Restart authorization.');
        }
        $client = app(FreshClientRepository::class)->findActive($tx['authorization']['client_id']);
        $redirect = $authorization->getRedirectUri() ?? (array) $authorization->getClient()->getRedirectUri();
        $redirect = is_array($redirect) ? $redirect[0] : $redirect;
        if (! $client || $redirect !== $tx['authorization']['redirect_uri']) {
            $this->transactions->invalidate(request(), $tx['id']);
            throw new HttpException(400, 'The client registration changed. Restart authorization.');
        }
        // League normalizes query strings; state must be returned exactly as received.
        if (isset($tx['authorization']['state'])) {
            $authorization->setState($tx['authorization']['state']);
        }
        $scopes = Passport::scopesFor(array_map(fn ($scope) => $scope->getIdentifier(), $authorization->getScopes()));

        return [$authorization, $client, $scopes];
    }

    public function error(array $authorization, string $error, ?string $description = null): \Illuminate\Http\RedirectResponse
    {
        [$uri, $fragment] = array_pad(explode('#', $authorization['redirect_uri'], 2), 2, null);
        $parameters = ['error' => $error];
        if (array_key_exists('state', $authorization) && $authorization['state'] !== null) {
            $parameters['state'] = $authorization['state'];
        }
        if ($description !== null) {
            $parameters['error_description'] = $description;
        }

        return redirect()->away($uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($parameters).($fragment !== null ? '#'.$fragment : ''))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function reauthenticate(Request $request, array $tx): Response
    {
        if (in_array('none', $tx['requirements']['prompts'], true)) {
            $this->transactions->finish($request, $tx['id'], 'failed');

            return $this->error($tx['authorization'], 'login_required');
        }
        if (! app()->bound(ReauthenticationHandler::class)) {
            throw new HttpException(500, 'Bind an OIDC ReauthenticationHandler before accepting authorization requests.');
        }
        $challenge = $this->transactions->beginChallenge($request, $tx['id']);

        return app(ReauthenticationHandler::class)->redirect($request, $challenge)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function response(ResponseInterface $response): Response
    {
        return new Response((string) $response->getBody(), $response->getStatusCode(), $response->getHeaders() + ['Cache-Control' => 'no-store']);
    }
}
