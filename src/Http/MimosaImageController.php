<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Images\ImageException;
use App\Images\ImageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

final class MimosaImageController
{
    private const KINDS = ['main', 'angle', 'detail'];
    private const BASE_QUALITY = 95;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly AuditLog $audit,
        private readonly ImageService $images,
    ) {
    }

    public function config(Request $request, Response $response): Response
    {
        return Json::write($response, [
            'logos' => $this->images->availableLogos(),
            'kinds' => self::KINDS,
            'max_side' => ImageService::MAX_SIDE,
            'limits' => $this->images->limits(),
        ]);
    }

    public function logo(Request $request, Response $response, array $args): Response
    {
        $path = $this->images->logoPath((string) $args['variant']);

        return $path === null ? $this->err($response, 404, 'not_found') : $this->stream($response, $path, 'image/png');
    }

    public function index(Request $request, Response $response, array $args): Response
    {
        $pieceId = (int) $args['id'];
        if ($this->run('SELECT id FROM pieces WHERE id = ?', [$pieceId])->fetch() === false) {
            return $this->err($response, 404, 'not_found');
        }
        $rows = $this->run(
            'SELECT i.id, i.filename, i.kind, i.alt_text, i.sort_order, s.image_id AS source_id, s.watermark, r.has_watermark AS reviewed_has_watermark FROM piece_images i LEFT JOIN piece_image_sources s ON s.image_id = i.id LEFT JOIN piece_image_watermark_reviews r ON r.image_id = i.id WHERE i.piece_id = ? ORDER BY i.sort_order, i.id',
            [$pieceId],
        )->fetchAll();

        return Json::write($response, ['images' => array_map([$this, 'view'], $rows)]);
    }

    public function upload(Request $request, Response $response, array $args): Response
    {
        $pieceId = (int) $args['id'];
        $piece = $this->run('SELECT id, deleted_at FROM pieces WHERE id = ?', [$pieceId])->fetch();
        if ($piece === false) {
            return $this->err($response, 404, 'not_found');
        }
        if ($piece['deleted_at'] !== null) {
            return $this->err($response, 409, 'piece_deleted');
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (! $file instanceof UploadedFileInterface) {
            $post = $this->images->limits()['post_bytes'];
            $tooBig = $post !== null && (int) $request->getHeaderLine('Content-Length') > $post;

            return $tooBig ? $this->err($response, 413, 'too_large') : $this->err($response, 400, 'no_file');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? $this->err($response, 413, 'too_large')
                : $this->err($response, 400, 'upload_failed');
        }
        if (($file->getSize() ?? 0) > $this->images->maxBytes()) {
            return $this->err($response, 413, 'too_large');
        }

        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $fields = [];

        $count = (int) $this->run('SELECT COUNT(*) FROM piece_images WHERE piece_id = ?', [$pieceId])->fetchColumn();
        $kind = $body['kind'] ?? ($count === 0 ? 'main' : 'angle');
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            $fields['kind'] = 'invalid';
        }

        $alt = $body['alt_text'] ?? null;
        if ($alt !== null) {
            $alt = is_string($alt) ? trim($alt) : false;
            if ($alt === false) {
                $fields['alt_text'] = 'invalid';
            } elseif (mb_strlen($alt) > 255) {
                $fields['alt_text'] = 'too_long';
            } elseif ($alt === '') {
                $alt = null;
            }
        }

        $watermark = null;
        $raw = $body['watermark'] ?? '';
        if (is_string($raw) && trim($raw) !== '' && $raw !== 'none') {
            try {
                $watermark = $this->images->normalizeWatermark(json_decode($raw, true));
            } catch (ImageException $e) {
                $fields['watermark'] = $e->error;
            }
        }
        if ($fields !== []) {
            return $this->err($response, 422, 'validation', ['fields' => $fields]);
        }

        $dir = $this->images->originalsDir();
        $token = bin2hex(random_bytes(8));
        $made = [];
        $committed = false;

        try {
            $this->images->ensureDir($dir);
            $this->images->ensureDir($this->images->publicDir());

            $upload = $dir . '/' . $token . '-orig.upload';
            $made[] = $upload;
            $file->moveTo($upload);

            $info = $this->images->inspect($upload);
            $originalName = $token . '-orig.' . ($info['type'] === IMAGETYPE_PNG ? 'png' : 'jpg');
            $original = $dir . '/' . $originalName;
            $made[] = $original;
            if (! rename($upload, $original)) {
                throw new ImageException('write_failed', 500);
            }

            $baseName = $token . '-base.jpg';
            $basePath = $dir . '/' . $baseName;
            $made[] = $basePath;
            $publicName = 'p' . $pieceId . '-' . $token . '.jpg';
            $public = $this->images->publicDir() . '/' . $publicName;
            $made[] = $public;

            if ($this->images->hasGd()) {
                $img = $this->images->shrink($this->images->load($original, $info['type']));
                $this->images->saveJpeg($img, $basePath, self::BASE_QUALITY);
                if ($watermark !== null) {
                    $this->images->saveJpeg($this->images->burn($img, $watermark), $public);
                } else {
                    $this->images->saveJpeg($img, $public);
                }
            } else {
                $this->images->normalizeJpeg($original, $basePath, self::BASE_QUALITY);
                if ($watermark !== null) {
                    $this->images->watermarkJpeg($basePath, $public, $watermark);
                } else {
                    $this->images->publish($basePath, $public);
                }
            }

            $range = $this->run('SELECT MIN(sort_order) AS lo, MAX(sort_order) AS hi FROM piece_images WHERE piece_id = ?', [$pieceId])->fetch();
            $sort = $range['hi'] === null ? 0 : ($kind === 'main' ? (int) $range['lo'] - 1 : (int) $range['hi'] + 1);

            $this->pdo->beginTransaction();
            try {
                $this->run('INSERT INTO piece_images (piece_id, filename, kind, alt_text, sort_order) VALUES (?, ?, ?, ?, ?)', [$pieceId, $publicName, $kind, $alt, $sort]);
                $imageId = (int) $this->pdo->lastInsertId();
                $this->run(
                    'INSERT INTO piece_image_sources (image_id, base_file, original_file, watermark, watermarked_at) VALUES (?, ?, ?, ?, ?)',
                    [$imageId, $baseName, $originalName, $watermark === null ? null : json_encode($watermark), $watermark === null ? null : gmdate('Y-m-d H:i:s')],
                );
                $this->touchPiece($pieceId);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            $committed = true;
        } catch (ImageException $e) {
            return $this->err($response, $e->status, $e->error);
        } finally {
            if (! $committed) {
                foreach ($made as $path) {
                    @unlink($path);
                }
            }
        }

        $this->log($request, 'image_upload', $imageId, ['piece_id' => $pieceId, 'kind' => $kind, 'watermarked' => $watermark !== null]);

        return Json::write($response, ['image' => $this->imageView($imageId)], 201);
    }

    public function base(Request $request, Response $response, array $args): Response
    {
        $row = $this->findImage((int) $args['id']);
        if ($row === null) {
            return $this->err($response, 404, 'not_found');
        }
        $source = $this->source((int) $row['id']);
        $path = $source !== null ? $this->images->originalsDir() . '/' . basename($source['base_file']) : $this->publicPath($row);

        return is_file($path) ? $this->stream($response, $path, 'image/jpeg') : $this->err($response, 404, 'file_missing');
    }

    public function watermark(Request $request, Response $response, array $args): Response
    {
        $row = $this->findImage((int) $args['id']);
        if ($row === null) {
            return $this->err($response, 404, 'not_found');
        }
        if ($row['piece_deleted_at'] !== null) {
            return $this->err($response, 409, 'piece_deleted');
        }

        $body = $request->getParsedBody();
        $confirmed = is_array($body) && ($body['confirm_clean'] ?? false) === true;
        $source = $this->source((int) $row['id']);
        if ($source !== null && $source['watermark'] !== null) {
            return $this->err($response, 409, 'already_watermarked');
        }
        $review = $this->watermarkReview((int) $row['id']);
        if ($review === true) {
            return $this->err($response, 409, 'already_watermarked');
        }
        if ($source === null && ! $confirmed) {
            return $this->err($response, 409, 'confirm_required');
        }

        try {
            $params = $this->images->normalizeWatermark($body);
            $basePath = $this->ensureBase($row);

            if ($this->images->hasGd()) {
                $out = $this->images->burn($this->images->loadJpeg($basePath), $params);
                $this->images->saveJpeg($out, $this->publicPath($row));
            } else {
                $this->images->watermarkJpeg($basePath, $this->publicPath($row), $params);
            }
            $this->run('UPDATE piece_image_sources SET watermark = ?, watermarked_at = ? WHERE image_id = ?', [json_encode($params), gmdate('Y-m-d H:i:s'), (int) $row['id']]);
            $this->touchPiece((int) $row['piece_id']);
        } catch (ImageException $e) {
            return $this->err($response, $e->status, $e->error);
        }

        $this->log($request, 'image_watermark', (int) $row['id'], ['piece_id' => (int) $row['piece_id'], 'logo' => $params['logo'], 'width' => $params['width'], 'opacity' => $params['opacity'], 'confirmed_clean' => $confirmed]);

        return Json::write($response, ['image' => $this->imageView((int) $row['id'])]);
    }

    public function reviewWatermark(Request $request, Response $response, array $args): Response
    {
        $row = $this->findImage((int) $args['id']);
        if ($row === null) {
            return $this->err($response, 404, 'not_found');
        }
        if ($row['piece_deleted_at'] !== null) {
            return $this->err($response, 409, 'piece_deleted');
        }

        $body = $request->getParsedBody();
        if (! is_array($body) || ! is_bool($body['has_watermark'] ?? null)) {
            return $this->err($response, 400, 'invalid_request');
        }
        if ($this->source((int) $row['id']) !== null) {
            return $this->err($response, 409, 'already_tracked');
        }

        $imageId = (int) $row['id'];
        $hasWatermark = $body['has_watermark'];
        $this->run(
            'INSERT INTO piece_image_watermark_reviews (image_id, has_watermark, reviewed_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE has_watermark = VALUES(has_watermark), reviewed_at = VALUES(reviewed_at)',
            [$imageId, $hasWatermark ? 1 : 0, gmdate('Y-m-d H:i:s')],
        );
        $this->touchPiece((int) $row['piece_id']);
        $this->log($request, 'image_watermark_reviewed', $imageId, ['has_watermark' => $hasWatermark]);

        return Json::write($response, ['image' => $this->imageView($imageId)]);
    }

    public function unwatermark(Request $request, Response $response, array $args): Response
    {
        $row = $this->findImage((int) $args['id']);
        if ($row === null) {
            return $this->err($response, 404, 'not_found');
        }
        if ($row['piece_deleted_at'] !== null) {
            return $this->err($response, 409, 'piece_deleted');
        }
        $source = $this->source((int) $row['id']);
        if ($source === null || $source['watermark'] === null) {
            return $this->err($response, 409, 'not_watermarked');
        }
        $base = $this->images->originalsDir() . '/' . basename($source['base_file']);
        if (! is_file($base)) {
            return $this->err($response, 409, 'source_missing');
        }

        try {
            $this->images->publish($base, $this->publicPath($row));
        } catch (ImageException $e) {
            return $this->err($response, $e->status, $e->error);
        }
        $this->run('UPDATE piece_image_sources SET watermark = NULL, watermarked_at = NULL WHERE image_id = ?', [(int) $row['id']]);
        $this->touchPiece((int) $row['piece_id']);
        $this->log($request, 'image_watermark_removed', (int) $row['id'], ['piece_id' => (int) $row['piece_id']]);

        return Json::write($response, ['image' => $this->imageView((int) $row['id'])]);
    }

    private function ensureBase(array $row): string
    {
        $source = $this->source((int) $row['id']);
        if ($source !== null) {
            $path = $this->images->originalsDir() . '/' . basename($source['base_file']);
            if (! is_file($path)) {
                throw new ImageException('source_missing', 409);
            }

            return $path;
        }

        $public = $this->publicPath($row);
        if (! is_file($public)) {
            throw new ImageException('file_missing', 404);
        }
        $info = $this->images->inspect($public);
        if ($info['type'] !== IMAGETYPE_JPEG) {
            throw new ImageException('unsupported_type');
        }

        $this->images->ensureDir($this->images->originalsDir());
        $name = bin2hex(random_bytes(8)) . '-base.jpg';
        $path = $this->images->originalsDir() . '/' . $name;
        $this->images->publish($public, $path);
        $this->run('INSERT INTO piece_image_sources (image_id, base_file, original_file, watermark, watermarked_at) VALUES (?, ?, NULL, NULL, NULL)', [(int) $row['id'], $name]);

        return $path;
    }

    private function findImage(int $id): ?array
    {
        $row = $this->run('SELECT i.id, i.piece_id, i.filename, p.deleted_at AS piece_deleted_at FROM piece_images i JOIN pieces p ON p.id = i.piece_id WHERE i.id = ?', [$id])->fetch();

        return $row === false ? null : $row;
    }

    private function source(int $imageId): ?array
    {
        $row = $this->run('SELECT base_file, original_file, watermark FROM piece_image_sources WHERE image_id = ?', [$imageId])->fetch();

        return $row === false ? null : $row;
    }

    private function watermarkReview(int $imageId): ?bool
    {
        $value = $this->run('SELECT has_watermark FROM piece_image_watermark_reviews WHERE image_id = ?', [$imageId])->fetchColumn();

        return $value === false ? null : (bool) $value;
    }

    private function publicPath(array $row): string
    {
        return $this->images->publicDir() . '/' . basename((string) $row['filename']);
    }

    private function imageView(int $imageId): array
    {
        $row = $this->run('SELECT i.id, i.filename, i.kind, i.alt_text, i.sort_order, s.image_id AS source_id, s.watermark, r.has_watermark AS reviewed_has_watermark FROM piece_images i LEFT JOIN piece_image_sources s ON s.image_id = i.id LEFT JOIN piece_image_watermark_reviews r ON r.image_id = i.id WHERE i.id = ?', [$imageId])->fetch();

        return $this->view($row);
    }

    private function view(array $row): array
    {
        $state = $row['source_id'] !== null
            ? ($row['watermark'] === null ? 'clean' : 'applied')
            : ($row['reviewed_has_watermark'] === null ? 'unknown' : ((bool) $row['reviewed_has_watermark'] ? 'preexisting' : 'clean'));

        return [
            'id' => (int) $row['id'],
            'filename' => $row['filename'],
            'kind' => $row['kind'],
            'alt_text' => $row['alt_text'],
            'sort_order' => (int) $row['sort_order'],
            'tracked' => $row['source_id'] !== null,
            'watermark_state' => $state,
            'watermark' => $row['watermark'] === null ? null : json_decode((string) $row['watermark'], true),
        ];
    }

    private function touchPiece(int $pieceId): void
    {
        $this->run('UPDATE pieces SET updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $pieceId]);
    }

    private function stream(Response $response, string $path, string $type): Response
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $this->err($response, 404, 'not_found');
        }

        return $response->withBody(new Stream($handle))->withHeader('Content-Type', $type)->withHeader('Content-Length', (string) filesize($path));
    }

    private function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return $st;
    }

    private function log(Request $request, string $action, int $imageId, array $details): void
    {
        $this->audit->record($action, (int) $request->getAttribute('user')['id'], 'piece_image', (string) $imageId, $details, ClientIp::from($request), $request->getHeaderLine('User-Agent'));
    }

    private function err(Response $response, int $status, string $error, array $extra = []): Response
    {
        return Json::write($response, ['error' => $error] + $extra, $status);
    }
}
