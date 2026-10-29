<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MimosaPieceController
{
    private const STATUSES = ['available', 'sold', 'in_studio', 'on_display'];
    private const ORIGINS = ['original', 'commission'];
    private const MODES = ['buy', 'inquire'];
    private const COLUMNS = ['title', 'slug', 'description', 'medium', 'canvas_size_id', 'price_cents', 'sale_price_cents', 'sale_ends_at', 'status', 'status_note', 'origin', 'purchase_mode', 'print_url', 'painted_on'];
    private const SORTS = ['updated' => 'p.updated_at DESC, p.id DESC', 'id' => 'p.id DESC', 'title' => 'p.title ASC, p.id ASC'];
    private const MAX_CENTS = 99999999;

    public function __construct(private readonly \PDO $pdo, private readonly AuditLog $audit)
    {
    }

    public function meta(Request $request, Response $response): Response
    {
        $categories = array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'parent_id' => $c['parent_id'] === null ? null : (int) $c['parent_id'], 'slug' => $c['slug'], 'name' => $c['name']], $this->run('SELECT id, parent_id, slug, name FROM categories ORDER BY COALESCE(parent_id, id), parent_id IS NOT NULL, sort_order, id')->fetchAll());
        $sizes = array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'label' => $s['label'], 'offered' => (int) $s['offered'] === 1], $this->run('SELECT id, label, offered FROM canvas_sizes ORDER BY offered DESC, long_in, short_in')->fetchAll());

        return Json::write($response, ['categories' => $categories, 'sizes' => $sizes, 'statuses' => self::STATUSES, 'origins' => self::ORIGINS, 'purchase_modes' => self::MODES]);
    }

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $where = [];
        $params = [];

        $deleted = $q['deleted'] ?? '0';
        if ($deleted === '0') {
            $where[] = 'p.deleted_at IS NULL';
        } elseif ($deleted === '1') {
            $where[] = 'p.deleted_at IS NOT NULL';
        } elseif ($deleted !== 'all') {
            return $this->err($response, 400, 'invalid_request');
        }

        if (isset($q['status'])) {
            $list = is_string($q['status']) ? explode(',', $q['status']) : [];
            if ($list === [] || array_diff($list, self::STATUSES) !== []) {
                return $this->err($response, 400, 'invalid_request');
            }
            $where[] = 'p.status IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
            array_push($params, ...$list);
        }
        if (isset($q['q'])) {
            if (! is_string($q['q']) || mb_strlen($q['q']) > 100) {
                return $this->err($response, 400, 'invalid_request');
            }
            if ($q['q'] !== '') {
                $where[] = 'p.title LIKE ?';
                $params[] = '%' . addcslashes($q['q'], '%_\\') . '%';
            }
        }
        if (($q['untagged'] ?? '') === '1') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM piece_categories pc WHERE pc.piece_id = p.id)';
        }

        $sort = $q['sort'] ?? 'updated';
        $limit = $q['limit'] ?? '25';
        $offset = $q['offset'] ?? '0';
        if (! is_string($sort) || ! isset(self::SORTS[$sort]) || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100 || ! is_string($offset) || ! ctype_digit($offset)) {
            return $this->err($response, 400, 'invalid_request');
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $this->run('SELECT COUNT(*) FROM pieces p' . $clause, $params)->fetchColumn();
        $rows = $this->run(
            "SELECT p.id, p.slug, p.title, p.status, p.origin, p.purchase_mode, p.price_cents, p.sale_price_cents, p.painted_on, p.updated_at, p.deleted_at, cs.label AS size,
                (SELECT i.filename FROM piece_images i WHERE i.piece_id = p.id ORDER BY (i.kind = 'main') DESC, i.sort_order, i.id LIMIT 1) AS image
             FROM pieces p JOIN canvas_sizes cs ON cs.id = p.canvas_size_id{$clause} ORDER BY " . self::SORTS[$sort] . ' LIMIT ? OFFSET ?',
            [...$params, (int) $limit, (int) $offset],
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['price_cents'] = (int) $r['price_cents'];
            $r['sale_price_cents'] = $r['sale_price_cents'] === null ? null : (int) $r['sale_price_cents'];
        }
        unset($r);

        return Json::write($response, ['data' => $rows, 'meta' => ['total' => $total, 'limit' => (int) $limit, 'offset' => (int) $offset]]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $piece = $this->find((int) $args['id']);

        return $piece === null ? $this->err($response, 404, 'not_found') : Json::write($response, ['piece' => $piece]);
    }

    public function create(Request $request, Response $response): Response
    {
        $in = $request->getParsedBody();
        if (! is_array($in)) {
            return $this->err($response, 400, 'invalid_request');
        }
        [$clean, $errors] = $this->validate($in, null);
        if ($errors !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $errors]);
        }

        $now = gmdate('Y-m-d H:i:s');
        $row = $clean + ['slug' => $this->uniqueSlug($clean['title'], null), 'status' => 'available', 'origin' => 'original', 'purchase_mode' => 'buy', 'price_cents' => 0];
        $values = array_map(static fn (string $c): mixed => $row[$c] ?? null, self::COLUMNS);
        $values[] = $row['status'] === 'available' ? $now : null;
        $values[] = $row['status'] === 'sold' ? $now : null;
        array_push($values, $now, $now);

        $this->pdo->beginTransaction();
        try {
            $this->run(
                'INSERT INTO pieces (' . implode(', ', self::COLUMNS) . ', listed_at, sold_at, created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')',
                $values,
            );
            $id = (int) $this->pdo->lastInsertId();
            $this->syncCategories($id, $clean['category_ids'] ?? []);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                return $this->err($response, 409, 'slug_taken');
            }
            throw $e;
        }

        $this->log($request, 'piece_created', $id, ['fields' => array_keys($clean)]);

        return Json::write($response, ['piece' => $this->find($id)], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $current = $this->find($id);
        if ($current === null) {
            return $this->err($response, 404, 'not_found');
        }
        if ($current['deleted_at'] !== null) {
            return $this->err($response, 409, 'piece_deleted');
        }
        $in = $request->getParsedBody();
        if (! is_array($in) || $in === []) {
            return $this->err($response, 400, 'invalid_request');
        }
        [$clean, $errors] = $this->validate($in, $current);
        if ($errors !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $errors]);
        }

        $changes = [];
        foreach ($clean as $k => $v) {
            if ($current[$k] !== $v) {
                $changes[$k] = $v;
            }
        }
        if ($changes === []) {
            return Json::write($response, ['piece' => $current]);
        }

        $now = gmdate('Y-m-d H:i:s');
        $set = [];
        foreach ($changes as $k => $v) {
            if ($k !== 'category_ids') {
                $set[$k] = $v;
            }
        }
        if (isset($changes['status'])) {
            $set['sold_at'] = $changes['status'] === 'sold' ? ($current['sold_at'] ?? $now) : null;
            if ($changes['status'] === 'available') {
                $set['listed_at'] = $current['listed_at'] ?? $now;
            }
        }
        $set['updated_at'] = $now;

        $this->pdo->beginTransaction();
        try {
            $this->run(
                'UPDATE pieces SET ' . implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($set))) . ' WHERE id = ?',
                [...array_values($set), $id],
            );
            if (isset($changes['category_ids'])) {
                $this->syncCategories($id, $changes['category_ids']);
            }
            $this->pdo->commit();
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                return $this->err($response, 409, 'slug_taken');
            }
            throw $e;
        }

        $this->log($request, 'piece_updated', $id, ['fields' => array_keys($changes)]);

        return Json::write($response, ['piece' => $this->find($id)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        return $this->setDeleted($request, $response, (int) $args['id'], true);
    }

    public function restore(Request $request, Response $response, array $args): Response
    {
        return $this->setDeleted($request, $response, (int) $args['id'], false);
    }

    private function setDeleted(Request $request, Response $response, int $id, bool $deleted): Response
    {
        $piece = $this->find($id);
        if ($piece === null) {
            return $this->err($response, 404, 'not_found');
        }
        if (($piece['deleted_at'] !== null) === $deleted) {
            return Json::write($response, ['piece' => $piece]);
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->run('UPDATE pieces SET deleted_at = ?, updated_at = ? WHERE id = ?', [$deleted ? $now : null, $now, $id]);
        $this->log($request, $deleted ? 'piece_deleted' : 'piece_restored', $id, []);

        return Json::write($response, ['piece' => $this->find($id)]);
    }

    private function validate(array $in, ?array $current): array
    {
        $clean = [];
        $errors = [];
        $has = static fn (string $k): bool => array_key_exists($k, $in);

        foreach (array_diff(array_keys($in), [...self::COLUMNS, 'category_ids']) as $k) {
            $errors[(string) $k] = 'unknown_field';
        }

        if ($has('title') || $current === null) {
            $v = is_string($in['title'] ?? null) ? trim($in['title']) : '';
            if ($v === '' || mb_strlen($v) > 200) {
                $errors['title'] = 'invalid';
            } else {
                $clean['title'] = $v;
            }
        }

        foreach (['description' => 10000, 'medium' => 100, 'status_note' => 255] as $f => $max) {
            if (! $has($f)) {
                continue;
            }
            $v = $in[$f];
            if ($v !== null && ! is_string($v)) {
                $errors[$f] = 'invalid';
                continue;
            }
            $v = $v === null ? '' : trim($v);
            if (mb_strlen($v) > $max) {
                $errors[$f] = 'too_long';
            } else {
                $clean[$f] = $v === '' ? null : $v;
            }
        }

        foreach (['status' => self::STATUSES, 'origin' => self::ORIGINS, 'purchase_mode' => self::MODES] as $f => $allowed) {
            if ($has($f)) {
                if (is_string($in[$f]) && in_array($in[$f], $allowed, true)) {
                    $clean[$f] = $in[$f];
                } else {
                    $errors[$f] = 'invalid';
                }
            }
        }

        if ($has('canvas_size_id') || $current === null) {
            $v = $in['canvas_size_id'] ?? null;
            if (is_int($v) && $this->run('SELECT 1 FROM canvas_sizes WHERE id = ?', [$v])->fetchColumn() !== false) {
                $clean['canvas_size_id'] = $v;
            } else {
                $errors['canvas_size_id'] = 'invalid';
            }
        }

        if ($has('price_cents')) {
            $v = $in['price_cents'];
            if (is_int($v) && $v >= 0 && $v <= self::MAX_CENTS) {
                $clean['price_cents'] = $v;
            } else {
                $errors['price_cents'] = 'invalid';
            }
        }
        if ($has('sale_price_cents')) {
            $v = $in['sale_price_cents'];
            if ($v === null || (is_int($v) && $v >= 1 && $v <= self::MAX_CENTS)) {
                $clean['sale_price_cents'] = $v;
            } else {
                $errors['sale_price_cents'] = 'invalid';
            }
        }

        if ($has('sale_ends_at')) {
            $v = $in['sale_ends_at'];
            $utc = null;
            if (is_string($v) && $v !== '') {
                try {
                    $utc = (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                } catch (\Exception) {
                    $errors['sale_ends_at'] = 'invalid';
                }
            } elseif ($v !== null) {
                $errors['sale_ends_at'] = 'invalid';
            }
            if (! isset($errors['sale_ends_at'])) {
                $clean['sale_ends_at'] = $utc;
            }
        }

        if ($has('painted_on')) {
            $v = $in['painted_on'];
            $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
            if ($v === null || ($d !== false && $d->format('Y-m-d') === $v)) {
                $clean['painted_on'] = $v;
            } else {
                $errors['painted_on'] = 'invalid';
            }
        }

        if ($has('print_url')) {
            $v = $in['print_url'];
            $v = is_string($v) ? trim($v) : $v;
            if ($v === null || $v === '') {
                $clean['print_url'] = null;
            } elseif (is_string($v) && strlen($v) <= 500 && filter_var($v, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($v, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $clean['print_url'] = $v;
            } else {
                $errors['print_url'] = 'invalid';
            }
        }

        if ($has('slug')) {
            $v = $in['slug'];
            if (! is_string($v) || strlen($v) > 120 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v) !== 1) {
                $errors['slug'] = 'invalid';
            } elseif ($this->run('SELECT 1 FROM pieces WHERE slug = ? AND id <> ?', [$v, $current['id'] ?? 0])->fetchColumn() !== false) {
                $errors['slug'] = 'taken';
            } else {
                $clean['slug'] = $v;
            }
        }

        if ($has('category_ids')) {
            $v = $in['category_ids'];
            if (! is_array($v) || ! array_is_list($v) || count($v) > 12 || array_filter($v, static fn (mixed $x): bool => ! is_int($x)) !== []) {
                $errors['category_ids'] = 'invalid';
            } else {
                $v = array_values(array_unique($v));
                sort($v);
                $found = $v === [] ? 0 : (int) $this->run('SELECT COUNT(*) FROM categories WHERE id IN (' . implode(',', array_fill(0, count($v), '?')) . ')', $v)->fetchColumn();
                if ($found !== count($v)) {
                    $errors['category_ids'] = 'unknown_category';
                } else {
                    $clean['category_ids'] = $v;
                }
            }
        }

        $touched = static fn (string ...$keys): bool => array_intersect_key($clean, array_flip($keys)) !== [];
        $final = $clean + ($current ?? ['status' => 'available', 'price_cents' => 0, 'sale_price_cents' => null, 'sale_ends_at' => null]);

        if ($touched('status', 'price_cents', 'canvas_size_id') && $final['status'] === 'available' && $errors === []) {
            if ($final['price_cents'] < 1) {
                $errors['price_cents'] = 'required_when_available';
            }
            if ((int) $this->run('SELECT offered FROM canvas_sizes WHERE id = ?', [$final['canvas_size_id']])->fetchColumn() !== 1) {
                $errors['canvas_size_id'] = 'not_offered';
            }
        }
        if ($touched('price_cents', 'sale_price_cents', 'sale_ends_at', 'status') && $errors === []) {
            if ($final['sale_price_cents'] !== null && $final['sale_price_cents'] >= $final['price_cents']) {
                $errors['sale_price_cents'] = 'must_be_below_price';
            } elseif ($final['sale_price_cents'] !== null && $final['status'] !== 'available') {
                $errors['sale_price_cents'] = 'requires_available';
            }
            if ($final['sale_ends_at'] !== null && $final['sale_price_cents'] === null) {
                $errors['sale_ends_at'] = 'requires_sale_price';
            }
        }

        return [$clean, $errors];
    }

    private function find(int $id): ?array
    {
        $r = $this->run('SELECT p.*, cs.label AS size_label FROM pieces p JOIN canvas_sizes cs ON cs.id = p.canvas_size_id WHERE p.id = ?', [$id])->fetch();
        if (! is_array($r)) {
            return null;
        }
        foreach (['id', 'canvas_size_id', 'price_cents'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['sale_price_cents'] = $r['sale_price_cents'] === null ? null : (int) $r['sale_price_cents'];
        $r['images'] = array_map(
            static fn (array $i): array => ['id' => (int) $i['id'], 'filename' => $i['filename'], 'kind' => $i['kind'], 'alt_text' => $i['alt_text'], 'sort_order' => (int) $i['sort_order']],
            $this->run('SELECT id, filename, kind, alt_text, sort_order FROM piece_images WHERE piece_id = ? ORDER BY sort_order, id', [$id])->fetchAll(),
        );
        $r['category_ids'] = array_map('intval', $this->run('SELECT category_id FROM piece_categories WHERE piece_id = ? ORDER BY category_id', [$id])->fetchAll(\PDO::FETCH_COLUMN));

        return $r;
    }

    private function syncCategories(int $id, array $ids): void
    {
        $this->run('DELETE FROM piece_categories WHERE piece_id = ?', [$id]);
        foreach ($ids as $cid) {
            $this->run('INSERT INTO piece_categories (piece_id, category_id) VALUES (?, ?)', [$id, $cid]);
        }
    }

    private function uniqueSlug(string $title, ?int $exceptId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(str_replace(["'", "\u{2019}"], '', $title))), '-');
        $base = $base === '' ? 'piece' : rtrim(substr($base, 0, 100), '-');
        $slug = $base;
        for ($n = 2; $n < 100; $n++) {
            if ($this->run('SELECT 1 FROM pieces WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0])->fetchColumn() === false) {
                return $slug;
            }
            $slug = "{$base}-{$n}";
        }

        return $base . '-' . bin2hex(random_bytes(3));
    }

    private function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : ($v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
        }
        $st->execute();

        return $st;
    }

    private function log(Request $request, string $action, int $pieceId, array $details): void
    {
        $this->audit->record($action, (int) $request->getAttribute('user')['id'], 'piece', (string) $pieceId, $details, ClientIp::from($request), $request->getHeaderLine('User-Agent'));
    }

    private function err(Response $response, int $status, string $error, array $extra = []): Response
    {
        return Json::write($response, ['error' => $error] + $extra, $status);
    }
}
