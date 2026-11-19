<?php

declare(strict_types=1);

namespace App\Payments;

class CartService
{
    public const MAX_ITEMS = 20;

    private const HELD = "EXISTS (SELECT 1 FROM order_items oi JOIN orders o ON o.id = oi.order_id AND o.kind = 'sale' AND o.status = 'pending'
        JOIN payments pay ON pay.order_id = o.id AND pay.status = 'pending' AND pay.expires_at > ? WHERE oi.piece_id = p.id AND o.user_id <> ?)";

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $userId): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.id, p.slug, p.title, p.status, p.purchase_mode, p.updated_at, cs.label AS size, cs.long_in, cs.short_in,
                    CASE WHEN p.sale_price_cents IS NOT NULL AND (p.sale_ends_at IS NULL OR p.sale_ends_at > NOW()) THEN p.sale_price_cents ELSE p.price_cents END AS price_cents,
                    (SELECT i.filename FROM piece_images i WHERE i.piece_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS filename,
                    ' . self::HELD . ' AS held
             FROM cart_items ci JOIN pieces p ON p.id = ci.piece_id JOIN canvas_sizes cs ON cs.id = p.canvas_size_id
             WHERE ci.user_id = ? AND p.deleted_at IS NULL ORDER BY ci.created_at, p.id'
        );
        $st->execute([gmdate('Y-m-d H:i:s'), $userId, $userId]);

        return array_map(static function (array $r): array {
            $reason = $r['status'] !== 'available' || $r['purchase_mode'] !== 'buy' ? 'unavailable' : ((int) $r['held'] === 1 ? 'held' : null);

            return [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'title' => (string) $r['title'],
                'size' => (string) $r['size'],
                'long_in' => (float) $r['long_in'],
                'short_in' => (float) $r['short_in'],
                'price_cents' => (int) $r['price_cents'],
                'image' => $r['filename'] === null ? null : '/media/pieces/' . rawurlencode((string) $r['filename']) . '?v=' . rawurlencode((string) $r['updated_at']),
                'ok' => $reason === null,
                'reason' => $reason,
            ];
        }, $st->fetchAll());
    }

    public function add(int $userId, int $pieceId): ?string
    {
        $st = $this->pdo->prepare(
            "SELECT p.status, p.purchase_mode, {$this->heldSql()} AS held FROM pieces p WHERE p.id = ? AND p.deleted_at IS NULL"
        );
        $st->execute([gmdate('Y-m-d H:i:s'), $userId, $pieceId]);
        $p = $st->fetch();
        if ($p === false || $p['status'] !== 'available' || $p['purchase_mode'] !== 'buy') {
            return 'unavailable';
        }
        if ((int) $p['held'] === 1) {
            return 'held';
        }
        $has = $this->pdo->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = ? AND piece_id = ?');
        $has->execute([$userId, $pieceId]);
        if ((int) $has->fetchColumn() === 1) {
            return null;
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = ?');
        $count->execute([$userId]);
        if ((int) $count->fetchColumn() >= self::MAX_ITEMS) {
            return 'cart_full';
        }
        $this->pdo->prepare('INSERT INTO cart_items (user_id, piece_id, created_at) VALUES (?, ?, ?)')->execute([$userId, $pieceId, gmdate('Y-m-d H:i:s')]);

        return null;
    }

    public function remove(int $userId, int $pieceId): void
    {
        $this->pdo->prepare('DELETE FROM cart_items WHERE user_id = ? AND piece_id = ?')->execute([$userId, $pieceId]);
    }

    public function heldSql(): string
    {
        return self::HELD;
    }
}
