<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Auth\Csrf;
use App\Config;
use App\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responses,
        private readonly AuditLog $audit,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        $originOk = $this->originAllowed($request);
        $tokenOk = Csrf::valid($_SESSION ?? [], $request->getHeaderLine('X-CSRF-Token'));
        if (! $originOk || ! $tokenOk) {
            $this->audit->record('csrf_rejected', null, null, null, ['origin_ok' => $originOk, 'token_ok' => $tokenOk, 'path' => $request->getUri()->getPath()], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

            return Json::write($this->responses->createResponse(), ['error' => 'csrf'], 403);
        }

        return $handler->handle($request);
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            $referer = $request->getHeaderLine('Referer');
            $origin = $referer !== '' ? (self::originOf($referer) ?? '') : '';
        }
        $origin = strtolower($origin);

        return $origin !== '' && in_array($origin, $this->allowedOrigins(), true);
    }

    private function allowedOrigins(): array
    {
        $list = [self::originOf($this->config->getRequired('APP_URL'))];
        foreach (explode(',', (string) $this->config->get('ALLOWED_ORIGINS', '')) as $extra) {
            $list[] = self::originOf(trim($extra));
        }

        return array_values(array_filter($list, static fn (?string $o): bool => $o !== null));
    }

    private static function originOf(string $url): ?string
    {
        $p = parse_url($url);
        if (! is_array($p) || ! isset($p['scheme'], $p['host']) || ! in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
    }
}
