<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Auth\RateLimiter;
use App\Commissions\Pricing;
use App\Config;
use App\Images\ImageService;
use App\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class CommissionRequestController
{
    public const TERMS_VERSION = 'draft-1';
    private const MAX_FILES = 6;
    private const MAX_FILE_BYTES = 10485760;
    private const MAX_SUBJECTS = 10;
    private const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Pricing $pricing,
        private readonly RateLimiter $limits,
        private readonly AuditLog $audit,
        private readonly ImageService $images,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $st = $this->pdo->prepare(
            'SELECT c.id, c.subject_type, c.status, c.quoted_cents, c.delivery, c.created_at, cs.label AS size
             FROM commissions c JOIN canvas_sizes cs ON cs.id = c.canvas_size_id
             WHERE c.user_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT 50'
        );
        $st->execute([(int) $request->getAttribute('user')['id']]);

        return Json::write($response, ['commissions' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'subject_type' => (string) $r['subject_type'],
            'status' => (string) $r['status'],
            'quoted_cents' => (int) $r['quoted_cents'],
            'delivery' => (string) $r['delivery'],
            'created_at' => (string) $r['created_at'],
            'size' => (string) $r['size'],
        ], $st->fetchAll())]);
    }

    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user['id'];

        if ($this->pricing->availability() === 'closed') {
            return $this->err($response, 409, 'closed');
        }

        $body = $request->getParsedBody();
        $files = $this->files($request);
        if (! is_array($body) || ($body === [] && $files === [])) {
            $post = $this->images->limits()['post_bytes'];
            $tooBig = $post !== null && (int) $request->getHeaderLine('Content-Length') > $post;

            return $this->err($response, $tooBig ? 413 : 400, $tooBig ? 'too_large' : 'invalid_request');
        }

        $fields = [];
        $subject = $body['subject_type'] ?? null;
        if (! is_string($subject) || ! in_array($subject, Pricing::SUBJECTS, true)) {
            $fields['subject_type'] = 'invalid';
            $subject = null;
        }
        $sizeId = $this->int($body['canvas_size_id'] ?? null, 1, 65535);
        if ($sizeId === null) {
            $fields['canvas_size_id'] = 'invalid';
        }
        $pets = $this->int($body['pet_count'] ?? 0, 0, self::MAX_SUBJECTS);
        $people = $this->int($body['person_count'] ?? 0, 0, self::MAX_SUBJECTS);
        if ($pets === null) {
            $fields['pet_count'] = 'invalid';
        }
        if ($people === null) {
            $fields['person_count'] = 'invalid';
        }
        if ($subject === 'pet' && $pets !== null) {
            $people = 0;
            if ($pets < 1) {
                $fields['pet_count'] = 'invalid';
            }
        }
        if ($subject === 'human' && $people !== null && $people < 1) {
            $fields['person_count'] = 'invalid';
        }

        $description = is_string($body['description'] ?? null) ? trim(str_replace("\r\n", "\n", $body['description'])) : '';
        if (mb_strlen($description) < 10 || mb_strlen($description) > 4000) {
            $fields['description'] = 'invalid';
        }

        $delivery = $body['delivery'] ?? 'ship';
        if (! is_string($delivery) || ! in_array($delivery, ['ship', 'pickup'], true)) {
            $fields['delivery'] = 'invalid';
            $delivery = 'ship';
        }
        $zip = is_string($body['postal_code'] ?? null) ? trim($body['postal_code']) : '';
        if ($zip !== '' && preg_match('/^\d{5}(-\d{4})?$/', $zip) !== 1) {
            $fields['postal_code'] = 'invalid';
        } elseif ($delivery === 'pickup' && $zip === '') {
            $fields['postal_code'] = 'required';
        }

        if (($body['terms'] ?? '') !== '1') {
            $fields['terms'] = 'required';
        }

        if ($files === []) {
            $fields['photos'] = 'required';
        } elseif (count($files) > self::MAX_FILES) {
            $fields['photos'] = 'too_many';
        } else {
            foreach ($files as $file) {
                if ($file->getError() !== UPLOAD_ERR_OK || ($file->getSize() ?? 0) < 1) {
                    $fields['photos'] = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'too_large' : 'upload_failed';
                    break;
                }
                if ($file->getSize() > self::MAX_FILE_BYTES) {
                    $fields['photos'] = 'too_large';
                    break;
                }
            }
        }

        $quote = null;
        if ($fields === []) {
            $quote = $this->pricing->quote((string) $subject, (int) $sizeId, (int) $pets, (int) $people);
            if ($quote === null) {
                $fields['canvas_size_id'] = 'not_offered';
            }
        }
        if ($fields !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $fields]);
        }

        if ($this->limits->tooMany('commission_request', (string) $userId, 3, 86400)) {
            return $this->err($response, 429, 'rate_limited');
        }
        $this->limits->hit('commission_request', (string) $userId);

        $now = gmdate('Y-m-d H:i:s');
        $ip = ClientIp::from($request);
        $dir = null;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO commissions (user_id, subject_type, canvas_size_id, pet_count, person_count, description, delivery, postal_code, status, quoted_cents, terms_version, terms_accepted_at, terms_accepted_ip, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$userId, $subject, $sizeId, $pets, $people, $description, $delivery, $zip === '' ? null : $zip, 'submitted', $quote['total_cents'], self::TERMS_VERSION, $now, ClientIp::pack($ip), $now, $now]);
            $id = (int) $this->pdo->lastInsertId();

            $dir = $this->config->root . '/storage/uploads/commissions/' . $id;
            if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
                throw new \RuntimeException('mkdir_failed');
            }

            $insert = $this->pdo->prepare('INSERT INTO commission_files (commission_id, stored_name, original_name, mime, bytes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            foreach ($files as $file) {
                $tmp = $dir . '/' . bin2hex(random_bytes(16)) . '.upload';
                $file->moveTo($tmp);
                $mime = (string) $finfo->file($tmp);
                if (! isset(self::MIMES[$mime])) {
                    unlink($tmp);
                    throw new \DomainException('unsupported_type');
                }
                $stored = bin2hex(random_bytes(16)) . '.' . self::MIMES[$mime];
                if (! rename($tmp, $dir . '/' . $stored)) {
                    throw new \RuntimeException('write_failed');
                }
                $insert->execute([$id, $stored, $this->fileName((string) $file->getClientFilename()), $mime, (int) filesize($dir . '/' . $stored), $now]);
            }
            $this->pdo->commit();
        } catch (\DomainException) {
            $this->abort($dir);

            return $this->err($response, 422, 'validation', ['fields' => ['photos' => 'unsupported_type']]);
        } catch (\Throwable $e) {
            $this->abort($dir);
            error_log('Commission request failed: ' . $e->getMessage());

            return $this->err($response, 500, 'save_failed');
        }

        $this->audit->record('commission_requested', $userId, 'commission', (string) $id, ['subject_type' => $subject, 'delivery' => $delivery, 'files' => count($files)], $ip, $request->getHeaderLine('User-Agent'));
        $this->notify($id, $user, (string) $subject, (int) $sizeId, (int) $pets, (int) $people, $delivery, $quote['total_cents']);

        return Json::write($response, ['commission' => ['id' => $id, 'status' => 'submitted', 'quoted_cents' => $quote['total_cents']]], 201);
    }

    private function notify(int $id, array $user, string $subject, int $sizeId, int $pets, int $people, string $delivery, int $cents): void
    {
        $label = $this->pdo->prepare('SELECT label FROM canvas_sizes WHERE id = ?');
        $label->execute([$sizeId]);
        $size = (string) $label->fetchColumn();
        $name = (string) ($user['display_name'] ?? '') !== '' ? (string) $user['display_name'] : (string) $user['email'];
        $price = '$' . number_format($cents / 100, 2);
        $to = $this->config->get('MAIL_FROM') ?? $this->config->getRequired('MAIL_USER');
        $link = rtrim((string) $this->config->get('APP_URL', ''), '/') . '/mimosa/commissions/' . $id;

        $this->mailer->send(
            $to,
            "New portrait request #{$id}",
            "From: {$name} <{$user['email']}>\nType: " . ($subject === 'pet' ? 'Pet/animal' : 'Human') . "\nSize: {$size}\nPets: {$pets}\nPeople: {$people}\nDelivery: " . ($delivery === 'pickup' ? 'Local pickup' : 'Ship') . "\nEstimate: {$price} plus shipping\n\n{$link}\n",
            (string) $user['email'],
        );
        $this->mailer->send(
            (string) $user['email'],
            'We received your portrait request',
            "Thank you! Studio Anni received your portrait request (#{$id}). Anni will review it and reply by email if she needs more information.\n\nSize: {$size}\nEstimate: {$price} plus shipping\n\nNo payment is needed until your commission is approved.\n",
        );
    }

    private function files(Request $request): array
    {
        $files = $request->getUploadedFiles()['photos'] ?? [];
        $files = is_array($files) ? $files : [$files];

        return array_values(array_filter($files, static fn (mixed $f): bool => $f instanceof UploadedFileInterface && $f->getError() !== UPLOAD_ERR_NO_FILE));
    }

    private function abort(?string $dir): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($dir !== null && is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function int(mixed $value, int $min, int $max): ?int
    {
        if (is_string($value) && preg_match('/^\d{1,5}$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    private function fileName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', basename($name)));

        return mb_substr($clean === '' ? 'photo' : $clean, 0, 255);
    }

    private function err(Response $response, int $status, string $error, array $extra = []): Response
    {
        return Json::write($response, ['error' => $error] + $extra, $status);
    }
}
