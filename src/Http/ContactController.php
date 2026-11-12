<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\ClientIp;
use App\Auth\RateLimiter;
use App\Config;
use App\Contact\ContactGuard;
use App\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Public contact form. No account needed. Spam defenses: honeypot, signed minimum-age token, per-IP and per-email rate limits. */
final class ContactController
{
    private const SOURCES = ['commissions' => 'Commissions page', 'about' => 'About page', 'general' => 'Website'];

    public function __construct(
        private readonly ContactGuard $guard,
        private readonly RateLimiter $limits,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {
    }

    public function token(Request $request, Response $response): Response
    {
        return Json::write($response, ['token' => $this->guard->issue()])->withHeader('Cache-Control', 'no-store');
    }

    public function send(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        if (! is_array($body) || ! str_contains(strtolower($request->getHeaderLine('Content-Type')), 'json')) {
            return $this->err($response, 400, 'invalid_request');
        }

        // Honeypot: real visitors never see this field. Pretend it worked.
        if (($body['website'] ?? '') !== '') {
            return Json::write($response, ['ok' => true]);
        }

        $state = $this->guard->check($body['token'] ?? null);
        if ($state !== 'ok') {
            return $this->err($response, 422, $state === 'too_fast' ? 'too_fast' : 'expired');
        }

        $name = $this->text($body['name'] ?? null, 80);
        $email = $this->text($body['email'] ?? null, 254);
        $message = is_string($body['message'] ?? null) ? trim(str_replace("\r\n", "\n", $body['message'])) : '';
        $source = $body['source'] ?? 'general';
        if ($name === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($message) < 5 || mb_strlen($message) > 4000
            || ! is_string($source) || ! isset(self::SOURCES[$source])) {
            return $this->err($response, 422, 'invalid_fields');
        }

        $ip = ClientIp::from($request);
        if ($this->limits->tooMany('contact_ip', $ip, 5, 3600) || $this->limits->tooMany('contact_email', $email, 3, 3600)) {
            return $this->err($response, 429, 'rate_limited');
        }
        $this->limits->hit('contact_ip', $ip);
        $this->limits->hit('contact_email', $email);

        $to = $this->config->get('CONTACT_TO') ?? $this->config->get('MAIL_FROM') ?? $this->config->getRequired('MAIL_USER');
        $text = "Name: {$name}\nEmail: {$email}\nSent from: " . self::SOURCES[$source] . "\n\n{$message}\n";
        if (! $this->mailer->send($to, 'Studio Anni contact: ' . $name, $text, $email)) {
            return $this->err($response, 502, 'send_failed');
        }

        return Json::write($response, ['ok' => true]);
    }

    /** Single-line text: control characters (including CR/LF) collapse to spaces. */
    private function text(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            return '';
        }
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return mb_strlen($clean) > $max ? '' : $clean;
    }

    private function err(Response $response, int $status, string $error): Response
    {
        return Json::write($response, ['error' => $error], $status);
    }
}
