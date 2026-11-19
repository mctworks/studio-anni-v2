<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Contact\ContactSenders;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MimosaContactController
{
    private const PAGE = 50;

    public function __construct(private readonly ContactSenders $senders, private readonly AuditLog $audit)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $offset = $request->getQueryParams()['offset'] ?? '0';
        if (! is_string($offset) || ! ctype_digit($offset) || strlen($offset) > 7) {
            return $this->err($response, 400, 'invalid_request');
        }

        [$total, $rows] = $this->senders->messages((int) $offset, self::PAGE);
        $blocks = $this->senders->all();
        $messages = array_map(fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
            'source' => (string) $r['source'],
            'excerpt' => (string) $r['excerpt'],
            'created_at' => (string) $r['created_at'],
            'blocked' => ContactSenders::flags($blocks, (string) $r['ip'], (string) $r['email']),
        ], $rows);

        return Json::write($response, ['total' => $total, 'messages' => $messages]);
    }

    public function blocks(Request $request, Response $response): Response
    {
        $blocks = array_map(static fn (array $b): array => [
            'id' => (int) $b['id'],
            'kind' => (string) $b['kind'],
            'value' => (string) $b['value'],
            'reason' => $b['reason'] === null ? null : (string) $b['reason'],
            'hits' => (int) $b['hits'],
            'last_hit_at' => $b['last_hit_at'] === null ? null : (string) $b['last_hit_at'],
            'created_at' => (string) $b['created_at'],
        ], $this->senders->all());

        return Json::write($response, ['blocks' => $blocks]);
    }

    public function add(Request $request, Response $response): Response
    {
        $in = $request->getParsedBody();
        $kind = is_array($in) && is_string($in['kind'] ?? null) ? $in['kind'] : '';
        $value = is_array($in) && is_string($in['value'] ?? null) ? $in['value'] : '';
        $reason = is_array($in) && is_string($in['reason'] ?? null) ? $in['reason'] : null;

        return $this->save($request, $response, $kind, $value, $reason);
    }

    public function blockFromMessage(Request $request, Response $response, array $args): Response
    {
        $row = $this->senders->message((int) $args['id']);
        if ($row === null) {
            return $this->err($response, 404, 'not_found');
        }

        $in = $request->getParsedBody();
        $kind = is_array($in) && is_string($in['kind'] ?? null) ? $in['kind'] : '';
        $value = match ($kind) {
            'email' => (string) $row['email'],
            'domain' => (string) ContactSenders::domainOf((string) $row['email']),
            'ip' => (string) $row['ip'],
            default => '',
        };

        return $this->save($request, $response, $kind, $value, 'From the contact form');
    }

    public function remove(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if (! $this->senders->remove($id)) {
            return $this->err($response, 404, 'not_found');
        }
        $this->audit->record('contact_unblock', (int) $request->getAttribute('user')['id'], 'blocked_sender', (string) $id, [], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return $response->withStatus(204);
    }

    private function save(Request $request, Response $response, string $kind, string $value, ?string $reason): Response
    {
        $ip = ClientIp::from($request);
        if ($kind === 'ip' && ContactSenders::canonicalIp($value) === ContactSenders::canonicalIp($ip)) {
            return $this->err($response, 422, 'own_ip');
        }

        $userId = (int) $request->getAttribute('user')['id'];
        [$id, $error] = $this->senders->add($kind, $value, $reason, $userId);
        if ($error !== null) {
            return $this->err($response, $error === 'exists' ? 409 : 422, $error);
        }
        $this->audit->record('contact_block', $userId, 'blocked_sender', (string) $id, ['kind' => $kind], $ip, $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['id' => $id], 201);
    }

    private function err(Response $response, int $status, string $error): Response
    {
        return Json::write($response, ['error' => $error], $status);
    }
}
