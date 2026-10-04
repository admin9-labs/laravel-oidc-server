<?php

declare(strict_types=1);

namespace Admin9\OidcServer\Http\Controllers;

use Admin9\OidcServer\Services\OidcAuthorizationServer;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AccessTokenController
{
    use ConvertsPsrResponses;

    public function __construct(private OidcAuthorizationServer $server) {}

    public function issueToken(ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        try {
            return $this->convertResponse($this->server->respondToAccessTokenRequest($psrRequest, $psrResponse));
        } catch (OAuthServerException $exception) {
            return $this->convertResponse($exception->generateHttpResponse($psrResponse));
        }
    }
}
