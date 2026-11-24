<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Comments\Comments;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MimosaReplyController
{
    public function __construct(private readonly Comments $comments, private readonly AuditLog $audit)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $status = $q['status'] ?? null;
        $kind = $q['kind'] ?? null;
        $userId = $q['user_id'] ?? null;
        $limit = $q['limit'] ?? '25';
        $offset = $q['offset'] ?? '0';
        if (($status !== null && ! in_array($status, Comments::STATUSES, true))
            || ($kind !== null && ! in_array($kind, Comments::KINDS, true))
            || ($userId !== null && (! is_string($userId) || ! ctype_digit($userId)))
            || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100
            || ! is_string($offset) || ! ctype_digit($offset)) {
            return Json::write($response, ['error' => 'invalid_request'], 400);
        }
        $r = $this->comments->moderation($status, $kind, $userId === null ? null : (int) $userId, (int) $limit, (int) $offset);

        return Json::write($response, ['data' => $r['rows'], 'meta' => ['total' => $r['total'], 'pending' => $r['pending'], 'limit' => (int) $limit, 'offset' => (int) $offset]]);
    }

    /** @param array<string, string> $args */
    public function setStatus(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = $request->getParsedBody();
        $status = is_array($body) ? ($body['status'] ?? null) : null;
        if (! is_string($status) || ! in_array($status, Comments::STATUSES, true)) {
            return Json::write($response, ['error' => 'validation', 'fields' => ['status' => 'invalid']], 422);
        }
        if (! $this->comments->exists($args['kind'], $id)) {
            return Json::write($response, ['error' => 'not_found'], 404);
        }
        $this->comments->setStatus($args['kind'], $id, $status, (int) $request->getAttribute('user')['id']);
        $this->log($request, 'reply_moderated', $args['kind'], $id, ['status' => $status]);

        return Json::write($response, ['ok' => true]);
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if (! $this->comments->exists($args['kind'], $id)) {
            return Json::write($response, ['error' => 'not_found'], 404);
        }
        $this->comments->delete($args['kind'], $id);
        $this->log($request, 'reply_deleted', $args['kind'], $id, []);

        return $response->withStatus(204);
    }

    /** @param array<string, mixed> $details */
    private function log(Request $request, string $action, string $kind, int $id, array $details): void
    {
        $this->audit->record($action, (int) $request->getAttribute('user')['id'], $kind === 'guestbook' ? 'guestbook_entry' : 'piece_comment', (string) $id, $details, ClientIp::from($request), $request->getHeaderLine('User-Agent'));
    }
}
