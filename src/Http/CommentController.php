<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\ClientIp;
use App\Auth\RateLimiter;
use App\Comments\Comments;
use App\Contact\ContactSenders;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CommentController
{
    public function __construct(
        private readonly Comments $comments,
        private readonly RateLimiter $limits,
        private readonly ContactSenders $senders,
    ) {
    }

    public function guestbook(Request $request, Response $response): Response
    {
        $page = $this->page($request);
        if ($page === null) {
            return $this->err($response, 400, 'invalid_request');
        }
        $r = $this->comments->guestbook($page[0], $page[1]);

        return $this->listed($response, $r['rows'], $r['total'], $page);
    }

    /** @param array<string, string> $args */
    public function pieceComments(Request $request, Response $response, array $args): Response
    {
        $page = $this->page($request);
        if ($page === null) {
            return $this->err($response, 400, 'invalid_request');
        }
        $r = $this->comments->pieceComments((int) $args['id'], $page[0], $page[1]);

        return $this->listed($response, $r['rows'], $r['total'], $page);
    }

    public function mine(Request $request, Response $response): Response
    {
        return Json::write($response, ['data' => $this->comments->mine($this->userId($request))]);
    }

    public function postGuestbook(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $user['id'];
        [$body, $error] = $this->body($request, Comments::GUESTBOOK_MAX);
        if ($error !== null) {
            return $this->err($response, 422, 'validation', ['fields' => ['body' => $error]]);
        }
        if (! $this->comments->hasProfile($id)) {
            return $this->err($response, 409, 'no_profile');
        }
        if ($this->limits->tooMany('guestbook', (string) $id, 5, 3600)) {
            return $this->err($response, 429, 'too_many_attempts');
        }
        $this->limits->hit('guestbook', (string) $id);

        $status = $this->statusFor($request, 'guestbook', (string) $user['email']);
        [$newId, $fail] = $this->comments->addGuestbook($id, (string) $body, $status);
        if ($fail !== null) {
            return $this->err($response, 409, 'already_signed');
        }

        return Json::write($response, ['reply' => ['kind' => 'guestbook', 'id' => $newId, 'status' => $status === 'approved' ? 'approved' : 'pending']], 201);
    }

    /** @param array<string, string> $args */
    public function postPieceComment(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $user['id'];
        $pieceId = (int) $args['id'];
        [$body, $error] = $this->body($request, Comments::PIECE_MAX);
        if ($error !== null) {
            return $this->err($response, 422, 'validation', ['fields' => ['body' => $error]]);
        }
        if (! $this->comments->hasProfile($id)) {
            return $this->err($response, 409, 'no_profile');
        }
        if (! $this->comments->pieceExists($pieceId)) {
            return $this->err($response, 404, 'piece_not_found');
        }
        if ($this->limits->tooMany('piece_comment', (string) $id, 10, 3600)) {
            return $this->err($response, 429, 'too_many_attempts');
        }
        $this->limits->hit('piece_comment', (string) $id);

        $status = $this->statusFor($request, 'piece', (string) $user['email']);
        $newId = $this->comments->addPieceComment($id, $pieceId, (string) $body, $status);

        return Json::write($response, ['reply' => ['kind' => 'piece', 'id' => $newId, 'status' => $status === 'approved' ? 'approved' : 'pending']], 201);
    }

    /** @param array<string, string> $args */
    public function deleteOwn(Request $request, Response $response, array $args): Response
    {
        return $this->comments->deleteOwn($args['kind'], (int) $args['id'], $this->userId($request))
            ? $response->withStatus(204)
            : $this->err($response, 404, 'not_found');
    }

    /** @param array<string, string> $args */
    public function like(Request $request, Response $response, array $args): Response
    {
        return $this->setLike($request, $response, (int) $args['id'], true);
    }

    /** @param array<string, string> $args */
    public function unlike(Request $request, Response $response, array $args): Response
    {
        return $this->setLike($request, $response, (int) $args['id'], false);
    }

    public function liked(Request $request, Response $response): Response
    {
        $pieceId = $request->getQueryParams()['piece_id'] ?? '';
        if (! is_string($pieceId) || ! ctype_digit($pieceId) || (int) $pieceId < 1) {
            return $this->err($response, 400, 'invalid_request');
        }

        return Json::write($response, ['ids' => $this->comments->likedIds($this->userId($request), (int) $pieceId)]);
    }

    private function setLike(Request $request, Response $response, int $commentId, bool $on): Response
    {
        $id = $this->userId($request);
        if ($this->limits->tooMany('comment_like', (string) $id, 60, 3600)) {
            return $this->err($response, 429, 'too_many_attempts');
        }
        $this->limits->hit('comment_like', (string) $id);

        $count = $on ? $this->comments->like($commentId, $id) : $this->comments->unlike($commentId, $id);

        return $count === null ? $this->err($response, 404, 'not_found') : Json::write($response, ['likes' => $count]);
    }

    private function statusFor(Request $request, string $kind, string $email): string
    {
        if ($this->senders->blocked(ClientIp::from($request), $email, false)) {
            return 'rejected';
        }

        return $this->comments->holdsAll($kind) ? 'pending' : 'approved';
    }

    /** @return array{0: ?string, 1: ?string} */
    private function body(Request $request, int $max): array
    {
        $b = $request->getParsedBody();

        return Comments::clean(is_array($b) ? ($b['body'] ?? null) : null, $max);
    }

    /** @return array{0: int, 1: int}|null limit, offset */
    private function page(Request $request): ?array
    {
        $q = $request->getQueryParams();
        $limit = $q['limit'] ?? '20';
        $offset = $q['offset'] ?? '0';
        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 50 || ! is_string($offset) || ! ctype_digit($offset)) {
            return null;
        }

        return [(int) $limit, (int) $offset];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{0: int, 1: int} $page
     */
    private function listed(Response $response, array $rows, int $total, array $page): Response
    {
        return Json::write($response, ['data' => $rows, 'meta' => ['total' => $total, 'limit' => $page[0], 'offset' => $page[1]]])
            ->withHeader('Cache-Control', 'public, max-age=30');
    }

    private function userId(Request $request): int
    {
        return (int) $request->getAttribute('user')['id'];
    }

    /** @param array<string, mixed> $extra */
    private function err(Response $response, int $status, string $error, array $extra = []): Response
    {
        return Json::write($response, ['error' => $error] + $extra, $status);
    }
}
