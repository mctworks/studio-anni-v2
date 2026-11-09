<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Auth\PasswordResets;
use App\Auth\RateLimiter;
use App\Auth\SessionManager;
use App\Auth\TrustedDevices;
use App\Auth\Users;
use App\Customers\Avatars;
use App\Customers\Customers;
use App\Customers\UserAdmin;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MimosaUserController
{
    private const STATUSES = ['active', 'disabled', 'banned'];
    private const ROLES = ['owner', 'admin', 'customer'];

    public function __construct(
        private readonly UserAdmin $admin,
        private readonly Users $users,
        private readonly Customers $customers,
        private readonly Avatars $avatars,
        private readonly PasswordResets $resets,
        private readonly TrustedDevices $devices,
        private readonly SessionManager $sessions,
        private readonly RateLimiter $limits,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $search = $q['q'] ?? '';
        $role = $q['role'] ?? null;
        $status = $q['status'] ?? null;
        $limit = isset($q['limit']) ? filter_var($q['limit'], FILTER_VALIDATE_INT) : 25;
        $offset = isset($q['offset']) ? filter_var($q['offset'], FILTER_VALIDATE_INT) : 0;
        if (! is_string($search) || mb_strlen($search) > 100
            || ($role !== null && ! in_array($role, self::ROLES, true))
            || ($status !== null && ! in_array($status, self::STATUSES, true))
            || $limit === false || $limit < 1 || $limit > 100 || $offset === false || $offset < 0) {
            return $this->err($response, 400, 'invalid_request');
        }
        $r = $this->admin->list(trim($search), $role, $status, $limit, $offset);

        return Json::write($response, ['data' => $r['rows'], 'meta' => ['total' => $r['total'], 'limit' => $limit, 'offset' => $offset]]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $detail = $this->admin->detail((int) $args['id']);

        return $detail === null ? $this->err($response, 404, 'not_found') : Json::write($response, ['user' => $detail]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $target = $this->users->findById((int) $args['id']);
        if ($target === null) {
            return $this->err($response, 404, 'not_found');
        }
        $actor = $request->getAttribute('user');
        $in = $request->getParsedBody();
        if (! is_array($in) || $in === []) {
            return $this->err($response, 400, 'invalid_request');
        }

        $errors = [];
        foreach (array_diff(array_keys($in), ['customTitle', 'status', 'role']) as $k) {
            $errors[(string) $k] = 'unknown_field';
        }

        $title = null;
        if (array_key_exists('customTitle', $in)) {
            $v = $in['customTitle'];
            $v = $v === null ? '' : (is_string($v) ? trim($v) : false);
            if ($v === false || mb_strlen($v) > 40 || preg_match('/[\x00-\x1f\x7f]/', $v) === 1) {
                $errors['customTitle'] = 'invalid';
            } elseif (! $this->admin->hasProfile((int) $target['id'])) {
                return $this->err($response, 409, 'no_profile');
            } elseif ($target['role'] !== 'customer' && $actor['role'] !== 'owner') {
                return $this->err($response, 403, 'forbidden');
            } else {
                $title = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('status', $in)) {
            if (! is_string($in['status']) || ! in_array($in['status'], self::STATUSES, true)) {
                $errors['status'] = 'invalid';
            } elseif ((int) $actor['id'] === (int) $target['id']) {
                return $this->err($response, 409, 'cannot_change_self');
            } elseif ($target['role'] !== 'customer' && $actor['role'] !== 'owner') {
                return $this->err($response, 403, 'forbidden');
            }
        }
        if (array_key_exists('role', $in)) {
            if (! is_string($in['role']) || ! in_array($in['role'], ['admin', 'customer'], true)) {
                $errors['role'] = 'invalid';
            } elseif ($actor['role'] !== 'owner') {
                return $this->err($response, 403, 'forbidden');
            } elseif ((int) $actor['id'] === (int) $target['id']) {
                return $this->err($response, 409, 'cannot_change_self');
            } elseif ($target['role'] === 'owner') {
                return $this->err($response, 409, 'owner_locked');
            } elseif ($target['role'] === $in['role']) {
                return $this->err($response, 409, 'no_change');
            } elseif ($in['role'] === 'admin' && ($target['status'] !== 'active' || $target['email_verified_at'] === null)) {
                return $this->err($response, 409, 'not_eligible');
            } elseif ($in['role'] === 'customer' && ! $this->admin->hasProfile((int) $target['id'])) {
                return $this->err($response, 409, 'no_profile');
            }
        }
        if ($errors !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $errors]);
        }

        $id = (int) $target['id'];
        $details = ['fields' => array_keys($in)];
        if (array_key_exists('customTitle', $in)) {
            $this->admin->setCustomTitle($id, $title);
        }
        if (array_key_exists('status', $in)) {
            $this->admin->setStatus($id, $in['status']);
            $details['status'] = $in['status'];
            if ($in['status'] !== 'active') {
                $this->sessions->revokeUser($id);
                $this->devices->revokeAll($id);
            }
        }
        if (array_key_exists('role', $in)) {
            $this->admin->setRole($id, $in['role']);
            $this->sessions->revokeUser($id);
            $this->devices->revokeAll($id);
            $details['role'] = ['from' => $target['role'], 'to' => $in['role']];
        }
        $this->record($request, 'user_updated', (int) $actor['id'], $id, $details);

        return Json::write($response, ['user' => $this->admin->detail($id)]);
    }

    /** @param array<string, string> $args */
    public function assignPiece(Request $request, Response $response, array $args): Response
    {
        $target = $this->customer($args);
        if ($target === null) {
            return $this->err($response, 404, 'not_found');
        }
        $body = $request->getParsedBody();
        $pieceId = is_array($body) && is_int($body['pieceId'] ?? null) ? $body['pieceId'] : 0;
        if ($pieceId < 1) {
            return $this->err($response, 422, 'validation', ['fields' => ['pieceId' => 'invalid']]);
        }

        $result = $this->admin->assignPiece((int) $target['id'], $pieceId);
        if ($result === 'no_piece') {
            return $this->err($response, 404, 'piece_not_found');
        }
        if ($result !== 'ok') {
            return $this->err($response, 409, $result);
        }
        $this->record($request, 'piece_owner_assigned', (int) $request->getAttribute('user')['id'], (int) $target['id'], ['piece_id' => $pieceId]);

        return Json::write($response, ['user' => $this->admin->detail((int) $target['id'])]);
    }

    /** @param array<string, string> $args */
    public function removePiece(Request $request, Response $response, array $args): Response
    {
        $target = $this->customer($args);
        if ($target === null) {
            return $this->err($response, 404, 'not_found');
        }
        if (! $this->admin->removePiece((int) $target['id'], (int) $args['pieceId'])) {
            return $this->err($response, 404, 'not_owned');
        }
        $this->record($request, 'piece_owner_removed', (int) $request->getAttribute('user')['id'], (int) $target['id'], ['piece_id' => (int) $args['pieceId']]);

        return Json::write($response, ['user' => $this->admin->detail((int) $target['id'])]);
    }

    /** @param array<string, string> $args */
    public function removeAvatar(Request $request, Response $response, array $args): Response
    {
        $target = $this->customer($args);
        if ($target === null) {
            return $this->err($response, 404, 'not_found');
        }
        $this->avatars->delete($this->customers->setAvatar((int) $target['id'], null));
        $this->record($request, 'avatar_removed_by_staff', (int) $request->getAttribute('user')['id'], (int) $target['id'], []);

        return Json::write($response, ['user' => $this->admin->detail((int) $target['id'])]);
    }

    /** @param array<string, string> $args */
    public function resetPassword(Request $request, Response $response, array $args): Response
    {
        $target = $this->customer($args);
        if ($target === null) {
            return $this->err($response, 404, 'not_found');
        }
        if ($target['status'] !== 'active') {
            return $this->err($response, 409, 'not_active');
        }
        $id = (int) $target['id'];
        if ($this->limits->tooMany('pw_reset_staff', (string) $id, 3, 3600)) {
            return $this->err($response, 429, 'too_many_attempts');
        }
        $this->limits->hit('pw_reset_staff', (string) $id);

        $sent = $this->resets->request($id, (string) $target['email'], ClientIp::from($request));
        $this->record($request, 'password_reset_sent', (int) $request->getAttribute('user')['id'], $id, ['mailed' => $sent]);

        return $sent ? Json::write($response, ['status' => 'sent']) : $this->err($response, 502, 'mail_failed');
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>|null
     */
    private function customer(array $args): ?array
    {
        $u = $this->users->findById((int) $args['id']);

        return $u !== null && $u['role'] === 'customer' ? $u : null;
    }

    /** @param array<string, mixed> $details */
    private function record(Request $request, string $action, int $actorId, int $targetId, array $details): void
    {
        $this->audit->record($action, $actorId, 'user', (string) $targetId, $details, ClientIp::from($request), $request->getHeaderLine('User-Agent'));
    }

    /** @param array<string, mixed> $extra */
    private function err(Response $response, int $status, string $error, array $extra = []): Response
    {
        return Json::write($response, ['error' => $error] + $extra, $status);
    }
}
