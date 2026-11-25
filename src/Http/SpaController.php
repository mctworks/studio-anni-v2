<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves the built React shell for every non-API route.
 * Later: inject <title>/Open Graph tags here for /pieces/{id}-{slug} (see docs/OUTLINE.md, section 5).
 */
final class SpaController
{
    public function __construct(private readonly Config $config, private readonly PDO $pdo)
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

        $html = (string) file_get_contents($index);
        if (preg_match('#^pieces/(\d+)(?:-[a-z0-9-]+)?$#', $path, $m) === 1) {
            $html = $this->withPieceMeta($html, (int) $m[1], $path);
        }

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** Swaps the generic title/OG/canonical tags for this piece so shared links get a proper card. */
    private function withPieceMeta(string $html, int $id, string $path): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT p.title, p.description, p.slug, p.updated_at,
                    (SELECT i.filename FROM piece_images i WHERE i.piece_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS filename
                FROM pieces p WHERE p.id = ? AND p.deleted_at IS NULL');
            $stmt->execute([$id]);
            $piece = $stmt->fetch();
        } catch (\Throwable) {
            return $html;
        }
        if ($piece === false) {
            return $html;
        }

        $site = rtrim($this->config->get('APP_URL', 'https://studioanni.com'), '/');
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $piece['title'] . ' | Studio Anni';
        $desc = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($piece['description'] ?? ''))));
        $desc = $desc === '' ? 'Original acrylic painting by Anni Thompson.' : (mb_strlen($desc) > 200 ? mb_substr($desc, 0, 197) . '...' : $desc);
        $url = $site . '/pieces/' . $id . '-' . $piece['slug'];
        $image = $piece['filename'] !== null ? $site . '/media/pieces/' . rawurlencode((string) $piece['filename']) : null;

        $map = [
            '#<title>.*?</title>#s' => '<title>' . $e($title) . '</title>',
            '#<meta name="description" content="[^"]*" />#' => '<meta name="description" content="' . $e($desc) . '" />',
            '#<link rel="canonical" href="[^"]*" />#' => '<link rel="canonical" href="' . $e($url) . '" />',
            '#<meta property="og:url" content="[^"]*" />#' => '<meta property="og:url" content="' . $e($url) . '" />',
            '#<meta property="og:title" content="[^"]*" />#' => '<meta property="og:title" content="' . $e((string) $piece['title']) . '" />',
            '#<meta property="og:description" content="[^"]*" />#' => '<meta property="og:description" content="' . $e($desc) . '" />',
            '#<meta name="twitter:title" content="[^"]*" />#' => '<meta name="twitter:title" content="' . $e((string) $piece['title']) . '" />',
            '#<meta name="twitter:description" content="[^"]*" />#' => '<meta name="twitter:description" content="' . $e($desc) . '" />',
        ];
        if ($image !== null) {
            $map['#<meta property="og:image" content="[^"]*" />#'] = '<meta property="og:image" content="' . $e($image) . '" />';
            $map['#<meta name="twitter:image" content="[^"]*" />#'] = '<meta name="twitter:image" content="' . $e($image) . '" />';
        }

        foreach ($map as $pattern => $replacement) {
            $html = (string) preg_replace_callback($pattern, static fn (): string => $replacement, $html, 1);
        }

        return $html;
    }
}
