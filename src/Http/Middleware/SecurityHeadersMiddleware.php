<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        $connect = trim("'self' " . $this->config->get('CSP_CONNECT_EXTRA', ''));
        $img = trim("'self' data: blob: " . $this->config->get('CSP_IMG_EXTRA', ''));
        $policy = "default-src 'self'; script-src 'self'; style-src 'self'; img-src {$img}; font-src 'self'; connect-src {$connect}; "
            . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
        $cspHeader = $this->config->get('CSP_MODE', 'report-only') === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

        $response = $response
            ->withHeader($cspHeader, $policy)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), usb=()');

        if ($this->config->get('HSTS_ENABLED') === 'true') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }

        $path = $request->getUri()->getPath();
        if ($path === '/api/mimosa' || str_starts_with($path, '/api/mimosa/')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }
}
