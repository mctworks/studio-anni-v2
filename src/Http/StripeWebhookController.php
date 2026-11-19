<?php

declare(strict_types=1);

namespace App\Http;

use App\Payments\GatewayException;
use App\Payments\PaymentGateway;
use App\Payments\PaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StripeWebhookController
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentService $payments,
        private readonly \PDO $pdo,
    ) {
    }

    public function handle(Request $request, Response $response): Response
    {
        try {
            $event = $this->gateway->verifyEvent((string) $request->getBody(), $request->getHeaderLine('Stripe-Signature'));
        } catch (GatewayException $e) {
            return Json::write($response, ['error' => $e->reason], $e->reason === GatewayException::NOT_CONFIGURED ? 503 : 400);
        }

        $seen = $this->pdo->prepare('SELECT 1 FROM stripe_events WHERE id = ?');
        $seen->execute([$event['id']]);
        if ($seen->fetchColumn() !== false) {
            return Json::write($response, ['ok' => true]);
        }

        if ($event['type'] === 'payment_intent.succeeded' && $event['object_id'] !== null) {
            $this->payments->settle($event['object_id']);
        }

        $this->pdo->prepare('INSERT INTO stripe_events (id, type, received_at) VALUES (?, ?, ?)')->execute([$event['id'], $event['type'], gmdate('Y-m-d H:i:s')]);

        return Json::write($response, ['ok' => true]);
    }
}
