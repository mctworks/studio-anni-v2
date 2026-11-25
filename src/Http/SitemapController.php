<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SitemapController
{
    private const PAGES = ['/', '/gallery', '/archive', '/commissions', '/guestbook', '/about'];

    public function __construct(private readonly Config $config, private readonly PDO $pdo)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $site = rtrim($this->config->get('APP_URL', 'https://studioanni.com'), '/');
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::PAGES as $page) {
            $xml .= '<url><loc>' . $e($site . $page) . "</loc></url>\n";
        }
        foreach ($this->pdo->query('SELECT id, slug, updated_at FROM pieces WHERE deleted_at IS NULL ORDER BY id') as $row) {
            $xml .= '<url><loc>' . $e($site . '/pieces/' . $row['id'] . '-' . $row['slug']) . '</loc><lastmod>'
                . gmdate('Y-m-d', (int) strtotime((string) $row['updated_at'])) . "</lastmod></url>\n";
        }
        $xml .= '</urlset>' . "\n";

        $response->getBody()->write($xml);

        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
