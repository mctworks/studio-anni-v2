<?php

declare(strict_types=1);

namespace App\Payments;

use App\Commissions\Workflow;
use App\Config;
use App\Mail\Mailer;
use Stripe\Exception\ApiErrorException;

class PaymentService
{
    public const SALE_HOLD_SECONDS = 1200;
    public const COMMISSION_HOLD_SECONDS = 3600;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly PaymentGateway $gateway,
        private readonly Workflow $workflow,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {
    }

    public function open(int $orderId, string $kind, int $base, int $shipping, int $tax, int $holdSeconds, ?string $terms, string $ip): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO payments (order_id, kind, status, base_cents, shipping_cents, tax_cents, amount_cents, terms_version, terms_accepted_at, terms_accepted_ip, expires_at, created_at, updated_at)
             VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $orderId, $kind, $base, $shipping, $tax, $base + $shipping + $tax,
            $terms, $terms === null ? null : $now, $terms === null ? null : \App\Auth\ClientIp::pack($ip),
            gmdate('Y-m-d H:i:s', time() + $holdSeconds), $now, $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{payment_id: int, client_secret: string, publishable_key: string, amount_cents: int} @throws GatewayException */
    public function start(int $paymentId, string $description): array
    {
        $p = $this->payment($paymentId);
        try {
            $intent = $this->gateway->createIntent(
                (int) $p['amount_cents'],
                ['payment_id' => (string) $paymentId, 'order_id' => (string) $p['order_id'], 'kind' => (string) $p['kind']],
                $description,
                'sa-pay-' . $paymentId,
            );
            $this->pdo->prepare('UPDATE payments SET stripe_payment_intent_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$intent['id'], gmdate('Y-m-d H:i:s'), $paymentId]);
        } catch (\Throwable $e) {
            $this->logStartFailure($paymentId, $e);
            $this->markCanceled($paymentId);
            throw $e;
        }

        return [
            'payment_id' => $paymentId,
            'client_secret' => $intent['client_secret'],
            'publishable_key' => $this->gateway->publishableKey(),
            'amount_cents' => (int) $p['amount_cents'],
        ];
    }

    private function logStartFailure(int $paymentId, \Throwable $e): void
    {
        $previous = $e->getPrevious();
        $entry = [
            'time' => gmdate('Y-m-d H:i:s'),
            'event' => 'payment_start_failed',
            'payment_id' => $paymentId,
            'exception' => $e::class,
            'reason' => $e instanceof GatewayException ? $e->reason : null,
        ];
        if ($previous instanceof ApiErrorException) {
            $entry['stripe_http_status'] = $previous->getHttpStatus();
            $entry['stripe_error_code'] = $previous->getStripeCode();
            $entry['stripe_request_id'] = $previous->getRequestId();
            $error = $previous->getError();
            if ($error !== null) {
                $entry['stripe_error_type'] = $error->type ?? null;
                $entry['stripe_detail_code'] = $error->code ?? null;
                $entry['stripe_parameter'] = $error->param ?? null;
            }
        }

        $directory = $this->config->root . '/storage/logs';
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            return;
        }
        $path = $directory . '/application.log';
        if (@file_put_contents($path, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
            @chmod($path, 0640);
        }
    }

    public function cancelPendingForOrder(int $orderId): void
    {
        $st = $this->pdo->prepare("SELECT id FROM payments WHERE order_id = ? AND status = 'pending'");
        $st->execute([$orderId]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->cancel((int) $id);
        }
    }

    public function cancelPendingForUser(int $userId, string $orderKind): void
    {
        $st = $this->pdo->prepare(
            "SELECT p.id FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.user_id = ? AND o.kind = ? AND p.status = 'pending'"
        );
        $st->execute([$userId, $orderKind]);
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->cancel((int) $id);
        }
    }

    public function cancel(int $paymentId): void
    {
        $p = $this->payment($paymentId);
        if ($p['status'] !== 'pending') {
            return;
        }
        $intent = $p['stripe_payment_intent_id'];
        if ($intent !== null) {
            try {
                $this->gateway->cancel((string) $intent);
            } catch (GatewayException) {
                if ($this->settle((string) $intent)['status'] === 'succeeded') {
                    return;
                }
            }
        }
        $this->markCanceled($paymentId);
    }

