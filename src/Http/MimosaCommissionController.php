<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Commissions\Pricing;
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
