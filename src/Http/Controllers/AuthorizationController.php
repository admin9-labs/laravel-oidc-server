<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Http\Controllers;

use Admin9\OidcServer\Services\AuthorizationFlow;
use Admin9\OidcServer\Services\AuthorizationTransactions;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationController
{
    public function __construct(private AuthorizationFlow $flow, private AuthorizationTransactions $transactions) {}

    public function authorize(Request $request, ServerRequestInterface $psr): Response
    {
        return $this->respond(fn () => $this->flow->authorize($request, $psr));
    }

    public function approve(Request $request): Response
    {
        $id = $this->credential($request, 'transaction');
        $this->transactions->consume($request, $id, $this->credential($request, 'auth_token'), 'approval');

        return $this->respond(fn () => $this->flow->advance($request, $id, true));
    }

    public function deny(Request $request): Response
    {
        $id = $this->credential($request, 'transaction');
        $this->transactions->consume($request, $id, $this->credential($request, 'auth_token'), 'approval');

        return $this->respond(fn () => $this->flow->deny($request, $id));
    }

    public function resume(Request $request): Response
    {
        $id = $this->credential($request, 'transaction');
        $this->transactions->consume($request, $id, $this->credential($request, 'resume'), 'resume');

        return $this->respond(fn () => $this->flow->advance($request, $id));
    }

    private function credential(Request $request, string $name): string
    {
        $value = $request->input($name);
        abort_unless(is_string($value) && preg_match('/\\A[a-f0-9]{64}\\z/', $value), 400, 'Invalid authorization credential.');

        return $value;
    }

    private function respond(callable $operation): Response
    {
        try {
            return $operation();
        } catch (OAuthServerException $exception) {
            $response = $exception->generateHttpResponse(app(ResponseInterface::class));

            return new Response((string) $response->getBody(), $response->getStatusCode(), $response->getHeaders());
        }
    }
}
