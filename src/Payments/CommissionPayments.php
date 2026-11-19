<?php

declare(strict_types=1);

namespace App\Payments;

use App\Commissions\Pricing;
use App\Shipping\ShippingService;
use App\Shipping\ShippingUnavailable;

class CommissionPayments
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Pricing $pricing,
        private readonly ShippingService $shipping,
        private readonly TaxService $tax,
        private readonly PaymentService $payments,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function state(int $userId, int $commissionId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id, c.status, c.quoted_cents, c.delivery, c.postal_code, cs.long_in, cs.short_in, o.id AS order_id
             FROM commissions c JOIN canvas_sizes cs ON cs.id = c.canvas_size_id JOIN orders o ON o.commission_id = c.id
             WHERE c.id = ? AND c.user_id = ?'
        );
        $st->execute([$commissionId, $userId]);
        $c = $st->fetch();
        if ($c === false) {
            return null;
        }

        $sum = $this->pdo->prepare(
            "SELECT COALESCE(SUM(base_cents), 0) AS base, COUNT(*) AS n, COALESCE(SUM(CASE WHEN kind = 'balance' THEN 1 ELSE 0 END), 0) AS bal, COALESCE(SUM(shipping_cents), 0) AS shipping
             FROM payments WHERE order_id = ? AND status = 'succeeded'
             UNION ALL
             SELECT COALESCE(SUM(amount_cents), 0), COUNT(*), COALESCE(SUM(CASE WHEN kind = 'balance' THEN 1 ELSE 0 END), 0), 0
             FROM commission_external_payments WHERE commission_id = ?"
        );
        $sum->execute([$c['order_id'], $commissionId]);
        $parts = $sum->fetchAll();
        $s = [
            'base' => (int) $parts[0]['base'] + (int) $parts[1]['base'],
            'n' => (int) $parts[0]['n'] + (int) $parts[1]['n'],
            'bal' => (int) $parts[0]['bal'] + (int) $parts[1]['bal'],
            'shipping' => (int) $parts[0]['shipping'],
        ];
        $total = (int) $c['quoted_cents'];
        $paidBase = (int) $s['base'];
        $options = [];

        if ($c['status'] === 'quoted' && (int) $s['n'] === 0) {
            $options['deposit'] = $this->pricing->depositPercent() === 100 ? $total : intdiv($total * $this->pricing->depositPercent() + 99, 100);
            $options['full'] = $total;
        } elseif (in_array($c['status'], ['completed', 'awaiting_balance'], true) && (int) $s['n'] > 0
            && ($total - $paidBase > 0 || ($c['delivery'] === 'ship' && (int) $s['shipping'] === 0))) {
            $options['balance'] = max(0, $total - $paidBase);
        }

        return [
            'commission_id' => (int) $c['id'],
            'order_id' => (int) $c['order_id'],
            'status' => (string) $c['status'],
            'delivery' => (string) $c['delivery'],
            'postal_code' => $c['postal_code'],
            'long_in' => (float) $c['long_in'],
            'short_in' => (float) $c['short_in'],
            'total_cents' => $total,
            'paid_base_cents' => $paidBase,
            'options' => $options,
        ];
    }

    /** @return array<string, mixed> */
    public function quote(int $userId, int $commissionId, mixed $option, mixed $rawAddress): array
    {
        $s = $this->state($userId, $commissionId);
        if ($s === null) {
            return $this->fail(404, 'not_found');
        }
        if (! is_string($option) || ! isset($s['options'][$option])) {
            return $this->fail(409, 'not_payable');
        }
        $base = $s['options'][$option];
        $ship = $option === 'balance' && $s['delivery'] === 'ship';

        $address = null;
        if ($ship) {
            [$address, $fields] = Address::parse($rawAddress, true);
            if ($address === null) {
                return $this->fail(422, 'invalid_address', ['fields' => $fields]);
            }
            $zip = $address['postal_code'];
        } else {
            $zip = $s['delivery'] === 'pickup' ? CheckoutService::STUDIO_ZIP : (string) $s['postal_code'];
        }

        $postage = 0;
        $insurance = 0;
        if ($ship) {
            try {
                $q = $this->shipping->quote([['long_in' => $s['long_in'], 'short_in' => $s['short_in'], 'price_cents' => $s['total_cents']]], $zip);
            } catch (ShippingUnavailable $e) {
                if ($e->reason === 'not_configured') {
                    return $this->fail(503, 'shipping_not_configured');
                }

                return $this->fail(422, 'shipping_unavailable', ['reason' => $e->reason]);
            }
            $postage = $q['postage_cents'];
            $insurance = $q['insurance_cents'];
        }
        $shipping = $postage + $insurance;

        $rate = 0;
        $taxable = false;
        $inGa = $address !== null ? $address['region'] === 'GA' : TaxService::georgiaZip($zip);
        if ($inGa) {
            try {
                ['rate_ppm' => $rate, 'shipping_taxable' => $taxable] = $this->tax->lookup($address['line1'] ?? '', $address['city'] ?? '', 'GA', $zip);
            } catch (TaxUnavailable) {
                return $this->fail(503, 'tax_unavailable');
            }
        }
        $tax = TaxService::amount($base + ($taxable ? $shipping : 0), $rate);

        return ['ok' => true, 'quote' => [
            'option' => $option,
            'address' => $address,
            'base_cents' => $base,
            'shipping_cents' => $shipping,
            'postage_cents' => $postage,
            'insurance_cents' => $insurance,
            'tax_rate_ppm' => $rate,
            'shipping_taxable' => $taxable,
            'tax_cents' => $tax,
            'total_cents' => $base + $shipping + $tax,
            'order_id' => $s['order_id'],
        ]];
    }

    /** @return array<string, mixed> */
    public function pay(int $userId, int $commissionId, array $body, string $ip): array
    {
        $r = $this->quote($userId, $commissionId, $body['option'] ?? null, $body['address'] ?? null);
        if (! $r['ok']) {
            return $r;
        }
        $q = $r['quote'];
        if (($body['expected_total_cents'] ?? null) !== $q['total_cents']) {
            return $this->fail(409, 'price_changed', ['quote' => $q]);
        }

        $this->payments->cancelPendingForOrder($q['order_id']);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('SELECT id FROM orders WHERE id = ?' . Locking::forUpdate($this->pdo))->execute([$q['order_id']]);
            $again = $this->state($userId, $commissionId);
            if ($again === null || ! isset($again['options'][$q['option']])) {
                $this->pdo->rollBack();

                return $this->fail(409, 'not_payable');
            }
            $kind = $q['option'] === 'balance' ? 'balance' : 'deposit';
            $paymentId = $this->payments->open($q['order_id'], $kind, $q['base_cents'], $q['shipping_cents'], $q['tax_cents'], PaymentService::COMMISSION_HOLD_SECONDS, null, $ip);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $label = ['deposit' => 'deposit', 'full' => 'payment in full', 'balance' => 'final payment'][$q['option']];
        try {
            $started = $this->payments->start($paymentId, "Studio Anni portrait request #{$commissionId} {$label}");
        } catch (GatewayException) {
            return $this->fail(502, 'payment_unavailable');
        }

        return ['ok' => true] + $started;
    }

    private function fail(int $status, string $error, array $extra = []): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'extra' => $extra];
    }
}
