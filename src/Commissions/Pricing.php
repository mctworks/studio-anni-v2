<?php

declare(strict_types=1);

namespace App\Commissions;

final class Pricing
{
    public const SUBJECTS = ['pet', 'human'];
    public const AVAILABILITY = ['open', 'delays', 'closed', 'unknown'];
    public const FEE_KEYS = [
        'extra_pet_cents' => 'commission_fee_extra_pet_cents',
        'extra_person_cents' => 'commission_fee_extra_person_cents',
        'human_pet_cents' => 'commission_fee_human_pet_cents',
    ];
    public const MAX_CENTS = 99999999;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function availability(): string
    {
        $value = $this->setting('commission_availability', 'unknown');

        return in_array($value, self::AVAILABILITY, true) ? $value : 'unknown';
    }

    public function depositPercent(): int
    {
        $value = (int) $this->setting('commission_deposit_percent', '50');

        return $value >= 1 && $value <= 100 ? $value : 50;
    }

    public function fees(): array
    {
        $out = [];
        foreach (self::FEE_KEYS as $name => $key) {
            $out[$name] = max(0, (int) $this->setting($key, '0'));
        }

        return $out;
    }

    public function table(): array
    {
        $rows = $this->pdo->query(
            "SELECT cs.id, cs.label, cs.long_in, cs.short_in,
                    MAX(CASE WHEN cp.subject_type = 'pet' THEN cp.price_cents END) AS pet_cents,
                    MAX(CASE WHEN cp.subject_type = 'human' THEN cp.price_cents END) AS human_cents
             FROM canvas_sizes cs
             LEFT JOIN commission_prices cp ON cp.canvas_size_id = cs.id
             WHERE cs.offered = 1 OR cp.canvas_size_id IS NOT NULL
             GROUP BY cs.id, cs.label, cs.long_in, cs.short_in
             ORDER BY cs.long_in * cs.short_in, cs.long_in"
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'canvas_size_id' => (int) $r['id'],
            'label' => (string) $r['label'],
            'long_in' => (float) $r['long_in'],
            'short_in' => (float) $r['short_in'],
            'pet_cents' => $r['pet_cents'] === null ? null : (int) $r['pet_cents'],
            'human_cents' => $r['human_cents'] === null ? null : (int) $r['human_cents'],
        ], $rows);
    }

    public function publicTable(): array
    {
        $groups = [];
        foreach ($this->table() as $row) {
            if ($row['pet_cents'] === null && $row['human_cents'] === null) {
                continue;
            }
            $key = ($row['pet_cents'] ?? 'x') . '|' . ($row['human_cents'] ?? 'x');
            if (! isset($groups[$key])) {
                $groups[$key] = ['labels' => [], 'canvas_size_ids' => [], 'pet_cents' => $row['pet_cents'], 'human_cents' => $row['human_cents']];
            }
            $groups[$key]['labels'][] = $row['label'];
            $groups[$key]['canvas_size_ids'][] = $row['canvas_size_id'];
        }

        return array_values(array_map(static fn (array $g): array => [
            'label' => implode(' or ', $g['labels']),
            'canvas_size_ids' => $g['canvas_size_ids'],
            'pet_cents' => $g['pet_cents'],
            'human_cents' => $g['human_cents'],
        ], $groups));
    }

    public function savePrices(array $prices): void
    {
        $upsert = $this->pdo->prepare(
            'INSERT INTO commission_prices (subject_type, canvas_size_id, price_cents) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE price_cents = VALUES(price_cents)'
        );
        $delete = $this->pdo->prepare('DELETE FROM commission_prices WHERE subject_type = ? AND canvas_size_id = ?');

        $this->pdo->beginTransaction();
        try {
            foreach ($prices as $p) {
                if ($p['price_cents'] === null) {
                    $delete->execute([$p['subject_type'], $p['canvas_size_id']]);
                } else {
                    $upsert->execute([$p['subject_type'], $p['canvas_size_id'], $p['price_cents']]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveFees(array $fees): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($fees as $name => $cents) {
                $this->saveSetting(self::FEE_KEYS[$name], (string) $cents);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveAvailability(string $value): void
    {
        $this->saveSetting('commission_availability', $value);
    }

    public function saveDepositPercent(int $percent): void
    {
        $this->saveSetting('commission_deposit_percent', (string) $percent);
    }

    public function quote(string $subject, int $canvasSizeId, int $pets, int $people): ?array
    {
        if (! in_array($subject, self::SUBJECTS, true) || $pets < 0 || $people < 0
            || ($subject === 'pet' && $pets < 1) || ($subject === 'human' && $people < 1)) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT price_cents FROM commission_prices WHERE subject_type = ? AND canvas_size_id = ?');
        $st->execute([$subject, $canvasSizeId]);
        $base = $st->fetchColumn();
        if ($base === false) {
            return null;
        }

        return self::compute((int) $base, $subject, $pets, $people, $this->fees(), $this->depositPercent());
    }

    public static function compute(int $base, string $subject, int $pets, int $people, array $fees, int $depositPercent): array
    {
        $extras = $subject === 'pet'
            ? max(0, $pets - 1) * $fees['extra_pet_cents']
            : max(0, $people - 1) * $fees['extra_person_cents'] + $pets * $fees['human_pet_cents'];
        $total = $base + $extras;

        return [
            'base_cents' => $base,
            'extras_cents' => $extras,
            'total_cents' => $total,
            'deposit_cents' => intdiv($total * $depositPercent + 99, 100),
        ];
    }

    private function setting(string $key, string $default): string
    {
        $st = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute([$key]);
        $value = $st->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    private function saveSetting(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
    }
}
