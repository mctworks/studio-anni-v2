<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpNotFoundException;

final class AccountsSwitchMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($this->config->get('ACCOUNTS_ENABLED') !== 'true') {
            throw new HttpNotFoundException($request);
        }

        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }
}
