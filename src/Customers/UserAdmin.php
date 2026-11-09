<?php

declare(strict_types=1);

namespace App\Customers;

final class UserAdmin
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} */
    public function list(string $q, ?string $role, ?string $status, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($q !== '') {
            $like = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $q) . '%';
            $where[] = "(u.email LIKE ? ESCAPE '|' OR u.display_name LIKE ? ESCAPE '|' OR p.username LIKE ? ESCAPE '|')";
            array_push($params, $like, $like, $like);
        }
        if ($role !== null) {
            $where[] = 'u.role = ?';
            $params[] = $role;
        }
        if ($status !== null) {
            $where[] = 'u.status = ?';
            $params[] = $status;
        }
        $w = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN customer_profiles p ON p.user_id = u.id $w");
        $count->execute($params);

        $st = $this->pdo->prepare(
            "SELECT u.id, u.email, u.display_name, u.role, u.status, u.last_login_at, u.created_at, p.username, p.custom_title,
                (SELECT COUNT(*) FROM piece_owners o JOIN pieces pc ON pc.id = o.piece_id WHERE o.user_id = u.id AND pc.deleted_at IS NULL) AS owned
             FROM users u LEFT JOIN customer_profiles p ON p.user_id = u.id $w
             ORDER BY u.created_at DESC, u.id DESC LIMIT ? OFFSET ?"
        );
        foreach ($params as $i => $v) {
            $st->bindValue($i + 1, $v);
        }
        $st->bindValue(count($params) + 1, $limit, \PDO::PARAM_INT);
        $st->bindValue(count($params) + 2, $offset, \PDO::PARAM_INT);
        $st->execute();

        $rows = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'displayName' => $r['display_name'],
            'username' => $r['username'],
            'role' => $r['role'],
            'status' => $r['status'],
            'title' => $r['username'] !== null ? Customers::title($r['custom_title'], (int) $r['owned'], (string) $r['role']) : null,
            'owned' => (int) $r['owned'],
            'createdAt' => $r['created_at'],
            'lastLoginAt' => $r['last_login_at'],
        ], $st->fetchAll());

        return ['rows' => $rows, 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string, mixed>|null */
    public function detail(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT u.id, u.email, u.display_name, u.role, u.status, u.email_verified_at, u.created_at, u.last_login_at, (u.totp_secret_enc IS NOT NULL) AS totp_on,
                p.username, p.location, p.profile_public, p.custom_title, p.avatar_file
             FROM users u LEFT JOIN customer_profiles p ON p.user_id = u.id WHERE u.id = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch();
        if (! is_array($r)) {
            return null;
        }
        $pieces = $this->pieces($id);

        return [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'displayName' => $r['display_name'],
            'role' => $r['role'],
            'status' => $r['status'],
            'emailVerified' => $r['email_verified_at'] !== null,
            'totpEnrolled' => (int) $r['totp_on'] === 1,
            'hasProfile' => $r['username'] !== null,
            'createdAt' => $r['created_at'],
            'lastLoginAt' => $r['last_login_at'],
            'username' => $r['username'],
            'location' => $r['location'],
            'profilePublic' => (int) ($r['profile_public'] ?? 0) === 1,
            'avatar' => Avatars::url($r['avatar_file']),
            'customTitle' => $r['custom_title'],
            'title' => $r['username'] !== null ? Customers::title($r['custom_title'], count($pieces), (string) $r['role']) : null,
            'pieces' => $pieces,
            'addresses' => $this->addresses($id),
        ];
    }

    public function setCustomTitle(int $id, ?string $title): void
    {
        $this->pdo->prepare('UPDATE customer_profiles SET custom_title = ? WHERE user_id = ?')->execute([$title, $id]);
    }

    public function hasProfile(int $id): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM customer_profiles WHERE user_id = ?');
        $st->execute([$id]);

        return $st->fetchColumn() !== false;
    }

    /** Demoting to customer also wipes the authenticator and unused recovery codes. */
    public function setRole(int $id, string $role): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE users SET role = ?, updated_at = ? WHERE id = ?')->execute([$role, $now, $id]);
            if ($role === 'customer') {
                $this->pdo->prepare('UPDATE users SET totp_secret_enc = NULL, totp_enrolled_at = NULL, totp_last_step = NULL WHERE id = ?')->execute([$id]);
                $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function setStatus(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE users SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, gmdate('Y-m-d H:i:s'), $id]);
    }

    public function assignPiece(int $userId, int $pieceId): string
    {
        $p = $this->pdo->prepare('SELECT 1 FROM pieces WHERE id = ? AND deleted_at IS NULL');
        $p->execute([$pieceId]);
        if ($p->fetchColumn() === false) {
            return 'no_piece';
        }
        $o = $this->pdo->prepare('SELECT user_id FROM piece_owners WHERE piece_id = ? LIMIT 1');
        $o->execute([$pieceId]);
        $owner = $o->fetchColumn();
        if ($owner !== false) {
            return (int) $owner === $userId ? 'already_yours' : 'already_owned';
        }
        try {
            $this->pdo->prepare('INSERT INTO piece_owners (piece_id, user_id, delivered_at, source) VALUES (?, ?, ?, \'manual\')')
                ->execute([$pieceId, $userId, gmdate('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return 'already_owned';
            }
            throw $e;
        }

        return 'ok';
    }

    public function removePiece(int $userId, int $pieceId): bool
    {
        $st = $this->pdo->prepare('DELETE FROM piece_owners WHERE piece_id = ? AND user_id = ?');
        $st->execute([$pieceId, $userId]);

        return $st->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> */
    private function pieces(int $userId): array
    {
        $st = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.status, o.delivered_at, o.source,
                (SELECT i.filename FROM piece_images i WHERE i.piece_id = p.id ORDER BY (i.kind = 'main') DESC, i.sort_order, i.id LIMIT 1) AS image
             FROM piece_owners o JOIN pieces p ON p.id = o.piece_id
             WHERE o.user_id = ? AND p.deleted_at IS NULL ORDER BY o.delivered_at DESC, p.id DESC"
        );
        $st->execute([$userId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'status' => $r['status'],
            'image' => $r['image'],
            'deliveredAt' => $r['delivered_at'],
            'source' => $r['source'],
        ], $st->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    private function addresses(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id');
        $st->execute([$userId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'label' => $r['label'],
            'recipient' => $r['recipient'],
            'line1' => $r['line1'],
            'line2' => $r['line2'],
            'city' => $r['city'],
            'region' => $r['region'],
            'postalCode' => $r['postal_code'],
            'country' => $r['country'],
            'phone' => $r['phone'],
            'isDefault' => (int) $r['is_default'] === 1,
        ], $st->fetchAll());
    }
}
