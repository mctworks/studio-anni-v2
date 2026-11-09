<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Auth\Csrf;
use App\Auth\EmailCodes;
use App\Auth\LoginThrottle;
use App\Auth\PasswordService;
use App\Auth\RateLimiter;
use App\Auth\SessionManager;
use App\Auth\TrustedDevices;
use App\Auth\Users;
use App\Config;
use App\Customers\Avatars;
use App\Customers\Customers;
use App\Images\ImageException;
use App\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class CustomerAuthController
{
    private const MIN_FORM_SECONDS = 3;

    public function __construct(
        private readonly Users $users,
        private readonly Customers $customers,
        private readonly PasswordService $passwords,
        private readonly SessionManager $sessions,
        private readonly LoginThrottle $throttle,
        private readonly RateLimiter $limits,
        private readonly EmailCodes $codes,
        private readonly TrustedDevices $devices,
        private readonly AuditLog $audit,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly Avatars $avatars,
    ) {
    }

    public function csrf(Request $request, Response $response): Response
    {
        return Json::write($response, ['csrfToken' => Csrf::token($_SESSION)]);
    }

    public function me(Request $request, Response $response): Response
    {
        $id = $this->sessions->userId();
        $user = $id !== null ? $this->users->findById($id) : null;
        if ($user === null || $user['status'] !== 'active') {
            return $this->err($response, 401, 'unauthenticated');
        }
        $view = $user['role'] === 'customer' ? $this->customerUser($id, $this->customers->own($id)) : Users::publicView($user);

        return Json::write($response, ['user' => $view, 'csrfToken' => Csrf::token($_SESSION)]);
    }

    public function register(Request $request, Response $response): Response
    {
        $b = $this->body($request);
        $ip = ClientIp::from($request);
        $agent = $request->getHeaderLine('User-Agent');

        $sentAt = is_int($b['t'] ?? null) ? intdiv($b['t'], 1000) : 0;
        if (($b['website'] ?? '') !== '' || $sentAt === 0 || time() - $sentAt < self::MIN_FORM_SECONDS) {
            return $this->err($response, 400, 'invalid_request');
        }
        if ($this->limits->tooMany('signup', $ip, 5, 3600)) {
            return $this->err($response, 429, 'too_many_attempts');
        }

        $email = is_string($b['email'] ?? null) ? Users::normalizeEmail($b['email']) : '';
        $username = is_string($b['username'] ?? null) ? strtolower(trim($b['username'])) : '';
        $display = is_string($b['displayName'] ?? null) ? trim($b['displayName']) : '';
        $password = is_string($b['password'] ?? null) ? $b['password'] : '';

        $errors = [];
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'invalid';
        }
        if (($e = Customers::usernameError($username)) !== null) {
            $errors['username'] = $e;
        } elseif ($this->customers->usernameTaken($username)) {
            $errors['username'] = 'taken';
        }
        if ($display === '') {
            $display = $username;
        } elseif (mb_strlen($display) > 80) {
            $errors['displayName'] = 'too_long';
        }
        foreach ($this->passwords->validate($password) as $code) {
            $errors['password'] = $code;
            break;
        }
        if ($errors !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $errors]);
        }

        $this->limits->hit('signup', $ip);
        $reply = ['status' => 'verify_required'];

        if ($this->users->findByEmail($email) !== null) {
            $this->mailer->send(
                $email,
                'Studio Anni account',
                "Someone tried to create a Studio Anni account with this email address, but one already exists.\n\nIf that was you, sign in instead. If not, you can ignore this message.",
            );
            $this->audit->record('register_duplicate', null, null, null, [], $ip, $agent);

            return Json::write($response, $reply + ['csrfToken' => Csrf::token($_SESSION)]);
        }

        try {
            $id = $this->customers->create($email, $display, $this->passwords->hash($password), $username);
        } catch (\DomainException) {
            return $this->err($response, 409, 'conflict');
        }
        $this->audit->record('register', $id, null, null, [], $ip, $agent);

        $this->sessions->beginPending($id);
        $_SESSION['pending_kind'] = 'verify';
        $sent = $this->sendCode($id, $email, 'verify');

        return Json::write($response, $reply + ['csrfToken' => Csrf::token($_SESSION)], $sent ? 200 : 502);
    }

    public function verify(Request $request, Response $response): Response
    {
        [$user, $kind] = $this->pending();
        if ($user === null || $kind !== 'verify') {
            return $this->err($response, 401, 'no_pending_login');
        }
        if (($fail = $this->checkCode($request, $response, $user, 'verify')) !== null) {
            return $fail;
        }

        $this->customers->markVerified((int) $user['id']);

        return $this->finish($request, $response, $user, true, 'verify');
    }

    public function loginCode(Request $request, Response $response): Response
    {
        [$user, $kind] = $this->pending();
        if ($user === null || $kind !== 'login') {
            return $this->err($response, 401, 'no_pending_login');
        }
        if (($fail = $this->checkCode($request, $response, $user, 'login')) !== null) {
            return $fail;
        }

        return $this->finish($request, $response, $user, ($this->body($request)['trustDevice'] ?? false) === true, 'email_code');
    }

    public function resend(Request $request, Response $response): Response
    {
        [$user, $kind] = $this->pending();
        if ($user === null || $kind === null) {
            return $this->err($response, 401, 'no_pending_login');
        }
        if ($this->limits->tooMany('code_resend', (string) $user['id'], 3, 600)) {
            return $this->err($response, 429, 'too_many_attempts');
        }
        $this->limits->hit('code_resend', (string) $user['id']);

        return $this->sendCode((int) $user['id'], (string) $user['email'], $kind)
            ? Json::write($response, ['status' => 'sent'])
            : $this->err($response, 502, 'mail_failed');
    }

    public function login(Request $request, Response $response): Response
    {
        $b = $this->body($request);
        $email = is_string($b['email'] ?? null) ? Users::normalizeEmail($b['email']) : '';
        $password = is_string($b['password'] ?? null) ? $b['password'] : '';
        if ($email === '' || strlen($email) > 254 || $password === '' || strlen($password) > PasswordService::MAX_BYTES) {
            return $this->err($response, 400, 'invalid_request');
        }

        $ip = ClientIp::from($request);
        $agent = $request->getHeaderLine('User-Agent');

        $wait = $this->throttle->retryAfter($email, $ip);
        if ($wait > 0) {
            $this->audit->record('login_throttled', null, null, null, ['step' => 'password', 'area' => 'customer'], $ip, $agent);

            return $this->tooMany($response, $wait);
        }

        $user = $this->users->findByEmail($email);
        $ok = $user !== null ? $this->passwords->verify($password, (string) $user['password_hash']) : $this->passwords->dummyVerify($password);
        if ($user === null || ! $ok || $user['status'] !== 'active' || ! in_array($user['role'], ['owner', 'admin', 'customer'], true)) {
            $this->throttle->recordFailure($email, $ip);
            $this->audit->record('login_failed', $user !== null ? (int) $user['id'] : null, null, null, ['step' => 'password'], $ip, $agent);

            return $this->err($response, 401, 'invalid_credentials');
        }

        if ($this->passwords->needsRehash((string) $user['password_hash'])) {
            $this->users->setPasswordHash((int) $user['id'], $this->passwords->hash($password));
        }

        $id = (int) $user['id'];
        if ($user['role'] !== 'customer') {
            if ($user['totp_secret_enc'] === null) {
                $this->audit->record('login_blocked', $id, null, null, ['reason' => 'totp_not_enrolled'], $ip, $agent);

                return $this->err($response, 403, 'totp_not_enrolled');
            }
            $this->sessions->beginPending($id);

            return Json::write($response, ['status' => 'totp_required', 'csrfToken' => Csrf::token($_SESSION)]);
        }

        if ($user['email_verified_at'] === null) {
            $this->sessions->beginPending($id);
            $_SESSION['pending_kind'] = 'verify';
            $sent = $this->sendCode($id, $email, 'verify');

            return Json::write($response, ['status' => 'verify_required', 'csrfToken' => Csrf::token($_SESSION)], $sent ? 200 : 502);
        }

        $cookie = $request->getCookieParams()[$this->deviceCookieName()] ?? null;
        if ($this->devices->valid($id, is_string($cookie) ? $cookie : null)) {
            return $this->finish($request, $response, $user, false, 'trusted_device');
        }

        $this->sessions->beginPending($id);
        $_SESSION['pending_kind'] = 'login';
        $sent = $this->sendCode($id, $email, 'login');

        return Json::write($response, ['status' => 'code_required', 'csrfToken' => Csrf::token($_SESSION)], $sent ? 200 : 502);
    }

    public function logout(Request $request, Response $response): Response
    {
        $userId = $this->sessions->userId();
        if ($userId !== null) {
            $this->audit->record('logout', $userId, null, null, ['area' => 'customer'], ClientIp::from($request), $request->getHeaderLine('User-Agent'));
        }
        $this->sessions->logout();

        return $response->withStatus(204);
    }

    public function profile(Request $request, Response $response): Response
    {
        $own = $this->customers->own((int) $request->getAttribute('user')['id']);

        return $own === null ? $this->err($response, 404, 'not_found') : Json::write($response, ['profile' => $own, 'csrfToken' => Csrf::token($_SESSION)]);
    }

    /** Staff accounts start without a profile; this creates one (customers get theirs at signup). */
    public function createProfile(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('user')['id'];
        if ($this->customers->own($id) !== null) {
            return $this->err($response, 409, 'exists');
        }

        $b = $this->body($request);
        $username = is_string($b['username'] ?? null) ? strtolower(trim($b['username'])) : '';
        $display = is_string($b['displayName'] ?? null) ? trim($b['displayName']) : '';
        $errors = [];
        if (preg_match(Customers::USERNAME_PATTERN, $username) !== 1) {
            $errors['username'] = 'invalid';
        } elseif ($this->customers->usernameTaken($username)) {
            $errors['username'] = 'taken';
        }
        if ($display !== '' && mb_strlen($display) > 80) {
            $errors['displayName'] = 'too_long';
        }
        if ($errors !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $errors]);
        }

        try {
            $this->customers->createProfile($id, $username);
        } catch (\DomainException) {
            return $this->err($response, 422, 'validation', ['fields' => ['username' => 'taken']]);
        }
        if ($display !== '') {
            $this->customers->update($id, ['displayName' => $display]);
        }
        $this->audit->record('profile_created', $id, 'user', (string) $id, [], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['profile' => $this->customers->own($id)], 201);
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('user')['id'];
        $in = $request->getParsedBody();
        if (! is_array($in) || $in === []) {
            return $this->err($response, 400, 'invalid_request');
        }

        $clean = [];
        $errors = [];
        foreach (array_diff(array_keys($in), ['displayName', 'location', 'profilePublic']) as $k) {
            $errors[(string) $k] = 'unknown_field';
        }
        if (array_key_exists('displayName', $in)) {
            $v = is_string($in['displayName']) ? trim($in['displayName']) : '';
            if ($v === '' || mb_strlen($v) > 80) {
                $errors['displayName'] = 'invalid';
            } else {
                $clean['displayName'] = $v;
            }
        }
        if (array_key_exists('location', $in)) {
            $v = $in['location'];
            $v = $v === null ? '' : (is_string($v) ? trim($v) : false);
            if ($v === false || mb_strlen($v) > 80) {
                $errors['location'] = 'invalid';
            } else {
                $clean['location'] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('profilePublic', $in)) {
            if (is_bool($in['profilePublic'])) {
                $clean['profilePublic'] = $in['profilePublic'];
            } else {
                $errors['profilePublic'] = 'invalid';
            }
        }
        if ($errors !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $errors]);
        }

        $this->customers->update($id, $clean);
        $this->audit->record('profile_updated', $id, 'user', (string) $id, ['fields' => array_keys($clean)], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['profile' => $this->customers->own($id)]);
    }

    public function uploadAvatar(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('user')['id'];
        if ($this->limits->tooMany('avatar', (string) $id, 10, 3600)) {
            return $this->err($response, 429, 'too_many_attempts');
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (! $file instanceof UploadedFileInterface) {
            return $this->err($response, (int) $request->getHeaderLine('Content-Length') > $this->avatars->maxBytes() ? 413 : 400, 'no_file');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? $this->err($response, 413, 'too_large')
                : $this->err($response, 400, 'upload_failed');
        }
        if (($file->getSize() ?? 0) > $this->avatars->maxBytes()) {
            return $this->err($response, 413, 'too_large');
        }

        $this->limits->hit('avatar', (string) $id);
        try {
            $name = $this->avatars->store($file);
        } catch (ImageException $e) {
            return $this->err($response, $e->status, $e->error);
        }

        $this->avatars->delete($this->customers->setAvatar($id, $name));
        $this->audit->record('avatar_updated', $id, 'user', (string) $id, [], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['profile' => $this->customers->own($id)]);
    }

    public function removeAvatar(Request $request, Response $response): Response
    {
        $id = (int) $request->getAttribute('user')['id'];
        $this->avatars->delete($this->customers->setAvatar($id, null));
        $this->audit->record('avatar_removed', $id, 'user', (string) $id, [], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['profile' => $this->customers->own($id)]);
    }

    /** @return array{0: array<string, mixed>|null, 1: string|null} */
    private function pending(): array
    {
        $id = $this->sessions->pendingUserId();
        $user = $id !== null ? $this->users->findById($id) : null;
        $kind = $_SESSION['pending_kind'] ?? null;
        if ($user === null || $user['status'] !== 'active' || $user['role'] !== 'customer' || ! in_array($kind, ['verify', 'login'], true)) {
            return [null, null];
        }

        return [$user, $kind];
    }

    /** @param array<string, mixed> $user */
    private function checkCode(Request $request, Response $response, array $user, string $purpose): ?Response
    {
        $code = $this->body($request)['code'] ?? '';
        if (! is_string($code) || $code === '') {
            return $this->err($response, 400, 'invalid_request');
        }

        $email = (string) $user['email'];
        $ip = ClientIp::from($request);
        $agent = $request->getHeaderLine('User-Agent');

        $wait = $this->throttle->retryAfter($email, $ip);
        if ($wait > 0) {
            $this->audit->record('login_throttled', (int) $user['id'], null, null, ['step' => 'email_code', 'area' => 'customer'], $ip, $agent);

            return $this->tooMany($response, $wait);
        }
        if (! $this->codes->verify((int) $user['id'], $purpose, $code)) {
            $this->throttle->recordFailure($email, $ip);
            $this->audit->record('login_failed', (int) $user['id'], null, null, ['step' => 'email_code', 'area' => 'customer'], $ip, $agent);

            return $this->err($response, 401, 'invalid_code');
        }

        return null;
    }

    /** @param array<string, mixed> $user */
    private function finish(Request $request, Response $response, array $user, bool $trust, string $method): Response
    {
        $id = (int) $user['id'];
        $ip = ClientIp::from($request);
        $agent = $request->getHeaderLine('User-Agent');

        $this->sessions->login($id);
        $this->users->touchLogin($id);
        $this->throttle->recordSuccess((string) $user['email'], $ip);
        $this->audit->record('login_success', $id, null, null, ['method' => $method, 'area' => 'customer'], $ip, $agent);

        $own = $this->customers->own($id);
        $out = Json::write($response, ['user' => $this->customerUser($id, $own), 'profile' => $own, 'csrfToken' => Csrf::token($_SESSION)]);
        if ($trust) {
            $token = $this->devices->issue($id, $agent);
            $out = $out->withAddedHeader('Set-Cookie', $this->deviceCookieName() . '=' . $token . '; Max-Age=' . (TrustedDevices::DAYS * 86400) . '; Path=/; HttpOnly; SameSite=Strict' . ($this->sessions->secureCookie() ? '; Secure' : ''));
        }

        return $out;
    }

    /** @param array<string, mixed>|null $own */
    private function customerUser(int $id, ?array $own): array
    {
        return ['id' => $id, 'role' => 'customer', 'displayName' => $own['displayName'] ?? null, 'username' => $own['username'] ?? null, 'title' => $own['title'] ?? 'Visitor'];
    }

    private function sendCode(int $userId, string $email, string $purpose): bool
    {
        $code = $this->codes->issue($userId, $purpose);
        $what = $purpose === 'verify' ? 'verify your email address' : 'sign in';

        return $this->mailer->send(
            $email,
            'Your Studio Anni code',
            "Your Studio Anni code to {$what} is {$code}.\n\nIt expires in 10 minutes. If you did not ask for it, you can ignore this message.",
        );
    }

    private function deviceCookieName(): string
    {
        return $this->sessions->secureCookie() ? '__Host-sa_td' : 'sa_td';
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $b = $request->getParsedBody();

        return is_array($b) ? $b : [];
    }

    private function tooMany(Response $response, int $wait): Response
    {
        return Json::write($response, ['error' => 'too_many_attempts', 'retryAfter' => $wait], 429)->withHeader('Retry-After', (string) $wait);
    }

    /** @param array<string, mixed> $extra */
    private function err(Response $response, int $status, string $error, array $extra = []): Response
    {
        return Json::write($response, ['error' => $error] + $extra, $status);
    }
}
