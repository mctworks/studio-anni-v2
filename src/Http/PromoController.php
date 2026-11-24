<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Config;
use App\Images\ImageException;
use App\Images\ImageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class PromoController
{
    private const KEY = 'homepage_promo';
    private const MAX_HEADING = 120;
    private const MAX_TEXT = 2000;
    private const MAX_IMAGE_ALT = 255;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Config $config,
        private readonly ImageService $images,
        private readonly AuditLog $audit,
    ) {
    }

    public function showPublic(Request $request, Response $response): Response
    {
        $promo = $this->view($this->current());
        if ($promo['image_url'] === null && $promo['heading'] === '' && $promo['text'] === '') {
            $promo = null;
        }

        return Json::write($response, ['promo' => $promo]);
    }

    public function show(Request $request, Response $response): Response
    {
        return Json::write($response, ['promo' => $this->view($this->current())]);
    }

    public function save(Request $request, Response $response): Response
    {
        $postBytes = $this->images->limits()['post_bytes'];
        if ($postBytes !== null && (int) $request->getHeaderLine('Content-Length') > $postBytes) {
            return $this->err($response, 413, 'too_large');
        }
        $body = $request->getParsedBody();
        if (! is_array($body)) {
            return $this->err($response, 400, 'invalid_request');
        }

        $heading = $body['heading'] ?? null;
        $text = $body['text'] ?? null;
        if (! is_string($heading) || ! is_string($text)) {
            return $this->err($response, 422, 'validation', ['fields' => ['content' => 'required']]);
        }
        $imageAlt = $body['image_alt'] ?? '';
        if (! is_string($imageAlt)) {
            return $this->err($response, 422, 'validation', ['fields' => ['image_alt' => 'invalid']]);
        }
        $heading = trim($heading);
        $text = trim($text);
        $imageAlt = trim($imageAlt);
        $fields = [];
        if (mb_strlen($heading) > self::MAX_HEADING) {
            $fields['heading'] = 'too_long';
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            $fields['text'] = 'too_long';
        }
        if (mb_strlen($imageAlt) > self::MAX_IMAGE_ALT) {
            $fields['image_alt'] = 'too_long';
        }
        if ($fields !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $fields]);
        }

        $clearImage = ($body['clear_image'] ?? '') === '1';
        $file = $request->getUploadedFiles()['file'] ?? null;
        if ($file !== null && ! $file instanceof UploadedFileInterface) {
            return $this->err($response, 400, 'invalid_request');
        }
        if ($file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE && $file->getError() !== UPLOAD_ERR_OK) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? $this->err($response, 413, 'too_large')
                : $this->err($response, 400, 'upload_failed');
        }
        $hasUpload = $file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK;
        if (($body['file_expected'] ?? '') === '1' && ! $hasUpload) {
            return $this->err($response, 400, 'upload_missing');
        }
        if ($clearImage && $hasUpload) {
            return $this->err($response, 400, 'invalid_request');
        }
        if ($hasUpload && (($file->getSize() ?? 0) > $this->images->maxBytes())) {
            return $this->err($response, 413, 'too_large');
        }

        $current = $this->current();
        $image = $clearImage ? null : $current['image'];
        $newImage = null;
        try {
            if ($hasUpload) {
                $image = $this->store($file);
                $newImage = $image;
            }
            $data = ['heading' => $heading, 'text' => $text, 'image' => $image, 'image_alt' => $image === null ? '' : $imageAlt];
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            )->execute([self::KEY, $json]);
        } catch (ImageException $e) {
            if ($newImage !== null) {
                $this->deleteImage($newImage);
            }

            return $this->err($response, $e->status, $e->error);
        } catch (\Throwable $e) {
            if ($newImage !== null) {
                $this->deleteImage($newImage);
            }
            throw $e;
        }

        if ($current['image'] !== $image) {
            $this->deleteImage($current['image']);
        }
        $this->audit->record(
            'homepage_promo_updated',
            (int) $request->getAttribute('user')['id'],
            'homepage_promo',
            'current',
            ['heading_length' => mb_strlen($heading), 'text_length' => mb_strlen($text), 'image_present' => $image !== null],
            ClientIp::from($request),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::write($response, ['promo' => $this->view($data)]);
    }

    private function current(): array
    {
        $st = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute([self::KEY]);
        $value = $st->fetchColumn();
        $data = is_string($value) ? json_decode($value, true) : null;
        $image = is_array($data) && is_string($data['image'] ?? null) && preg_match('/^promo-[a-f0-9]{32}\.(?:jpg|png)$/', $data['image']) === 1
            ? $data['image']
            : null;

        return [
            'heading' => is_array($data) && is_string($data['heading'] ?? null) ? $data['heading'] : '',
            'text' => is_array($data) && is_string($data['text'] ?? null) ? $data['text'] : '',
            'image' => $image,
            'image_alt' => is_array($data) && is_string($data['image_alt'] ?? null) ? $data['image_alt'] : '',
        ];
    }

    private function view(array $data): array
    {
        $image = $data['image'] ?? null;
        $url = is_string($image) && is_file($this->directory() . '/' . $image)
            ? '/media/promos/' . rawurlencode($image)
            : null;

        return [
            'heading' => $data['heading'],
            'text' => $data['text'],
            'image_url' => $url,
            'image_alt' => $url === null ? '' : ($data['image_alt'] ?? ''),
        ];
    }

    private function store(UploadedFileInterface $file): string
    {
        $directory = $this->directory();
        $temporaryDirectory = $this->config->root . '/storage/uploads';
        $this->images->ensurePublicDir($directory);
        $this->images->ensureDir($temporaryDirectory);
        $temporary = $temporaryDirectory . '/promo-' . bin2hex(random_bytes(16)) . '.upload';

        try {
            $file->moveTo($temporary);
            $info = $this->images->inspect($temporary);
            $extension = $info['type'] === IMAGETYPE_PNG ? 'png' : 'jpg';
            $name = 'promo-' . bin2hex(random_bytes(16)) . '.' . $extension;
            if (! @rename($temporary, $directory . '/' . $name)) {
                throw new ImageException('storage_unavailable', 500);
            }

            return $name;
        } finally {
            @unlink($temporary);
        }
    }

    private function directory(): string
    {
        return rtrim($this->config->publicDir, '/') . '/media/promos';
    }

    private function deleteImage(?string $name): void
    {
        if ($name !== null && preg_match('/^promo-[a-f0-9]{32}\.(?:jpg|png)$/', $name) === 1) {
            @unlink($this->directory() . '/' . $name);
        }
    }

    private function err(Response $response, int $status, string $error, array $extra = []): Response
    {
        return Json::write($response, ['error' => $error] + $extra, $status);
    }
}
