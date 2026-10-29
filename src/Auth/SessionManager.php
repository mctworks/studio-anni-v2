<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use Psr\Http\Message\ResponseInterface;

final class SessionManager
{
    private const PENDING_SECONDS = 300;

    private bool $cleared = false;

    public function __construct(private readonly Config $config, private readonly DbSessionHandler $handler)
    {
    }

    public function secureCookie(): bool
    {
        return $this->config->isProduction() || $this->config->get('SESSION_COOKIE_SECURE', 'true') !== 'false';
    }

    public function cookieName(): string
    {
        return $this->secureCookie() ? '__Host-sa' : 'sa_session';
    }

    public function start(?string $incomingId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->cleared = false;

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_cache_limiter('');
        session_set_save_handler($this->handler, true);
        session_id($incomingId !== null && preg_match('/^[A-Za-z0-9,-]{22,128}$/', $incomingId) === 1 ? $incomingId : '');
        session_start();
    }

    public function end(ResponseInterface $response, ?string $incomingId): ResponseInterface
    {
        $id = session_id();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $attributes = 'Path=/; HttpOnly; SameSite=Strict' . ($this->secureCookie() ? '; Secure' : '');
        if ($this->cleared) {
            return $response->withAddedHeader('Set-Cookie', $this->cookieName() . '=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; ' . $attributes);
        }
        if (is_string($id) && $id !== '' && $id !== $incomingId) {
            return $response->withAddedHeader('Set-Cookie', $this->cookieName() . '=' . $id . '; ' . $attributes);
        }

        return $response;
    }

    public function userId(): ?int
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    public function beginPending(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['pending_uid' => $userId, 'pending_at' => time()];
        $this->handler->setUserId(null);
    }

    public function pendingUserId(): ?int
    {
        if (! isset($_SESSION['pending_uid'], $_SESSION['pending_at']) || time() - (int) $_SESSION['pending_at'] > self::PENDING_SECONDS) {
            return null;
        }

        return (int) $_SESSION['pending_uid'];
    }

    public function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION = ['uid' => $userId, 'auth_at' => time()];
        $this->handler->setUserId($userId);
    }

    public function logout(): void
    {
        $_SESSION = [];
        $this->handler->setUserId(null);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->cleared = true;
    }

    public function revokeUser(int $userId, bool $keepCurrent = false): void
    {
        $this->handler->revokeUser($userId, $keepCurrent && session_status() === PHP_SESSION_ACTIVE ? session_id() : null);
    }
}
