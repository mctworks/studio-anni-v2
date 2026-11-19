<?php

declare(strict_types=1);

namespace App\Payments;

use App\Http\CommissionRequestController;
use App\Shipping\ShippingService;
use App\Shipping\ShippingUnavailable;

class CheckoutService
{
    public const STUDIO_ZIP = '30047';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly CartService $cart,
        private readonly ShippingService $shipping,
        private readonly TaxService $tax,
        private readonly PaymentService $payments,
    ) {
    }

    /** @return array{ok: true, quote: array<string, mixed>}|array{ok: false, status: int, error: string, extra: array<string, mixed>} */
    public function quote(int $userId, mixed $method, mixed $rawAddress): array
    {
        if ($method !== 'ship' && $method !== 'pickup') {
            return $this->fail(422, 'invalid_method');
        }
        $lines = $this->cart->lines($userId);
        if ($lines === []) {
            return $this->fail(409, 'empty_cart');
        }
        if (array_filter($lines, static fn (array $l): bool => ! $l['ok']) !== []) {
            return $this->fail(409, 'cart_changed', ['lines' => $lines]);
        }

        [$address, $fields] = Address::parse($method === 'pickup' ? ['postal_code' => self::STUDIO_ZIP] : $rawAddress, $method === 'ship');
        if ($address === null) {
            return $this->fail(422, 'invalid_address', ['fields' => $fields]);
        }

        $subtotal = array_sum(array_column($lines, 'price_cents'));
        $postage = 0;
        $insurance = 0;
        $packages = [];
        if ($method === 'ship') {
            try {
                $q = $this->shipping->quote(array_map(static fn (array $l): array => ['long_in' => $l['long_in'], 'short_in' => $l['short_in'], 'price_cents' => $l['price_cents']], $lines), $address['postal_code']);
            } catch (ShippingUnavailable $e) {
                if ($e->reason === 'not_configured') {
                    return $this->fail(503, 'shipping_not_configured');
                }

                return $this->fail(422, 'shipping_unavailable', ['reason' => $e->reason]);
            }
            $postage = $q['postage_cents'];
            $insurance = $q['insurance_cents'];
            $packages = $q['packages'];
        }
        $shipping = $postage + $insurance;

        $rate = 0;
        $taxable = false;
        if ($address['region'] === 'GA') {
            try {
                ['rate_ppm' => $rate, 'shipping_taxable' => $taxable] = $this->tax->lookup($address['line1'], $address['city'], 'GA', $address['postal_code']);
            } catch (TaxUnavailable) {
                return $this->fail(503, 'tax_unavailable');
            }
        }
        $tax = TaxService::amount($subtotal + ($taxable ? $shipping : 0), $rate);

        return ['ok' => true, 'quote' => [
            'method' => $method,
            'address' => $address,
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $shipping,
            'postage_cents' => $postage,
            'insurance_cents' => $insurance,
            'packages' => $packages,
            'tax_rate_ppm' => $rate,
            'shipping_taxable' => $taxable,
            'tax_cents' => $tax,
            'total_cents' => $subtotal + $shipping + $tax,
        ]];
    }

    /** @return array<string, mixed> */
    public function pay(int $userId, array $body, string $ip): array
    {
        if (($body['accept_terms'] ?? false) !== true) {
            return $this->fail(422, 'terms_required');
        }
        $notes = $body['notes'] ?? '';
        $notes = is_string($notes) ? trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $notes)) : '';
        if (mb_strlen($notes) > 500) {
            return $this->fail(422, 'notes_too_long');
        }

        $r = $this->quote($userId, $body['method'] ?? null, $body['address'] ?? null);
        if (! $r['ok']) {
            return $r;
        }
        $q = $r['quote'];
        if (($body['expected_total_cents'] ?? null) !== $q['total_cents']) {
            return $this->fail(409, 'price_changed', ['quote' => $q]);
        }

        $this->payments->cancelPendingForUser($userId, 'sale');

        $ids = array_column($q['lines'], 'id');
        sort($ids);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $now = gmdate('Y-m-d H:i:s');
        $a = $q['address'];

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                "SELECT p.id FROM pieces p WHERE p.id IN ({$in}) AND p.status = 'available' AND p.purchase_mode = 'buy' AND p.deleted_at IS NULL AND NOT {$this->cart->heldSql()} ORDER BY p.id" . Locking::forUpdate($this->pdo)
            );
            $lock->execute([...$ids, $now, $userId]);
            if (count($lock->fetchAll()) !== count($ids)) {
                $this->pdo->rollBack();

                return $this->fail(409, 'cart_changed', ['lines' => $this->cart->lines($userId)]);
            }

            $this->pdo->prepare("INSERT INTO orders (user_id, kind, status, total_cents, paid_cents, created_at, updated_at) VALUES (?, 'sale', 'pending', ?, 0, ?, ?)")
                ->execute([$userId, $q['total_cents'], $now, $now]);
            $orderId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare(
                'INSERT INTO order_shipments (order_id, method, dest_key, recipient, line1, line2, city, region, postal_code, notes, postage_cents, insurance_cents, tax_rate_ppm, shipping_taxable, tax_cents, packages, quoted_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $orderId, $q['method'], Address::key($q['method'], $a),
                $q['method'] === 'ship' ? $a['recipient'] : null, $q['method'] === 'ship' ? $a['line1'] : null, $q['method'] === 'ship' && $a['line2'] !== '' ? $a['line2'] : null,
                $q['method'] === 'ship' ? $a['city'] : null, $a['region'], $a['postal_code'], $notes === '' ? null : $notes,
                $q['postage_cents'], $q['insurance_cents'], $q['tax_rate_ppm'], $q['shipping_taxable'] ? 1 : 0, $q['tax_cents'],
                json_encode($q['packages'], JSON_THROW_ON_ERROR), $now, $now, $now,
            ]);
            $shipmentId = (int) $this->pdo->lastInsertId();

            $item = $this->pdo->prepare('INSERT INTO order_items (order_id, shipment_id, piece_id, price_cents, created_at) VALUES (?, ?, ?, ?, ?)');
            foreach ($q['lines'] as $l) {
                $item->execute([$orderId, $shipmentId, $l['id'], $l['price_cents'], $now]);
            }

            $paymentId = $this->payments->open($orderId, 'sale', $q['subtotal_cents'], $q['shipping_cents'], $q['tax_cents'], PaymentService::SALE_HOLD_SECONDS, CommissionRequestController::TERMS_VERSION, $ip);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $started = $this->payments->start($paymentId, "Studio Anni order #{$orderId}");
        } catch (GatewayException) {
            return $this->fail(502, 'payment_unavailable');
        }

        return ['ok' => true, 'order_id' => $orderId, 'expires_in' => PaymentService::SALE_HOLD_SECONDS] + $started;
    }

    private function fail(int $status, string $error, array $extra = []): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'extra' => $extra];
    }
}
