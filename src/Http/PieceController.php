<?php

declare(strict_types=1);

namespace App\Http;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PieceController
{
    private const STATUSES = ['available', 'sold', 'in_studio', 'on_display'];
    private const ORIGINS = ['original', 'commission'];
    private const SORTS = [
        'newest' => 'p.painted_on DESC, p.id DESC',
        'recently_sold' => 'COALESCE(p.sold_at, p.painted_on) DESC, p.id DESC',
        'added' => 'p.created_at DESC, p.id DESC',
    ];
    private const MAX_LIMIT = 50;

    private const SELECT = "SELECT p.id, p.slug, p.title, p.description, p.medium, p.status, p.status_note, p.origin,
            p.purchase_mode, p.painted_on, cs.label AS size,
            CASE WHEN p.status = 'available' THEN p.price_cents END AS price_cents,
            p.price_cents AS listed_price_cents,
            CASE WHEN p.status = 'available' AND p.sale_price_cents IS NOT NULL
                  AND (p.sale_ends_at IS NULL OR p.sale_ends_at > NOW()) THEN p.sale_price_cents END AS sale_price_cents
        FROM pieces p
        JOIN canvas_sizes cs ON cs.id = p.canvas_size_id";

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        if (isset($q['status'])) {
            $statuses = array_unique(explode(',', (string) $q['status']));
            if (array_diff($statuses, self::STATUSES) !== []) {
                return $this->json($response, ['error' => 'invalid status'], 400);
            }
            $where[] = 'p.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }

        if (isset($q['origin'])) {
            if (! in_array($q['origin'], self::ORIGINS, true)) {
                return $this->json($response, ['error' => 'invalid origin'], 400);
            }
            $where[] = 'p.origin = ?';
            $params[] = $q['origin'];
        }

        if (isset($q['category'])) {
            if (! is_string($q['category']) || ! preg_match('/^[a-z0-9-]{1,40}$/', $q['category'])) {
                return $this->json($response, ['error' => 'invalid category'], 400);
            }
            $where[] = 'p.id IN (SELECT pc.piece_id FROM piece_categories pc JOIN categories c ON c.id = pc.category_id
                WHERE c.slug = ? OR c.parent_id = (SELECT id FROM categories WHERE slug = ?))';
            array_push($params, $q['category'], $q['category']);
        }

        if (isset($q['q'])) {
            if (! is_string($q['q']) || mb_strlen($q['q'], 'UTF-8') > 100) {
                return $this->json($response, ['error' => 'invalid q'], 400);
            }
            $term = trim($q['q']);
            if ($term !== '') {
                $where[] = "(LOCATE(LOWER(?), LOWER(COALESCE(p.title, ''))) > 0
                    OR LOCATE(LOWER(?), LOWER(COALESCE(p.description, ''))) > 0
                    OR LOCATE(LOWER(?), LOWER(COALESCE(p.medium, ''))) > 0)";
                array_push($params, $term, $term, $term);
            }
        }

        if (isset($q['discounted'])) {
            if ($q['discounted'] !== '1') {
                return $this->json($response, ['error' => 'invalid discounted'], 400);
            }
            $where[] = "p.status IN ('available', 'sold') AND p.origin = 'original' AND p.price_cents <= 10000";
        }

        $sort = (string) ($q['sort'] ?? 'newest');
        if (! isset(self::SORTS[$sort])) {
            return $this->json($response, ['error' => 'invalid sort'], 400);
        }

        $limit = min(self::MAX_LIMIT, max(1, (int) ($q['limit'] ?? 12)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM pieces p' . $whereSql);
        $this->run($count, $params);

        $stmt = $this->pdo->prepare(self::SELECT . $whereSql . ' ORDER BY ' . self::SORTS[$sort] . ' LIMIT ? OFFSET ?');
        $this->run($stmt, [...$params, $limit, $offset]);
        $pieces = $this->hydrate($stmt->fetchAll(), false);

        return $this->json($response, [
            'data' => $pieces,
            'meta' => ['total' => (int) $count->fetchColumn(), 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE p.id = ? AND p.deleted_at IS NULL');
        $this->run($stmt, [(int) $args['id']]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return $this->json($response, ['error' => 'not found'], 404);
        }

        return $this->json($response, ['data' => $this->hydrate($rows, true)[0]]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows, bool $allImages): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $images = [];
        $stmt = $this->pdo->prepare("SELECT i.piece_id, i.filename, i.kind, i.alt_text, p.updated_at AS image_version FROM piece_images i JOIN pieces p ON p.id = i.piece_id WHERE i.piece_id IN ($in) ORDER BY i.piece_id, i.sort_order, i.id");
        $this->run($stmt, $ids);
        foreach ($stmt as $img) {
            $images[(int) $img['piece_id']][] = [
            'url' => '/media/pieces/' . rawurlencode((string) $img['filename']) . '?v=' . rawurlencode((string) $img['image_version']),
                'kind' => $img['kind'],
                'alt' => $img['alt_text'],
            ];
        }

        $cats = [];
        $stmt = $this->pdo->prepare("SELECT pc.piece_id, c.slug, c.name FROM piece_categories pc JOIN categories c ON c.id = pc.category_id WHERE pc.piece_id IN ($in) ORDER BY c.sort_order, c.id");
        $this->run($stmt, $ids);
        foreach ($stmt as $c) {
            $cats[(int) $c['piece_id']][] = ['slug' => $c['slug'], 'name' => $c['name']];
        }

        return array_map(static function (array $r) use ($images, $cats, $allImages): array {
            $id = (int) $r['id'];
            $all = $images[$id] ?? [];
            $r['id'] = $id;
            $r['price_cents'] = $r['price_cents'] === null ? null : (int) $r['price_cents'];
            $r['listed_price_cents'] = (int) $r['listed_price_cents'];
            $r['sale_price_cents'] = $r['sale_price_cents'] === null ? null : (int) $r['sale_price_cents'];
            $r['image'] = $all[0] ?? null;
            if ($allImages) {
                $r['images'] = $all;
            }
            $r['categories'] = $cats[$id] ?? [];

            return $r;
        }, $rows);
    }

    /** @param list<int|string> $params */
    private function run(\PDOStatement $stmt, array $params): void
    {
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
    }

    /** @param array<string, mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}