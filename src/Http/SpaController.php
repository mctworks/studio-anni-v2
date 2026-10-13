<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves the built React shell for every non-API route.
 * Later: inject <title>/Open Graph tags here for /pieces/{id}-{slug} (see docs/OUTLINE.md, section 5).
 */
final class SpaController
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, string> $args */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $path = ltrim($args['path'] ?? '', '/');
        if ($path === 'api' || str_starts_with($path, 'api/')) {
            throw new HttpNotFoundException($request);
        }

        $index = $this->config->publicDir . '/app/index.html';
        if (! is_file($index)) {
            $response->getBody()->write('Front end not built yet.');

            return $response->withStatus(503)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response->getBody()->write((string) file_get_contents($index));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
