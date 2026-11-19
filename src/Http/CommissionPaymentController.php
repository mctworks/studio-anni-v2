<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\ClientIp;
use App\Auth\RateLimiter;
use App\Payments\CommissionPayments;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CommissionPaymentController
{
    public function __construct(private readonly CommissionPayments $payments, private readonly RateLimiter $limits)
    {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $s = $this->payments->state((int) $request->getAttribute('user')['id'], (int) $args['id']);
        if ($s === null) {
            return Json::write($response, ['error' => 'not_found'], 404);
        }
        unset($s['order_id'], $s['long_in'], $s['short_in']);

        return Json::write($response, ['payment' => $s]);
    }

    public function quote(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        if ($this->limits->tooMany('checkout_quote', (string) $userId, 40, 600)) {
            return Json::write($response, ['error' => 'rate_limited'], 429);
        }
        $this->limits->hit('checkout_quote', (string) $userId);
        $b = $this->body($request);
        $r = $this->payments->quote($userId, (int) $args['id'], $b['option'] ?? null, $b['address'] ?? null);
        if ($r['ok']) {
            unset($r['quote']['order_id']);
        }

        return $this->out($response, $r);
    }

    public function pay(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        if ($this->limits->tooMany('checkout_pay', (string) $userId, 15, 600)) {
            return Json::write($response, ['error' => 'rate_limited'], 429);
        }
        $this->limits->hit('checkout_pay', (string) $userId);

        return $this->out($response, $this->payments->pay($userId, (int) $args['id'], $this->body($request), ClientIp::from($request)));
    }

    private function body(Request $request): array
    {
        $b = $request->getParsedBody();

        return is_array($b) ? $b : [];
    }

    private function out(Response $response, array $r): Response
    {
        if ($r['ok']) {
            unset($r['ok']);

            return Json::write($response, $r);
        }
        if (isset($r['extra']['quote'])) {
            unset($r['extra']['quote']['order_id']);
        }

        return Json::write($response, ['error' => $r['error']] + $r['extra'], $r['status']);
    }
}
