<?php

declare(strict_types=1);

namespace App\Payments;

final class Address
{
    private const FIELDS = ['recipient', 'line1', 'line2', 'city', 'region', 'postal_code'];
    private const MAX = ['recipient' => 120, 'line1' => 120, 'line2' => 120, 'city' => 80];

    /** @return array{0: ?array<string, string>, 1: array<string, string>} address (null when invalid) and field errors */
    public static function parse(mixed $raw, bool $full): array
    {
        $raw = is_array($raw) ? $raw : [];
        $a = [];
        foreach (self::FIELDS as $f) {
            $v = $raw[$f] ?? '';
            $v = is_string($v) ? (string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $v) : '';
            $a[$f] = trim($v);
        }
        $errors = [];
        foreach (self::MAX as $f => $n) {
            if (mb_strlen($a[$f]) > $n) {
                $errors[$f] = 'invalid';
            }
        }
        $a['region'] = $full ? strtoupper($a['region']) : 'GA';
        if ($full && preg_match('/^[A-Z]{2}$/', $a['region']) !== 1) {
            $errors['region'] = $a['region'] === '' ? 'required' : 'invalid';
        }
        if (preg_match('/^\d{5}(-\d{4})?$/', $a['postal_code']) !== 1) {
            $errors['postal_code'] = $a['postal_code'] === '' ? 'required' : 'invalid';
        }
        if ($full) {
            foreach (['recipient', 'line1', 'city'] as $f) {
                if ($a[$f] === '') {
                    $errors[$f] = 'required';
                }
            }
        }

        return [$errors === [] ? $a : null, $errors];
    }

    public static function key(string $method, array $a): string
    {
        if ($method === 'pickup') {
            return md5('pickup');
        }

        return md5($method . '|' . substr((string) $a['postal_code'], 0, 5) . '|' . preg_replace('/[^a-z0-9]/', '', strtolower((string) $a['line1'])));
    }

    public static function zip5(string $zip): string
    {
        return substr($zip, 0, 5);
    }

    public static function money(int $cents): string
    {
        return '$' . number_format($cents / 100, 2);
    }
}
