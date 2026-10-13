<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Config;

/** USPS v3 OAuth + Priority Mail base-rate lookup. Never logs tokens, secrets or response bodies. */
final class UspsClient
{
    private const BASE = 'https://apis.usps.com';
    private const MAIL_CLASS = 'PRIORITY_MAIL';
    private const DEFAULT_TTL = 1800; // used only if the token response has no expires_in

    public function __construct(private readonly Config $config)
    {
    }

    /** @throws ShippingUnavailable */
    public function postageCents(string $destZip5, float $weightOz, float $lengthIn, float $widthIn, float $heightIn, int $declaredCents): int
    {
        $body = [
            'originZIPCode' => $this->config->getRequired('USPS_ORIGIN_ZIP'),
            'destinationZIPCode' => $destZip5,
            'weight' => round($weightOz / 16, 4),
            'length' => $lengthIn,
            'width' => $widthIn,
            'height' => $heightIn,
            'mailClass' => self::MAIL_CLASS,
            'processingCategory' => 'NON_MACHINABLE',
            'destinationEntryFacilityType' => 'NONE',
            'rateIndicator' => 'SP',
            'priceType' => 'RETAIL',
            'mailingDate' => date('Y-m-d'),
            'itemValue' => round($declaredCents / 100, 2),
        ];

        [$status, $json] = $this->call('/prices/v3/base-rates/search', json_encode($body, JSON_THROW_ON_ERROR), 'application/json', $this->token());
        if ($status === 401) {
            [$status, $json] = $this->call('/prices/v3/base-rates/search', json_encode($body, JSON_THROW_ON_ERROR), 'application/json', $this->token(true));
        }

        $cents = ($status === 200 && is_array($json)) ? self::extractCents($json) : 0;
        if ($cents <= 0) {
            error_log("USPS rate lookup failed (HTTP {$status})");
            throw new ShippingUnavailable('usps');
        }

        return $cents;
    }

    /** "12.10", 12.1 or 12 to 1210. Never returns a negative number. */
    public static function priceToCents(mixed $price): int
    {
        if (! is_int($price) && ! is_float($price) && ! (is_string($price) && is_numeric($price))) {
            return 0;
        }
        [$dollars, $frac] = explode('.', number_format(max(0.0, (float) $price), 2, '.', ''));

        return (int) $dollars * 100 + (int) $frac;
    }

    /** @param array<string, mixed> $json */
    private static function extractCents(array $json): int
    {
        $rates = $json['rateOptions'][0]['rates'] ?? $json['rates'] ?? [];
        foreach (is_array($rates) ? $rates : [] as $rate) {
            if (($rate['mailClass'] ?? '') === self::MAIL_CLASS) {
                $cents = self::priceToCents($rate['price'] ?? null);
                if ($cents > 0) {
                    return $cents;
                }
            }
        }

        foreach ([$json['price'] ?? null, $json['totalBasePrice'] ?? null, $json['rateOptions'][0]['totalBasePrice'] ?? null] as $candidate) {
            $cents = self::priceToCents($candidate);
            if ($cents > 0) {
                return $cents;
            }
        }

        return 0;
    }

    /** @throws ShippingUnavailable */
    private function token(bool $refresh = false): string
    {
        $file = $this->config->root . '/storage/cache/usps_token.json';

        if (! $refresh && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && is_string($cached['token'] ?? null) && ($cached['expires_at'] ?? 0) > time() + 60) {
                return $cached['token'];
            }
        }

        [$status, $json] = $this->call('/oauth2/v3/token', http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->config->getRequired('USPS_CLIENT_ID'),
            'client_secret' => $this->config->getRequired('USPS_CLIENT_SECRET'),
        ]), 'application/x-www-form-urlencoded');

        if ($status !== 200 || ! is_array($json) || ! is_string($json['access_token'] ?? null)) {
            error_log("USPS token request failed (HTTP {$status})");
            throw new ShippingUnavailable('usps');
        }

        $ttl = (int) ($json['expires_in'] ?? self::DEFAULT_TTL);
        $tmp = $file . '.' . getmypid();
        if (@file_put_contents($tmp, json_encode(['token' => $json['access_token'], 'expires_at' => time() + $ttl]), LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $file);
        }

        return $json['access_token'];
    }

    /** @return array{0: int, 1: mixed} HTTP status (0 on connection failure) and decoded JSON */
    private function call(string $path, string $body, string $contentType, ?string $bearer = null): array
    {
        $headers = ['Content-Type: ' . $contentType, 'Accept: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, is_string($response) ? json_decode($response, true) : null];
    }
}