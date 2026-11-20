<?php

declare(strict_types=1);

namespace App\Customers;

use App\Payments\Address;

final class AddressBook
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id');
        $st->execute([$userId]);

        return array_map(self::view(...), $st->fetchAll());
    }

    /** @return array{ok: true, address: array<string, mixed>}|array{ok: false, error: string, fields?: array<string, string>} */
    public function save(int $userId, ?int $addressId, array $input): array
    {
        $allowed = ['label', 'recipient', 'line1', 'line2', 'city', 'region', 'postal_code', 'phone', 'isDefault'];
        $errors = [];
        foreach (array_diff(array_keys($input), $allowed) as $key) {
            $errors[(string) $key] = 'unknown_field';
        }

        [$address, $addressErrors] = Address::parse($input, true);
        $errors += $addressErrors;

        $label = $input['label'] ?? '';
        $label = is_string($label) ? trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $label) ?? '') : false;
        if ($label === false || mb_strlen($label) > 40) {
            $errors['label'] = 'invalid';
        }
        $phone = $input['phone'] ?? '';
        $phone = is_string($phone) ? trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $phone) ?? '') : false;
        if ($phone === false || mb_strlen($phone) > 30) {
            $errors['phone'] = 'invalid';
        }
        if (array_key_exists('isDefault', $input) && ! is_bool($input['isDefault'])) {
            $errors['isDefault'] = 'invalid';
        }
        if ($errors !== [] || $address === null) {
            return ['ok' => false, 'error' => 'validation', 'fields' => $errors];
        }

        $default = ($input['isDefault'] ?? false) === true;
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM users WHERE id = ?' . $this->forUpdate())->execute([$userId]);
            if ($addressId !== null) {
                $current = $this->pdo->prepare('SELECT id FROM addresses WHERE id = ? AND user_id = ?' . $this->forUpdate());
                $current->execute([$addressId, $userId]);
                if ($current->fetchColumn() === false) {
                    $this->pdo->rollBack();

                    return ['ok' => false, 'error' => 'not_found'];
                }
            } elseif (! $default) {
                $count = $this->pdo->prepare('SELECT COUNT(*) FROM addresses WHERE user_id = ?');
                $count->execute([$userId]);
                $default = (int) $count->fetchColumn() === 0;
            }

            if ($default) {
                $this->pdo->prepare('UPDATE addresses SET is_default = 0, updated_at = ? WHERE user_id = ?')->execute([$now, $userId]);
            }
            $values = [
                $label === '' ? null : $label,
                $address['recipient'],
                $address['line1'],
                $address['line2'] === '' ? null : $address['line2'],
                $address['city'],
                $address['region'],
                $address['postal_code'],
                'US',
                $phone === '' ? null : $phone,
                $default ? 1 : 0,
            ];
            if ($addressId === null) {
                $this->pdo->prepare(
                    'INSERT INTO addresses (user_id, label, recipient, line1, line2, city, region, postal_code, country, phone, is_default, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$userId, ...$values, $now, $now]);
                $addressId = (int) $this->pdo->lastInsertId();
            } else {
                $this->pdo->prepare(
                    'UPDATE addresses SET label = ?, recipient = ?, line1 = ?, line2 = ?, city = ?, region = ?, postal_code = ?, country = ?, phone = ?, is_default = ?, updated_at = ? WHERE id = ? AND user_id = ?'
                )->execute([...$values, $now, $addressId, $userId]);
            }
            $saved = $this->get($userId, $addressId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['ok' => true, 'address' => $saved];
    }

    public function delete(int $userId, int $addressId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM users WHERE id = ?' . $this->forUpdate())->execute([$userId]);
            $st = $this->pdo->prepare('SELECT is_default FROM addresses WHERE id = ? AND user_id = ?' . $this->forUpdate());
            $st->execute([$addressId, $userId]);
            $wasDefault = $st->fetchColumn();
            if ($wasDefault === false) {
                $this->pdo->rollBack();

                return false;
            }
            $this->pdo->prepare('DELETE FROM addresses WHERE id = ? AND user_id = ?')->execute([$addressId, $userId]);
            if ((int) $wasDefault === 1) {
                $next = $this->pdo->prepare('SELECT id FROM addresses WHERE user_id = ? ORDER BY id LIMIT 1');
                $next->execute([$userId]);
                $nextId = $next->fetchColumn();
                if ($nextId !== false) {
                    $this->pdo->prepare('UPDATE addresses SET is_default = 1, updated_at = ? WHERE id = ? AND user_id = ?')
                        ->execute([gmdate('Y-m-d H:i:s'), (int) $nextId, $userId]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function get(int $userId, int $addressId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM addresses WHERE id = ? AND user_id = ?');
        $st->execute([$addressId, $userId]);

        return self::view($st->fetch());
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function view(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'label' => $row['label'],
            'recipient' => $row['recipient'],
            'line1' => $row['line1'],
            'line2' => $row['line2'],
            'city' => $row['city'],
            'region' => $row['region'],
            'postal_code' => $row['postal_code'],
            'country' => $row['country'],
            'phone' => $row['phone'],
            'isDefault' => (int) $row['is_default'] === 1,
        ];
    }

    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}