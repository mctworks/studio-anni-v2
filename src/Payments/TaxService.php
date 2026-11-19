<?php

declare(strict_types=1);

namespace App\Payments;

use App\Config;

class TaxService
{
    private const URL = 'https://api.zip-tax.com/request/v60';
    private const FRESH_SECONDS = 604800;

    public function __construct(private readonly \PDO $pdo, private readonly Config $config)
    {
    }

    public static function amount(int $baseCents, int $ratePpm): int
    {
        return intdiv($baseCents * $ratePpm + 500000, 1000000);
    }

    public static function georgiaZip(string $zip): bool
    {
        $n = (int) Address::zip5($zip);

        return ($n >= 30000 && $n <= 31999) || ($n >= 39800 && $n <= 39999);
    }

    /** @return array{rate_ppm: int, shipping_taxable: bool} @throws TaxUnavailable */
    public function lookup(string $street, string $city, string $state, string $zip): array
    {
        if (strtoupper($state) !== 'GA') {
            return ['rate_ppm' => 0, 'shipping_taxable' => false];
        }

        $address = trim(preg_replace('/\s+/', ' ', "{$street} {$city} GA " . Address::zip5($zip)) ?? '');
        $hash = hash('sha256', strtolower($address));
        $cached = $this->cached($hash);
        if ($cached !== null && $cached['age'] < self::FRESH_SECONDS) {
            return ['rate_ppm' => $cached['rate_ppm'], 'shipping_taxable' => $cached['shipping_taxable']];
        }

        try {
            $fresh = $this->request($address);
        } catch (TaxUnavailable $e) {
            if ($cached !== null) {
                return ['rate_ppm' => $cached['rate_ppm'], 'shipping_taxable' => $cached['shipping_taxable']];
            }
            throw $e;
        }

        $this->pdo->prepare('REPLACE INTO tax_cache (address_hash, zip, rate_ppm, shipping_taxable, fetched_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$hash, Address::zip5($zip), $fresh['rate_ppm'], $fresh['shipping_taxable'] ? 1 : 0, gmdate('Y-m-d H:i:s')]);

        return $fresh;
    }

    /** @return array{rate_ppm: int, shipping_taxable: bool}|array{rate_ppm: int, shipping_taxable: bool, age: int}|null */
    private function cached(string $hash): ?array
    {
        $st = $this->pdo->prepare('SELECT rate_ppm, shipping_taxable, fetched_at FROM tax_cache WHERE address_hash = ?');
        $st->execute([$hash]);
        $r = $st->fetch();
        if ($r === false) {
            return null;
        }

        return [
            'rate_ppm' => (int) $r['rate_ppm'],
            'shipping_taxable' => (bool) $r['shipping_taxable'],
            'age' => time() - (int) strtotime((string) $r['fetched_at'] . ' UTC'),
        ];
    }

    /** @return array{rate_ppm: int, shipping_taxable: bool} @throws TaxUnavailable */
    private function request(string $address): array
    {
        $key = $this->config->get('ZIPTAX_API_KEY');
        if ($key === null) {
            throw new TaxUnavailable('not_configured');
        }

        [$status, $data] = $this->fetch(self::URL . '?address=' . rawurlencode($address), $key);
        if ($status !== 200 || ! is_array($data)) {
            throw new TaxUnavailable("http_{$status}");
        }
        $code = $data['metadata']['response']['code'] ?? 100;
        $rate = $data['taxSummaries'][0]['rate'] ?? null;
        if ($code !== 100 || ! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 0.2) {
            throw new TaxUnavailable('bad_response');
        }
        $taxable = $data['shipping']['taxable'] ?? 'Y';

        return [
            'rate_ppm' => (int) round((float) $rate * 1000000),
            'shipping_taxable' => $taxable !== 'N' && $taxable !== false,
        ];
    }

    /** @return array{0: int, 1: mixed} */
    protected function fetch(string $url, string $apiKey): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['X-API-KEY: ' . $apiKey, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, is_string($body) ? json_decode($body, true) : null];
    }
}
