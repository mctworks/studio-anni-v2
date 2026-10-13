<?php

declare(strict_types=1);

namespace App\Shipping;

/** Pure box selection. Largest piece first; the smallest box that holds everything left wins. */
final class PackageSelector
{
    private const EPS = 0.0001;

    /**
     * @param list<array{long_in: float, short_in: float, price_cents: int}> $pieces
     * @param list<array{label: string, long_in: float, short_in: float, depth_in: float, packed_weight_oz: float}> $boxes smallest to largest
     * @return list<array{box: array<string, mixed>|null, pieces: list<array<string, mixed>>}> box null = oversized, one piece alone
     */
    public static function pack(array $pieces, array $boxes, float $thicknessIn): array
    {
        $thicknessIn = $thicknessIn > 0 ? $thicknessIn : 1.0;

        foreach ($pieces as &$p) {
            [$p['long_in'], $p['short_in']] = [max($p['long_in'], $p['short_in']), min($p['long_in'], $p['short_in'])];
        }
        unset($p);
        usort($pieces, static fn (array $a, array $b): int => $b['long_in'] * $b['short_in'] <=> $a['long_in'] * $a['short_in']);

        $packages = [];
        while ($pieces !== []) {
            foreach ($boxes as $box) {
                if (count($pieces) <= self::capacity($box, $thicknessIn) && self::allFit($box, $pieces)) {
                    $packages[] = ['box' => $box, 'pieces' => $pieces];
                    break 2;
                }
            }

            $largest = $pieces[0];
            $box = null;
            foreach ($boxes as $candidate) {
                if (self::fits($candidate, $largest)) {
                    $box = $candidate;
                    break;
                }
            }
            if ($box === null) {
                $packages[] = ['box' => null, 'pieces' => [array_shift($pieces)]];
                continue;
            }

            $room = self::capacity($box, $thicknessIn);
            $taken = [];
            foreach ($pieces as $i => $p) {
                if (count($taken) < $room && self::fits($box, $p)) {
                    $taken[] = $p;
                    unset($pieces[$i]);
                }
            }
            $pieces = array_values($pieces);
            $packages[] = ['box' => $box, 'pieces' => $taken];
        }

        return $packages;
    }

    /** @param array<string, mixed> $box */
    private static function capacity(array $box, float $thicknessIn): int
    {
        return (int) floor($box['depth_in'] / $thicknessIn + self::EPS);
    }

    /** @param array<string, mixed> $box @param array<string, mixed> $piece */
    private static function fits(array $box, array $piece): bool
    {
        return $piece['long_in'] <= $box['long_in'] + self::EPS && $piece['short_in'] <= $box['short_in'] + self::EPS;
    }

    /** @param array<string, mixed> $box @param list<array<string, mixed>> $pieces */
    private static function allFit(array $box, array $pieces): bool
    {
        foreach ($pieces as $p) {
            if (! self::fits($box, $p)) {
                return false;
            }
        }

        return true;
    }
}