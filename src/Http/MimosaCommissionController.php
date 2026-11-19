<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Commissions\Pricing;
use App\Commissions\Workflow;
use App\Config;
use App\Payments\Locking;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MimosaCommissionController
{
    private const STATUSES = ['submitted', 'info_requested', 'customer_replied', 'quoted', 'deposit_paid', 'in_progress', 'completed', 'awaiting_balance', 'shipped', 'denied', 'abandoned', 'stopped', 'refunded'];
    private const ACTIVE = ['submitted', 'info_requested', 'customer_replied', 'quoted', 'deposit_paid', 'in_progress', 'completed', 'awaiting_balance'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Pricing $pricing,
        private readonly AuditLog $audit,
        private readonly Workflow $workflow,
        private readonly Config $config,
    ) {
    }

    public function overview(Request $request, Response $response): Response
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM commissions GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        $placeholders = implode(',', array_fill(0, count(self::ACTIVE), '?'));
        $st = $this->pdo->prepare(
            "SELECT c.id, c.subject_type, c.status, c.pet_count, c.person_count, c.quoted_cents, c.created_at,
                    cs.label AS size, u.email, u.display_name
             FROM commissions c
             JOIN canvas_sizes cs ON cs.id = c.canvas_size_id
             JOIN users u ON u.id = c.user_id
             WHERE c.status IN ({$placeholders})
             ORDER BY c.created_at DESC, c.id DESC
             LIMIT 10"
        );
        $st->execute(self::ACTIVE);

        $recent = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'subject_type' => (string) $r['subject_type'],
            'status' => (string) $r['status'],
            'pet_count' => (int) $r['pet_count'],
            'person_count' => (int) $r['person_count'],
            'quoted_cents' => (int) $r['quoted_cents'],
            'created_at' => (string) $r['created_at'],
            'size' => (string) $r['size'],
            'customer' => (string) ($r['display_name'] ?? '') !== '' ? (string) $r['display_name'] : (string) $r['email'],
        ], $st->fetchAll());

        return Json::write($response, [
            'availability' => $this->pricing->availability(),
            'counts' => $counts,
            'recent' => $recent,
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $st = $this->pdo->prepare(
            'SELECT c.*, o.id AS order_id, cs.label AS size, u.email, u.display_name FROM commissions c
             JOIN orders o ON o.commission_id = c.id JOIN canvas_sizes cs ON cs.id = c.canvas_size_id JOIN users u ON u.id = c.user_id WHERE c.id = ?'
        );
        $st->execute([$id]);
        $c = $st->fetch();
        if ($c === false) {
            return $this->err($response, 404, 'not_found');
        }
        $files = $this->pdo->prepare('SELECT id, original_name FROM commission_files WHERE commission_id = ? ORDER BY id');
        $files->execute([$id]);
        $ev = $this->pdo->prepare(
            'SELECT e.kind, e.from_status, e.to_status, e.note, e.emailed, e.created_at, u.display_name, u.email
             FROM commission_events e LEFT JOIN users u ON u.id = e.actor_user_id WHERE e.commission_id = ? ORDER BY e.id'
        );
        $ev->execute([$id]);

        return Json::write($response, ['commission' => [
            'id' => (int) $c['id'],
            'user_id' => (int) $c['user_id'],
            'customer' => (string) ($c['display_name'] ?? '') !== '' ? (string) $c['display_name'] : (string) $c['email'],
            'email' => (string) $c['email'],
            'subject_type' => (string) $c['subject_type'],
            'status' => (string) $c['status'],
            'next_statuses' => Workflow::next((string) $c['status']),
            'size' => (string) $c['size'],
            'pet_count' => (int) $c['pet_count'],
            'person_count' => (int) $c['person_count'],
            'quoted_cents' => (int) $c['quoted_cents'],
            'deposit_cents' => intdiv((int) $c['quoted_cents'] * $this->pricing->depositPercent() + 99, 100),
            'remaining_base_cents' => max(0, (int) $c['quoted_cents'] - $this->paidBase((int) $c['order_id'], $id)),
            'delivery' => (string) $c['delivery'],
            'postal_code' => $c['postal_code'],
            'description' => (string) $c['description'],
            'terms_version' => (string) $c['terms_version'],
            'terms_accepted_at' => (string) $c['terms_accepted_at'],
            'created_at' => (string) $c['created_at'],
            'files' => array_map(static fn (array $f): array => ['id' => (int) $f['id'], 'original_name' => (string) $f['original_name']], $files->fetchAll()),
            'external_payments' => $this->externalPayments($id),
            'events' => array_map(static fn (array $e): array => [
                'kind' => (string) $e['kind'],
                'from' => $e['from_status'],
                'to' => $e['to_status'],
                'note' => $e['note'],
                'emailed' => (bool) $e['emailed'],
                'by' => (string) ($e['display_name'] ?? '') !== '' ? (string) $e['display_name'] : $e['email'],
                'created_at' => (string) $e['created_at'],
            ], $ev->fetchAll()),
        ]]);
    }

    public function recordExternalPayment(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        if (! is_array($body) || ! in_array($body['kind'] ?? null, ['deposit', 'full', 'balance'], true)
            || ! in_array($body['method'] ?? null, ['paypal', 'venmo', 'other'], true)) {
            return $this->err($response, 400, 'invalid_request');
        }
        $reference = $body['reference'] ?? '';
        if (! is_string($reference) || mb_strlen($reference) > 120) {
            return $this->err($response, 422, 'invalid_reference');
        }
        $reference = trim(preg_replace('/[\\x00-\\x1F\\x7F]/', '', $reference) ?? '');
        $id = (int) $args['id'];
        $actor = (int) $request->getAttribute('user')['id'];
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "SELECT c.status, c.quoted_cents, o.id AS order_id FROM commissions c JOIN orders o ON o.commission_id = c.id WHERE c.id = ?" . Locking::forUpdate($this->pdo)
            );
            $st->execute([$id]);
            $commission = $st->fetch();
            if ($commission === false) {
                $this->pdo->rollBack();

                return $this->err($response, 404, 'not_found');
            }
            $isUpfront = in_array($body['kind'], ['deposit', 'full'], true);
            $validStatus = $isUpfront
                ? $commission['status'] === 'quoted'
                : in_array($commission['status'], ['completed', 'awaiting_balance'], true);
            if (! $validStatus) {
                $this->pdo->rollBack();

                return $this->err($response, 409, 'not_payable');
            }
            $existing = $isUpfront
                ? $this->pdo->prepare("SELECT 1 FROM payments WHERE order_id = ? AND status IN ('pending', 'succeeded') UNION ALL SELECT 1 FROM commission_external_payments WHERE commission_id = ? LIMIT 1")
                : $this->pdo->prepare("SELECT 1 FROM payments WHERE order_id = ? AND kind = 'balance' AND status IN ('pending', 'succeeded') UNION ALL SELECT 1 FROM commission_external_payments WHERE commission_id = ? AND kind = 'balance' LIMIT 1");
            $existing->execute([$commission['order_id'], $id]);
            if ($existing->fetchColumn() !== false) {
                $this->pdo->rollBack();

                return $this->err($response, 409, 'payment_exists');
            }
            $amount = match ($body['kind']) {
                'full' => (int) $commission['quoted_cents'],
                'deposit' => intdiv((int) $commission['quoted_cents'] * $this->pricing->depositPercent() + 99, 100),
                'balance' => max(0, (int) $commission['quoted_cents'] - $this->paidBase((int) $commission['order_id'], $id)),
            };
            if ($amount === 0) {
                $this->pdo->rollBack();

                return $this->err($response, 409, 'not_payable');
            }
            $this->pdo->prepare(
                'INSERT INTO commission_external_payments (commission_id, kind, method, amount_cents, reference, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $body['kind'], $body['method'], $amount, $reference === '' ? null : $reference, $actor, $now]);
            $this->pdo->prepare('UPDATE orders SET paid_cents = paid_cents + ?, updated_at = ? WHERE id = ?')
                ->execute([$amount, $now, $commission['order_id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $transition = $isUpfront ? $this->workflow->transition($id, 'deposit_paid', null, $actor) : ['ok' => true, 'emailed' => false];
        $this->audit->record('commission_external_payment', $actor, 'commission', (string) $id, [
            'kind' => $body['kind'], 'method' => $body['method'], 'amount_cents' => $amount,
        ], ClientIp::from($request), $request->getHeaderLine('User-Agent'));
        if (! $transition['ok']) {
            return $this->err($response, 500, 'payment_recorded_status_update_failed');
        }

        return Json::write($response, ['ok' => true, 'amount_cents' => $amount, 'emailed' => $transition['emailed']]);
    }

    private function externalPayments(int $commissionId): array
    {
        $st = $this->pdo->prepare(
            'SELECT kind, method, amount_cents, reference, created_at FROM commission_external_payments WHERE commission_id = ? ORDER BY id'
        );
        $st->execute([$commissionId]);

        return array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'],
            'method' => (string) $r['method'],
            'amount_cents' => (int) $r['amount_cents'],
            'reference' => $r['reference'],
            'created_at' => (string) $r['created_at'],
        ], $st->fetchAll());
    }

    private function paidBase(int $orderId, int $commissionId): int
    {
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(base_cents), 0) FROM payments WHERE order_id = ? AND status = 'succeeded'
             UNION ALL SELECT COALESCE(SUM(amount_cents), 0) FROM commission_external_payments WHERE commission_id = ?"
        );
        $st->execute([$orderId, $commissionId]);
        $amounts = $st->fetchAll(\PDO::FETCH_COLUMN);

        return (int) $amounts[0] + (int) $amounts[1];
    }

    public function file(Request $request, Response $response, array $args): Response
    {
        $st = $this->pdo->prepare('SELECT stored_name, mime FROM commission_files WHERE id = ? AND commission_id = ?');
        $st->execute([(int) $args['fid'], (int) $args['id']]);
        $f = $st->fetch();
        $path = $f === false ? '' : $this->config->root . '/storage/uploads/commissions/' . (int) $args['id'] . '/' . $f['stored_name'];
        if ($f === false || ! is_file($path)) {
            return $this->err($response, 404, 'not_found');
        }
        $response->getBody()->write((string) file_get_contents($path));

        return $response->withHeader('Content-Type', (string) $f['mime'])
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=300');
    }

    public function setStatus(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody();
        $to = is_array($body) ? ($body['status'] ?? null) : null;
        $note = is_array($body) && is_string($body['note'] ?? null) ? $body['note'] : null;
        if (! is_string($to)) {
            return $this->err($response, 400, 'invalid_request');
        }
        $actor = (int) $request->getAttribute('user')['id'];
        $r = $this->workflow->transition((int) $args['id'], $to, $note, $actor);
        if (! $r['ok']) {
            $code = $r['error'];

            return $this->err($response, $code === 'not_found' ? 404 : ($code === 'save_failed' ? 500 : ($code === 'conflict' ? 409 : 422)), $code);
        }
        $this->audit->record('commission_status', $actor, 'commission', (string) $args['id'], ['to' => $to, 'emailed' => $r['emailed']], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['ok' => true, 'emailed' => $r['emailed']]);
    }

    public function orders(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $where = [];
        $args = [];
        if (is_string($q['kind'] ?? null) && in_array($q['kind'], ['commission', 'sale'], true)) {
            $where[] = 'o.kind = ?';
            $args[] = $q['kind'];
        }
        if (is_string($q['status'] ?? null) && $q['status'] !== '' && preg_match('/^[a-z_]{1,32}$/', $q['status']) === 1) {
            $where[] = 'o.status = ?';
            $args[] = $q['status'];
        }
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $sql = 'FROM orders o JOIN users u ON u.id = o.user_id
                LEFT JOIN commissions c ON c.id = o.commission_id
                LEFT JOIN canvas_sizes cs ON cs.id = c.canvas_size_id
                LEFT JOIN pieces p ON p.id = o.piece_id' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        $count = $this->pdo->prepare('SELECT COUNT(*) ' . $sql);
        $count->execute($args);
        $st = $this->pdo->prepare(
            'SELECT o.id, o.kind, o.commission_id, o.piece_id, o.status, o.total_cents, o.paid_cents, o.created_at, u.email, u.display_name,
                    c.subject_type, cs.label AS size, COALESCE((SELECT GROUP_CONCAT(pp.title) FROM order_items oi JOIN pieces pp ON pp.id = oi.piece_id WHERE oi.order_id = o.id), p.title) AS title ' . $sql . ' ORDER BY o.created_at DESC, o.id DESC LIMIT 50 OFFSET ' . $offset
        );
        $st->execute($args);

        return Json::write($response, ['total' => (int) $count->fetchColumn(), 'orders' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'commission_id' => $r['commission_id'] === null ? null : (int) $r['commission_id'],
            'piece_id' => $r['piece_id'] === null ? null : (int) $r['piece_id'],
            'status' => (string) $r['status'],
            'total_cents' => (int) $r['total_cents'],
            'paid_cents' => (int) $r['paid_cents'],
            'created_at' => (string) $r['created_at'],
            'customer' => (string) ($r['display_name'] ?? '') !== '' ? (string) $r['display_name'] : (string) $r['email'],
            'item' => $r['kind'] === 'commission'
                ? ($r['subject_type'] === 'pet' ? 'Pet portrait' : 'Portrait') . ' · ' . $r['size']
                : (string) $r['title'],
        ], $st->fetchAll())]);
    }

    public function pricing(Request $request, Response $response): Response
    {
        return Json::write($response, [
            'sizes' => $this->pricing->table(),
            'fees' => $this->pricing->fees(),
            'deposit_percent' => $this->pricing->depositPercent(),
        ]);
    }

    public function savePricing(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        if (! is_array($body)) {
            return $this->err($response, 400, 'invalid_request');
        }

        $known = [];
        foreach ($this->pricing->table() as $row) {
            $known[$row['canvas_size_id']] = true;
        }

        $prices = [];
        if (array_key_exists('prices', $body)) {
            if (! is_array($body['prices']) || ! array_is_list($body['prices']) || count($body['prices']) > 200) {
                return $this->err($response, 400, 'invalid_request');
            }
            foreach ($body['prices'] as $p) {
                $cents = is_array($p) && array_key_exists('price_cents', $p) ? $this->cents($p['price_cents'], true) : false;
                if (! is_array($p) || ! in_array($p['subject_type'] ?? null, Pricing::SUBJECTS, true)
                    || ! is_int($p['canvas_size_id'] ?? null) || ! isset($known[$p['canvas_size_id']]) || $cents === false) {
                    return $this->err($response, 400, 'invalid_request');
                }
                $prices[] = ['subject_type' => $p['subject_type'], 'canvas_size_id' => $p['canvas_size_id'], 'price_cents' => $cents];
            }
        }

        $fees = [];
        if (array_key_exists('fees', $body)) {
            if (! is_array($body['fees']) || array_diff(array_keys($body['fees']), array_keys(Pricing::FEE_KEYS)) !== []) {
                return $this->err($response, 400, 'invalid_request');
            }
            foreach ($body['fees'] as $name => $value) {
                $cents = $this->cents($value, false);
                if ($cents === false || $cents === null) {
                    return $this->err($response, 400, 'invalid_request');
                }
                $fees[$name] = $cents;
            }
        }

        $deposit = null;
        if (array_key_exists('deposit_percent', $body)) {
            if (! is_int($body['deposit_percent']) || $body['deposit_percent'] < 1 || $body['deposit_percent'] > 100) {
                return $this->err($response, 400, 'invalid_request');
            }
            $deposit = $body['deposit_percent'];
        }

        if ($prices !== []) {
            $this->pricing->savePrices($prices);
        }
        if ($fees !== []) {
            $this->pricing->saveFees($fees);
        }
        if ($deposit !== null) {
            $this->pricing->saveDepositPercent($deposit);
        }
        $this->log($request, 'commission_pricing_updated', 'pricing', ['prices' => count($prices), 'fees' => array_keys($fees), 'deposit_percent' => $deposit]);

        return $this->pricing($request, $response);
    }

    public function saveAvailability(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body['availability'] ?? null) : null;
        if (! is_string($value) || ! in_array($value, Pricing::AVAILABILITY, true)) {
            return $this->err($response, 400, 'invalid_request');
        }
        $this->pricing->saveAvailability($value);
        $this->log($request, 'commission_availability_updated', 'availability', ['availability' => $value]);

        return Json::write($response, ['availability' => $value]);
    }

    private function cents(mixed $value, bool $nullable): int|null|false
    {
        if ($value === null) {
            return $nullable ? null : false;
        }

        return is_int($value) && $value >= 0 && $value <= Pricing::MAX_CENTS ? $value : false;
    }

    private function log(Request $request, string $action, string $target, array $details): void
    {
        $this->audit->record($action, (int) $request->getAttribute('user')['id'], 'commission_settings', $target, $details, ClientIp::from($request), $request->getHeaderLine('User-Agent'));
    }

    private function err(Response $response, int $status, string $error): Response
    {
        return Json::write($response, ['error' => $error], $status);
    }
}
