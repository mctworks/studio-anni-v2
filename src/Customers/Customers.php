<?php

declare(strict_types=1);

namespace App\Customers;

final class Customers
{
    public const USERNAME_PATTERN = '/^[a-z0-9_]{3,24}$/';
    private const RESERVED = ['admin', 'administrator', 'mimosa', 'anni', 'studioanni', 'studio_anni', 'staff', 'owner', 'support', 'contact', 'root', 'system', 'moderator', 'webmaster', 'api', 'login', 'logout', 'account', 'null'];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function usernameError(string $username): ?string
    {
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return 'invalid';
        }

        return in_array($username, self::RESERVED, true) ? 'reserved' : null;
    }

    public static function title(?string $custom, int $owned, string $role = 'customer'): string
    {
        if ($custom !== null && $custom !== '') {
            return $custom;
        }
        if ($role !== 'customer') {
            return 'Staff';
        }

        return $owned > 4 ? 'Regular Client' : ($owned >= 1 ? 'Client' : 'Visitor');
    }

    public function usernameTaken(string $username): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM customer_profiles WHERE username = ?');
        $st->execute([$username]);

        return $st->fetchColumn() !== false;
    }

    /** @throws \DomainException conflict when the email or username was taken in a race */
    public function create(string $email, string $displayName, string $passwordHash, string $username): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO users (email, display_name, password_hash, role, status, password_changed_at, created_at, updated_at) VALUES (?, ?, ?, \'customer\', \'active\', ?, ?, ?)'
            )->execute([$email, $displayName, $passwordHash, $now, $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO customer_profiles (user_id, username) VALUES (?, ?)')->execute([$id, $username]);
            $this->pdo->commit();

            return $id;
        } catch (\PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                throw new \DomainException('conflict');
            }
            throw $e;
        }
    }

    /** @throws \DomainException conflict when the username was taken in a race */
    public function createProfile(int $userId, string $username): void
    {
        try {
            $this->pdo->prepare('INSERT INTO customer_profiles (user_id, username, profile_public) VALUES (?, ?, 1)')->execute([$userId, $username]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \DomainException('conflict');
            }
            throw $e;
        }
    }

    public function setAvatar(int $userId, ?string $file): ?string
    {
        $st = $this->pdo->prepare('SELECT avatar_file FROM customer_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        $old = $st->fetchColumn();
        $this->pdo->prepare('UPDATE customer_profiles SET avatar_file = ? WHERE user_id = ?')->execute([$file, $userId]);

        return is_string($old) ? $old : null;
    }

    public function markVerified(int $userId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?')->execute([$now, $now, $userId]);
    }

    /** @param array<string, mixed> $fields displayName, location, profilePublic */
    public function update(int $userId, array $fields): void
    {
        $now = gmdate('Y-m-d H:i:s');
        if (array_key_exists('displayName', $fields)) {
            $this->pdo->prepare('UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?')->execute([$fields['displayName'], $now, $userId]);
        }
        if (array_key_exists('location', $fields)) {
            $this->pdo->prepare('UPDATE customer_profiles SET location = ? WHERE user_id = ?')->execute([$fields['location'], $userId]);
        }
        if (array_key_exists('profilePublic', $fields)) {
            $this->pdo->prepare('UPDATE customer_profiles SET profile_public = ? WHERE user_id = ?')->execute([$fields['profilePublic'] ? 1 : 0, $userId]);
        }
    }

    /** @return array<string, mixed>|null */
    public function own(int $userId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT u.id, u.email, u.display_name, u.role, p.username, p.location, p.profile_public, p.custom_title, p.avatar_file
             FROM users u JOIN customer_profiles p ON p.user_id = u.id WHERE u.id = ?'
        );
        $st->execute([$userId]);
        $r = $st->fetch();
        if (! is_array($r)) {
            return null;
        }
        $pieces = $this->ownedPieces($userId);

        return [
            'username' => $r['username'],
            'displayName' => $r['display_name'] ?? $r['username'],
            'email' => $r['email'],
            'location' => $r['location'],
            'profilePublic' => (int) $r['profile_public'] === 1,
            'avatar' => Avatars::url($r['avatar_file']),
            'role' => $r['role'],
            'title' => self::title($r['custom_title'], count($pieces), (string) $r['role']),
            'pieces' => $pieces,
        ];
    }

    /** @return array<string, mixed>|null only for active, verified accounts with a public profile */
    public function publicByUsername(string $username): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT u.id, u.display_name, u.role, p.username, p.location, p.custom_title, p.avatar_file
             FROM users u JOIN customer_profiles p ON p.user_id = u.id
             WHERE p.username = ? AND p.profile_public = 1 AND u.status = \'active\' AND u.email_verified_at IS NOT NULL'
        );
        $st->execute([$username]);
        $r = $st->fetch();
        if (! is_array($r)) {
            return null;
        }
        $pieces = $this->ownedPieces((int) $r['id']);
        $out = [
            'username' => $r['username'],
            'displayName' => $r['display_name'] ?? $r['username'],
            'title' => self::title($r['custom_title'], count($pieces), (string) $r['role']),
            'location' => $r['location'],
            'avatar' => Avatars::url($r['avatar_file']),
        ];
        if ($pieces !== []) {
            $out['pieces'] = $pieces;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function ownedPieces(int $userId): array
    {
        $st = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.title,
                (SELECT i.filename FROM piece_images i WHERE i.piece_id = p.id ORDER BY (i.kind = 'main') DESC, i.sort_order, i.id LIMIT 1) AS image
             FROM piece_owners o JOIN pieces p ON p.id = o.piece_id
             WHERE o.user_id = ? AND p.deleted_at IS NULL ORDER BY o.delivered_at DESC, p.id DESC"
        );
        $st->execute([$userId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'image' => $r['image'] === null ? null : '/media/pieces/' . rawurlencode((string) $r['image']),
        ], $st->fetchAll());
    }
}
