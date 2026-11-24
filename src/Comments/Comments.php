<?php

declare(strict_types=1);

namespace App\Comments;

use App\Customers\Avatars;
use App\Customers\Customers;

final class Comments
{
    public const GUESTBOOK_MAX = 2000;
    public const PIECE_MAX = 500;
    public const STATUSES = ['pending', 'approved', 'rejected'];
    public const KINDS = ['guestbook', 'piece'];

    private const AUTHOR = "u.display_name, u.role, p.username, p.profile_public, p.custom_title, p.avatar_file,
        (SELECT COUNT(*) FROM piece_owners o JOIN pieces pc ON pc.id = o.piece_id WHERE o.user_id = u.id AND pc.deleted_at IS NULL) AS owned";

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array{0: ?string, 1: ?string} cleaned text, or an error code: empty, too_long, invalid */
    public static function clean(mixed $value, int $max): array
    {
        if (! is_string($value)) {
            return [null, 'invalid'];
        }
        $v = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if ($v === '') {
            return [null, 'empty'];
        }
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $v) === 1 || ! mb_check_encoding($v, 'UTF-8')) {
            return [null, 'invalid'];
        }

        return mb_strlen($v) > $max ? [null, 'too_long'] : [$v, null];
    }

    public function holdsAll(string $kind): bool
    {
        $st = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute([$kind === 'guestbook' ? 'guestbook_hold_all' : 'comments_hold_all']);

        return $st->fetchColumn() !== '0';
    }

    public function hasProfile(int $userId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM customer_profiles WHERE user_id = ?');
        $st->execute([$userId]);

        return $st->fetchColumn() !== false;
    }

    public function pieceExists(int $pieceId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM pieces WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$pieceId]);

        return $st->fetchColumn() !== false;
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function guestbook(int $limit, int $offset): array
    {
        $join = "FROM guestbook_entries c JOIN users u ON u.id = c.user_id JOIN customer_profiles p ON p.user_id = u.id
                 WHERE c.status = 'approved' AND u.status = 'active'";
        $total = (int) $this->run("SELECT COUNT(*) {$join}")->fetchColumn();
        $rows = $this->run(
            'SELECT c.id, c.body, c.created_at, ' . self::AUTHOR . " {$join} ORDER BY c.created_at DESC, c.id DESC LIMIT ? OFFSET ?",
            [$limit, $offset],
        )->fetchAll();

        return ['rows' => array_map(fn (array $r): array => $this->shape($r, false), $rows), 'total' => $total];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function pieceComments(int $pieceId, int $limit, int $offset): array
    {
        $join = "FROM piece_comments c JOIN users u ON u.id = c.user_id JOIN customer_profiles p ON p.user_id = u.id
                 WHERE c.piece_id = ? AND c.status = 'approved' AND u.status = 'active'";
        $total = (int) $this->run("SELECT COUNT(*) {$join}", [$pieceId])->fetchColumn();
        $rows = $this->run(
            'SELECT c.id, c.body, c.created_at, c.like_count,
                EXISTS(SELECT 1 FROM piece_owners po WHERE po.piece_id = c.piece_id AND po.user_id = c.user_id) AS is_owner, '
            . self::AUTHOR . " {$join} ORDER BY c.created_at ASC, c.id ASC LIMIT ? OFFSET ?",
            [$pieceId, $limit, $offset],
        )->fetchAll();

        return ['rows' => array_map(fn (array $r): array => $this->shape($r, true), $rows), 'total' => $total];
    }

    /** @return list<array<string, mixed>> the account's own entries and comments with their approval state */
    public function mine(int $userId): array
    {
        $out = [];
        foreach ($this->run('SELECT id, body, status, created_at FROM guestbook_entries WHERE user_id = ?', [$userId])->fetchAll() as $r) {
            $out[] = ['kind' => 'guestbook', 'id' => (int) $r['id'], 'body' => $r['body'], 'status' => $r['status'], 'createdAt' => self::iso($r['created_at']), 'likes' => 0, 'piece' => null];
        }
        $rows = $this->run(
            'SELECT c.id, c.body, c.status, c.created_at, c.like_count, p.id AS piece_id, p.slug, p.title
             FROM piece_comments c JOIN pieces p ON p.id = c.piece_id WHERE c.user_id = ?',
            [$userId],
        )->fetchAll();
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'piece', 'id' => (int) $r['id'], 'body' => $r['body'], 'status' => $r['status'], 'createdAt' => self::iso($r['created_at']),
                'likes' => (int) $r['like_count'], 'piece' => ['id' => (int) $r['piece_id'], 'slug' => $r['slug'], 'title' => $r['title']],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));

        return $out;
    }

    /** @return array{0: ?int, 1: ?string} new id, or the error code 'exists' */
    public function addGuestbook(int $userId, string $body, string $status): array
    {
        try {
            $this->run('INSERT INTO guestbook_entries (user_id, body, status, created_at) VALUES (?, ?, ?, ?)', [$userId, $body, $status, gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return [null, 'exists'];
            }
            throw $e;
        }

        return [(int) $this->pdo->lastInsertId(), null];
    }

    public function addPieceComment(int $userId, int $pieceId, string $body, string $status): int
    {
        $this->run('INSERT INTO piece_comments (piece_id, user_id, body, status, created_at) VALUES (?, ?, ?, ?, ?)', [$pieceId, $userId, $body, $status, gmdate('Y-m-d H:i:s')]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteOwn(string $kind, int $id, int $userId): bool
    {
        $table = self::table($kind);

        return $this->run("DELETE FROM {$table} WHERE id = ? AND user_id = ?", [$id, $userId])->rowCount() === 1;
    }

    /** @return int|null the new like count, null when the comment is not approved or missing */
    public function like(int $commentId, int $userId): ?int
    {
        return $this->setLike($commentId, $userId, true);
    }

    /** @return int|null the new like count, null when the comment is not approved or missing */
    public function unlike(int $commentId, int $userId): ?int
    {
        return $this->setLike($commentId, $userId, false);
    }

    /** @return list<int> */
    public function likedIds(int $userId, int $pieceId): array
    {
        return array_map('intval', $this->run(
            'SELECT l.comment_id FROM piece_comment_likes l JOIN piece_comments c ON c.id = l.comment_id WHERE l.user_id = ? AND c.piece_id = ?',
            [$userId, $pieceId],
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, pending: int} */
    public function moderation(?string $status, ?string $kind, ?int $userId, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($userId !== null) {
            $where[] = 'c.user_id = ?';
            $params[] = $userId;
        }
        $w = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $parts = [];
        $all = [];
        $count = 0;
        if ($kind === null || $kind === 'guestbook') {
            $parts[] = "SELECT 'guestbook' AS kind, c.id, c.body, c.status, c.created_at, c.user_id, NULL AS piece_id, NULL AS piece_title, 0 AS likes FROM guestbook_entries c{$w}";
            $count += (int) $this->run("SELECT COUNT(*) FROM guestbook_entries c{$w}", $params)->fetchColumn();
            array_push($all, ...$params);
        }
        if ($kind === null || $kind === 'piece') {
            $parts[] = "SELECT 'piece' AS kind, c.id, c.body, c.status, c.created_at, c.user_id, c.piece_id, pc.title AS piece_title, c.like_count AS likes FROM piece_comments c JOIN pieces pc ON pc.id = c.piece_id{$w}";
            $count += (int) $this->run("SELECT COUNT(*) FROM piece_comments c{$w}", $params)->fetchColumn();
            array_push($all, ...$params);
        }

        $rows = $this->run(
            'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') x ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [...$all, $limit, $offset],
        )->fetchAll();

        $authors = $this->authors(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['user_id'], $rows))));
        $pending = (int) $this->run("SELECT (SELECT COUNT(*) FROM guestbook_entries WHERE status = 'pending') + (SELECT COUNT(*) FROM piece_comments WHERE status = 'pending')")->fetchColumn();

        return [
            'rows' => array_map(static fn (array $r): array => [
                'kind' => $r['kind'],
                'id' => (int) $r['id'],
                'body' => $r['body'],
                'status' => $r['status'],
                'createdAt' => self::iso($r['created_at']),
                'likes' => (int) $r['likes'],
                'piece' => $r['piece_id'] === null ? null : ['id' => (int) $r['piece_id'], 'title' => $r['piece_title']],
                'author' => $authors[(int) $r['user_id']] ?? null,
            ], $rows),
            'total' => $count,
            'pending' => $pending,
        ];
    }

    public function exists(string $kind, int $id): bool
    {
        $table = self::table($kind);

        return $this->run("SELECT 1 FROM {$table} WHERE id = ?", [$id])->fetchColumn() !== false;
    }

    public function setStatus(string $kind, int $id, string $status, int $reviewerId): void
    {
        $table = self::table($kind);
        $this->run("UPDATE {$table} SET status = ?, reviewed_at = ?, reviewed_by = ? WHERE id = ?", [$status, gmdate('Y-m-d H:i:s'), $reviewerId, $id]);
    }

    public function delete(string $kind, int $id): void
    {
        $table = self::table($kind);
        $this->run("DELETE FROM {$table} WHERE id = ?", [$id]);
    }

    private function setLike(int $commentId, int $userId, bool $on): ?int
    {
        if ($this->run("SELECT 1 FROM piece_comments WHERE id = ? AND status = 'approved'", [$commentId])->fetchColumn() === false) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            if ($on) {
                $changed = $this->run('INSERT IGNORE INTO piece_comment_likes (comment_id, user_id) VALUES (?, ?)', [$commentId, $userId])->rowCount() === 1;
                if ($changed) {
                    $this->run('UPDATE piece_comments SET like_count = like_count + 1 WHERE id = ?', [$commentId]);
                }
            } else {
                $changed = $this->run('DELETE FROM piece_comment_likes WHERE comment_id = ? AND user_id = ?', [$commentId, $userId])->rowCount() === 1;
                if ($changed) {
                    $this->run('UPDATE piece_comments SET like_count = GREATEST(like_count, 1) - 1 WHERE id = ?', [$commentId]);
                }
            }
            $count = (int) $this->run('SELECT like_count FROM piece_comments WHERE id = ?', [$commentId])->fetchColumn();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $count;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function authors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->run(
            'SELECT u.id, u.status, ' . self::AUTHOR . " FROM users u JOIN customer_profiles p ON p.user_id = u.id WHERE u.id IN ({$in})",
            $ids,
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = self::author($r) + ['userId' => (int) $r['id'], 'accountStatus' => $r['status']];
        }

        return $out;
    }

    /** @param array<string, mixed> $r */
    private function shape(array $r, bool $piece): array
    {
        $out = ['id' => (int) $r['id'], 'body' => $r['body'], 'createdAt' => self::iso($r['created_at']), 'author' => self::author($r)];
        if ($piece) {
            $out['likes'] = (int) $r['like_count'];
            $out['owner'] = (int) $r['is_owner'] === 1;
        }

        return $out;
    }

    /** @param array<string, mixed> $r */
    private static function author(array $r): array
    {
        return [
            'displayName' => $r['display_name'] ?? $r['username'],
            'username' => (int) $r['profile_public'] === 1 ? $r['username'] : null,
            'avatar' => Avatars::url($r['avatar_file']),
            'title' => Customers::title($r['custom_title'], (int) $r['owned'], (string) $r['role']),
        ];
    }

    private static function table(string $kind): string
    {
        return match ($kind) {
            'guestbook' => 'guestbook_entries',
            'piece' => 'piece_comments',
        };
    }

    private static function iso(string $utc): string
    {
        return str_replace(' ', 'T', $utc) . 'Z';
    }

    /** @param list<mixed> $params */
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : ($v === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
        }
        $st->execute();

        return $st;
    }
}
