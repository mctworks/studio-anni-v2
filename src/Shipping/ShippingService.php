<?php

declare(strict_types=1);

namespace App\Shipping;

/** Splits an order into boxes, prices each box with USPS, and adds insurance from real piece prices. */
final class ShippingService
{
    public function __construct(private readonly \PDO $pdo, private readonly UspsClient $usps)
    {
    }

    /**
     * @param list<array{long_in: float, short_in: float, price_cents: int}> $pieces price_cents = what the customer pays for that piece
     * @return array{postage_cents: int, insurance_cents: int, total_cents: int, packages: list<array<string, mixed>>, quoted_at: string}
     * @throws ShippingUnavailable
     */
    public function quote(array $pieces, string $destZip): array
    {
        $zip5 = substr(preg_replace('/\D/', '', $destZip) ?? '', 0, 5);
        if ($pieces === [] || strlen($zip5) !== 5) {
            throw new \InvalidArgumentException('A destination ZIP and at least one piece are required.');
        }

        $boxes = [];
        $rows = $this->pdo->query('SELECT label, long_in, short_in, depth_in, packed_weight_oz FROM boxes WHERE active = 1 ORDER BY sort_order');
        foreach ($rows as $r) {
            $boxes[] = [
                'label' => (string) $r['label'],
                'long_in' => (float) $r['long_in'],
                'short_in' => (float) $r['short_in'],
                'depth_in' => (float) $r['depth_in'],
                'packed_weight_oz' => (float) $r['packed_weight_oz'],
            ];
        }

        $schedule = json_decode($this->setting('usps_insurance_schedule'), true, 512, JSON_THROW_ON_ERROR);
        $packages = PackageSelector::pack($pieces, $boxes, (float) $this->setting('canvas_thickness_in'));

        $postage = 0;
        $insurance = 0;
        $out = [];
        foreach ($packages as $pkg) {
            $box = $pkg['box'];
            if ($box === null) {
                throw new ShippingUnavailable('oversized');
            }

            $value = array_sum(array_column($pkg['pieces'], 'price_cents'));
            $pkgPostage = $this->usps->postageCents(
                $zip5,
                $box['packed_weight_oz'],
                max($box['long_in'], $box['short_in']),
                $box['depth_in'],
                min($box['long_in'], $box['short_in']),
                $value,
            );
            $pkgInsurance = InsuranceSchedule::feeCents($value, $schedule);

            $postage += $pkgPostage;
            $insurance += $pkgInsurance;
            $out[] = [
                'box' => $box['label'],
                'pieces' => count($pkg['pieces']),
                'declared_cents' => $value,
                'postage_cents' => $pkgPostage,
                'insurance_cents' => $pkgInsurance,
            ];
        }

        return [
            'postage_cents' => $postage,
            'insurance_cents' => $insurance,
            'total_cents' => $postage + $insurance,
            'packages' => $out,
            'quoted_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function setting(string $key): string
    {
        $st = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException("Missing setting: {$key}");
        }

        return (string) $value;
    }
}