    public function expireStale(): int
    {
        $st = $this->pdo->prepare("SELECT id FROM payments WHERE status = 'pending' AND expires_at < ? ORDER BY id LIMIT 200");
        $st->execute([gmdate('Y-m-d H:i:s')]);
        $n = 0;
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            try {
                $this->cancel((int) $id);
                $n++;
            } catch (\Throwable $e) {
                error_log('Payment expiry failed for ' . $id . ': ' . $e->getMessage());
            }
        }

        return $n;
    }

    /** @return array{status: string, payment_id?: int, kind?: string, order_id?: int, commission_id?: ?int} */
    public function settle(string $intentId): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.*, o.kind AS order_kind, o.user_id, o.commission_id FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.stripe_payment_intent_id = ?'
        );
        $st->execute([$intentId]);
        $p = $st->fetch();
        if ($p === false) {
            return ['status' => 'unknown'];
        }
        $info = $this->info($p);
        if (in_array($p['status'], ['succeeded', 'refunded'], true)) {
            return ['status' => (string) $p['status']] + $info;
        }

        $intent = $this->gateway->retrieve($intentId);
        if ($intent['status'] !== 'succeeded') {
            return ['status' => $intent['status'] === 'processing' ? 'processing' : 'pending'] + $info;
        }
        if ($intent['amount'] !== (int) $p['amount_cents'] || $intent['received'] !== (int) $p['amount_cents'] || $intent['currency'] !== 'usd') {
            $this->notifyAdmin('Payment amount mismatch', "Payment #{$p['id']} intent {$intentId}: expected " . Address::money((int) $p['amount_cents']) . ", Stripe reports {$intent['received']} cents {$intent['currency']}. Not settled.");

            return ['status' => 'mismatch'] + $info;
        }

        $now = gmdate('Y-m-d H:i:s');
        $conflict = false;
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM payments WHERE id = ?' . Locking::forUpdate($this->pdo));
            $lock->execute([$p['id']]);
            if (in_array($lock->fetchColumn(), ['succeeded', 'refunded'], true)) {
                $this->pdo->rollBack();

                return $this->settle($intentId);
            }

            if ($p['order_kind'] === 'sale') {
                $ids = $this->orderPieceIds((int) $p['order_id']);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $rows = $this->pdo->prepare("SELECT id FROM pieces WHERE id IN ({$in}) AND status = 'available' AND deleted_at IS NULL" . Locking::forUpdate($this->pdo));
                $rows->execute($ids);
                $conflict = count($rows->fetchAll()) !== count($ids);
                if (! $conflict) {
                    $this->pdo->prepare("UPDATE pieces SET status = 'sold', sold_at = ? WHERE id IN ({$in})")->execute([$now, ...$ids]);
                    $this->pdo->prepare("DELETE FROM cart_items WHERE piece_id IN ({$in})")->execute($ids);
                }
            }

            if ($conflict) {
                $this->pdo->prepare("UPDATE payments SET status = 'refunded', updated_at = ? WHERE id = ?")->execute([$now, $p['id']]);
                $this->pdo->prepare("UPDATE orders SET status = 'canceled', updated_at = ? WHERE id = ?")->execute([$now, $p['order_id']]);
            } else {
                $this->pdo->prepare("UPDATE payments SET status = 'succeeded', paid_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $p['id']]);
                $this->pdo->prepare(
                    'UPDATE orders SET paid_cents = paid_cents + ?, status = CASE WHEN kind = ? THEN ? ELSE status END, updated_at = ? WHERE id = ?'
                )->execute([$p['amount_cents'], 'sale', 'paid', $now, $p['order_id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($conflict) {
            $this->refundConflict($p, $intentId);

            return ['status' => 'refunded'] + $info;
        }

        $this->afterPaid($p);

        return ['status' => 'succeeded'] + $info;
    }

    private function afterPaid(array $p): void
    {
        $amount = Address::money((int) $p['amount_cents']);
        $user = $this->user((int) $p['user_id']);
        $emailed = false;

        if ($p['order_kind'] === 'commission') {
            $cid = (int) $p['commission_id'];
            $c = $this->pdo->prepare('SELECT status FROM commissions WHERE id = ?');
            $c->execute([$cid]);
            $status = (string) $c->fetchColumn();
            if ($p['kind'] === 'deposit' && $status === 'quoted') {
                $emailed = ($this->workflow->transition($cid, 'deposit_paid', null, null)['emailed'] ?? false) === true;
            }
            $this->notifyAdmin(
                "Payment received for portrait request #{$cid}",
                "{$amount} ({$p['kind']}) from {$user['email']}. Commission status: {$status}.\n" . $this->base() . "/mimosa/commissions/{$cid}\n"
            );
            if (! $emailed) {
                $this->mailer->send($user['email'], "Payment received for your portrait request #{$cid}", "Hi {$user['name']},\n\nThank you! We received your payment of {$amount}.\n\n" . $this->base() . "/account/commissions/{$cid}\n\n- Studio Anni\n");
            }

            return;
        }

        $titles = $this->pdo->prepare('SELECT p.title FROM order_items oi JOIN pieces p ON p.id = oi.piece_id WHERE oi.order_id = ? ORDER BY oi.id');
        $titles->execute([$p['order_id']]);
        $list = implode("\n", array_map(static fn (string $t): string => "- {$t}", $titles->fetchAll(\PDO::FETCH_COLUMN)));
        $ship = $this->pdo->prepare('SELECT method FROM order_shipments WHERE order_id = ?');
        $ship->execute([$p['order_id']]);
        $how = $ship->fetchColumn() === 'pickup' ? 'Local pickup: we will contact you to arrange it.' : 'We will email you once your order ships.';
        $this->mailer->send(
            $user['email'],
            "Your Studio Anni order #{$p['order_id']}",
            "Hi {$user['name']},\n\nThank you for your order! We received your payment of {$amount}.\n\n{$list}\n\n{$how}\n\n- Studio Anni\n"
        );
        $this->notifyAdmin("New order #{$p['order_id']}", "{$amount} from {$user['email']}\n{$list}\n" . $this->base() . "/mimosa/orders\n");
    }

    private function refundConflict(array $p, string $intentId): void
    {
        $user = $this->user((int) $p['user_id']);
        try {
            $this->gateway->refund($intentId, 'sa-refund-' . $p['id']);
            $this->mailer->send($user['email'], "Your Studio Anni order #{$p['order_id']} was refunded", "Hi {$user['name']},\n\nSomeone else bought one of the pieces in your order moments before your payment cleared, so we refunded your payment in full. We're sorry about that!\n\n- Studio Anni\n");
            $this->notifyAdmin("Order #{$p['order_id']} auto-refunded", "A piece sold out from under payment #{$p['id']}. Refund issued.");
        } catch (GatewayException $e) {
            $this->notifyAdmin("REFUND NEEDED: order #{$p['order_id']}", "Payment #{$p['id']} ({$intentId}) was taken for a piece that is no longer available and the automatic refund failed: {$e->getMessage()}");
        }
    }

    private function markCanceled(int $paymentId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $up = $this->pdo->prepare("UPDATE payments SET status = 'canceled', updated_at = ? WHERE id = ? AND status = 'pending'");
        $up->execute([$now, $paymentId]);
        if ($up->rowCount() === 1) {
            $this->pdo->prepare(
                "UPDATE orders SET status = 'canceled', updated_at = ? WHERE kind = 'sale' AND status = 'pending' AND id = (SELECT order_id FROM payments WHERE id = ?)"
            )->execute([$now, $paymentId]);
        }
    }

    /** @return list<int> */
    private function orderPieceIds(int $orderId): array
    {
        $st = $this->pdo->prepare('SELECT piece_id FROM order_items WHERE order_id = ? ORDER BY piece_id');
        $st->execute([$orderId]);

        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function payment(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $st->execute([$id]);

        return $st->fetch() ?: throw new \RuntimeException("Payment {$id} not found");
    }

    private function info(array $p): array
    {
        return ['payment_id' => (int) $p['id'], 'kind' => (string) $p['kind'], 'order_id' => (int) $p['order_id'], 'commission_id' => $p['commission_id'] === null ? null : (int) $p['commission_id']];
    }

    private function user(int $id): array
    {
        $st = $this->pdo->prepare("SELECT email, COALESCE(NULLIF(display_name, ''), email) AS name FROM users WHERE id = ?");
        $st->execute([$id]);
        $r = $st->fetch();

        return ['email' => (string) ($r['email'] ?? ''), 'name' => (string) ($r['name'] ?? '')];
    }

    private function notifyAdmin(string $subject, string $body): void
    {
        $to = $this->config->get('MAIL_FROM') ?? $this->config->get('MAIL_USER');
        if ($to !== null) {
            $this->mailer->send($to, $subject, $body);
        }
    }

    private function base(): string
    {
        return rtrim((string) $this->config->get('APP_URL', ''), '/');
    }
}
