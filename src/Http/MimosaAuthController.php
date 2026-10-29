<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Auth\Csrf;
use App\Auth\LoginThrottle;
use App\Auth\PasswordService;
use App\Auth\RecoveryCodes;
use App\Auth\SessionManager;
use App\Auth\TotpService;
use App\Auth\Users;
use App\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MimosaAuthController
{
    private const MIMOSA_ROLES = ['owner', 'admin'];

    public function __construct(
        private readonly Users $users,
        private readonly PasswordService $passwords,
        private readonly TotpService $totp,
        private readonly RecoveryCodes $recovery,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly AuditLog $audit,
        private readonly Mailer $mailer,
        private readonly \PDO $pdo,
    ) {
    }

    public function csrf(Request $request, Response $response): Response
    {
        return Json::write($response, ['csrfToken' => Csrf::token($_SESSION)]);
    }

    public function me(Request $request, Response $response): Response
    {
        return Json::write($response, ['user' => Users::publicView($request->getAttribute('user')), 'csrfToken' => Csrf::token($_SESSION)]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $email = is_array($body) && is_string($body['email'] ?? null) ? Users::normalizeEmail($body['email']) : '';
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($email === '' || strlen($email) > 254 || $password === '' || strlen($password) > PasswordService::MAX_BYTES) {
            return Json::write($response, ['error' => 'invalid_request'], 400);
        }

        $ip = ClientIp::from($request);
        $agent = $request->getHeaderLine('User-Agent');

        $wait = $this->throttle->retryAfter($email, $ip);
        if ($wait > 0) {
            $this->audit->record('login_throttled', null, null, null, ['step' => 'password'], $ip, $agent);

            return $this->tooMany($response, $wait);
        }

        $user = $this->users->findByEmail($email);
        $passwordOk = $user !== null ? $this->passwords->verify($password, (string) $user['password_hash']) : $this->passwords->dummyVerify($password);

        if ($user === null || ! $passwordOk || $user['status'] !== 'active' || ! in_array($user['role'], self::MIMOSA_ROLES, true)) {
            $this->throttle->recordFailure($email, $ip);
            $this->audit->record('login_failed', $user !== null ? (int) $user['id'] : null, null, null, ['step' => 'password'], $ip, $agent);

            return Json::write($response, ['error' => 'invalid_credentials'], 401);
        }

        if ($user['totp_secret_enc'] === null) {
            $this->audit->record('login_blocked', (int) $user['id'], null, null, ['reason' => 'totp_not_enrolled'], $ip, $agent);

            return Json::write($response, ['error' => 'totp_not_enrolled'], 403);
        }

        if ($this->passwords->needsRehash((string) $user['password_hash'])) {
            $this->users->setPasswordHash((int) $user['id'], $this->passwords->hash($password));
        }

        $this->sessions->beginPending((int) $user['id']);

        return Json::write($response, ['status' => 'totp_required', 'csrfToken' => Csrf::token($_SESSION)]);
    }

    public function totp(Request $request, Response $response): Response
    {
        $userId = $this->sessions->pendingUserId();
        $user = $userId !== null ? $this->users->findById($userId) : null;
        if ($user === null || $user['status'] !== 'active' || ! in_array($user['role'], self::MIMOSA_ROLES, true) || $user['totp_secret_enc'] === null) {
            return Json::write($response, ['error' => 'no_pending_login'], 401);
        }

        $body = $request->getParsedBody();
        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : '';
        $recovery = is_array($body) && is_string($body['recoveryCode'] ?? null) ? $body['recoveryCode'] : '';
        if ($code === '' && $recovery === '') {
            return Json::write($response, ['error' => 'invalid_request'], 400);
        }

        $email = (string) $user['email'];
        $ip = ClientIp::from($request);
        $agent = $request->getHeaderLine('User-Agent');

        $wait = $this->throttle->retryAfter($email, $ip);
        if ($wait > 0) {
            $this->audit->record('login_throttled', (int) $user['id'], null, null, ['step' => 'second_factor'], $ip, $agent);

            return $this->tooMany($response, $wait);
        }

        $method = null;
        if ($code !== '') {
            $last = $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null;
            $step = $this->totp->matchStep((string) $user['totp_secret_enc'], $code, $last);
            if ($step !== null && $this->totp->consume($this->pdo, (int) $user['id'], $step)) {
                $method = 'totp';
            }
        } elseif ($this->recovery->redeem((int) $user['id'], $recovery)) {
            $method = 'recovery_code';
        }

        if ($method === null) {
            $this->throttle->recordFailure($email, $ip);
            $this->audit->record('login_failed', (int) $user['id'], null, null, ['step' => 'second_factor'], $ip, $agent);

            return Json::write($response, ['error' => 'invalid_code'], 401);
        }

        $this->sessions->login((int) $user['id']);
        $this->users->touchLogin((int) $user['id']);
        $this->throttle->recordSuccess($email, $ip);
        $this->audit->record('login_success', (int) $user['id'], null, null, ['method' => $method], $ip, $agent);

        $payload = ['user' => Users::publicView($user), 'csrfToken' => Csrf::token($_SESSION)];
        if ($method === 'recovery_code') {
            $remaining = $this->recovery->remaining((int) $user['id']);
            $this->audit->record('recovery_code_used', (int) $user['id'], null, null, ['remaining' => $remaining], $ip, $agent);
            $this->notifyRecoveryUse($email, $remaining);
            $payload['recoveryCodesRemaining'] = $remaining;
        }

        return Json::write($response, $payload);
    }

    public function logout(Request $request, Response $response): Response
    {
        $userId = $this->sessions->userId();
        if ($userId !== null) {
            $this->audit->record('logout', $userId, null, null, [], ClientIp::from($request), $request->getHeaderLine('User-Agent'));
        }
        $this->sessions->logout();

        return $response->withStatus(204);
    }

    private function tooMany(Response $response, int $wait): Response
    {
        return Json::write($response, ['error' => 'too_many_attempts', 'retryAfter' => $wait], 429)->withHeader('Retry-After', (string) $wait);
    }

    private function notifyRecoveryUse(string $email, int $remaining): void
    {
        try {
            $this->mailer->send(
                $email,
                'A recovery code was used on your Studio Anni account',
                "A recovery code was just used to sign in to MIMOSA - Studio Anni.\n\n"
                . "Recovery codes remaining: {$remaining}.\n\n"
                . "If this was not you, sign in and change your password now, then contact us at admin@studioanni.com.",
            );
        } catch (\Throwable $e) {
            error_log('Recovery-code notice not sent: ' . $e::class);
        }
    }
}
