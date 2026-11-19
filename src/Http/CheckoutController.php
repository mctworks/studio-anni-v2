<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\ClientIp;
use App\Auth\RateLimiter;
use App\Payments\CheckoutService;
use App\Payments\GatewayException;
use App\Payments\PaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CheckoutController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
        private readonly RateLimiter $limits,
        private readonly \PDO $pdo,
    ) {
    }

    public function quote(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        if ($this->limits->tooMany('checkout_quote', (string) $userId, 40, 600)) {
            return Json::write($response, ['error' => 'rate_limited'], 429);
        }
        $this->limits->hit('checkout_quote', (string) $userId);
        $body = $this->body($request);

        return $this->out($response, $this->checkout->quote($userId, $body['method'] ?? null, $body['address'] ?? null));
    }

    public function pay(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        if ($this->limits->tooMany('checkout_pay', (string) $userId, 15, 600)) {
            return Json::write($response, ['error' => 'rate_limited'], 429);
        }
        $this->limits->hit('checkout_pay', (string) $userId);

        return $this->out($response, $this->checkout->pay($userId, $this->body($request), ClientIp::from($request)));
    }

    public function finalize(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        $id = $this->body($request)['payment_id'] ?? null;
        $st = $this->pdo->prepare('SELECT p.stripe_payment_intent_id FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ? AND o.user_id = ?');
        $st->execute([is_int($id) ? $id : 0, $userId]);
        $intent = $st->fetchColumn();
        if (! is_string($intent)) {
            return Json::write($response, ['error' => 'not_found'], 404);
        }
        try {
            return Json::write($response, $this->payments->settle($intent));
        } catch (GatewayException) {
            return Json::write($response, ['error' => 'payment_unavailable'], 502);
        }
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

        return Json::write($response, ['error' => $r['error']] + $r['extra'], $r['status']);
    }
}
