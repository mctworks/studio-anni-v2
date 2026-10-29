<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionManager $sessions)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $incoming = $request->getCookieParams()[$this->sessions->cookieName()] ?? null;
        $incoming = is_string($incoming) ? $incoming : null;

        $this->sessions->start($incoming);

        return $this->sessions->end($handler->handle($request), $incoming);
    }
}
