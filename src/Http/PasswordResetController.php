<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Auth\PasswordResets;
use App\Auth\PasswordService;
use App\Auth\RateLimiter;
use App\Auth\SessionManager;
use App\Auth\TrustedDevices;
use App\Auth\Users;
use App\Customers\Customers;
use App\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PasswordResetController
{
    public function __construct(
        private readonly Users $users,
        private readonly Customers $customers,
        private readonly PasswordService $passwords,
        private readonly PasswordResets $resets,
        private readonly TrustedDevices $devices,
        private readonly SessionManager $sessions,
        private readonly RateLimiter $limits,
        private readonly AuditLog $audit,
        private readonly Mailer $mailer,
    ) {
    }

    public function forgot(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $email = is_array($body) && is_string($body['email'] ?? null) ? Users::normalizeEmail($body['email']) : '';
        if ($email === '' || strlen($email) > 254) {
            return Json::write($response, ['error' => 'invalid_request'], 400);
        }

        $ip = ClientIp::from($request);
        if ($this->limits->tooMany('pw_forgot', $ip, 5, 3600) || $this->limits->tooMany('pw_forgot_email', $email, 3, 3600)) {
            return Json::write($response, ['error' => 'too_many_attempts'], 429);
        }
        $this->limits->hit('pw_forgot', $ip);
        $this->limits->hit('pw_forgot_email', $email);

        $user = $this->users->findByEmail($email);
        if ($user !== null && $user['role'] === 'customer' && $user['status'] === 'active') {
            $this->resets->request((int) $user['id'], $email, $ip);
            $this->audit->record('password_reset_requested', (int) $user['id'], null, null, [], $ip, $request->getHeaderLine('User-Agent'));
        }

        return Json::write($response, ['status' => 'sent']);
    }

    public function reset(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $token = is_array($body) && is_string($body['token'] ?? null) ? $body['token'] : '';
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';

        $ip = ClientIp::from($request);
        if ($this->limits->tooMany('pw_reset', $ip, 10, 3600)) {
            return Json::write($response, ['error' => 'too_many_attempts'], 429);
        }
        $this->limits->hit('pw_reset', $ip);

        $row = $this->resets->find($token);
        if ($row === null) {
            return Json::write($response, ['error' => 'invalid_token'], 400);
        }
        foreach ($this->passwords->validate($password) as $code) {
            return Json::write($response, ['error' => 'validation', 'fields' => ['password' => $code]], 422);
        }
        if (! $this->resets->consume($row['id'])) {
            return Json::write($response, ['error' => 'invalid_token'], 400);
        }

        $id = $row['user_id'];
        $this->users->setPasswordHash($id, $this->passwords->hash($password));
        $this->customers->markVerified($id);
        $this->sessions->revokeUser($id);
        $this->devices->revokeAll($id);
        $this->audit->record('password_reset', $id, null, null, [], $ip, $request->getHeaderLine('User-Agent'));
        $this->mailer->send($row['email'], 'Your Studio Anni password was changed', "The password on your Studio Anni account was just changed.\n\nIf this was not you, contact us right away.");

        return Json::write($response, ['status' => 'ok']);
    }
}
