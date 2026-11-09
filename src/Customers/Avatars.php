<?php

declare(strict_types=1);

namespace App\Customers;

use App\Config;
use App\Images\ImageException;
use App\Images\ImageService;
use Psr\Http\Message\UploadedFileInterface;

final class Avatars
{
    public const SIZE = 256;
    public const MAX_BYTES = 5242880;

    public function __construct(private readonly ImageService $images, private readonly Config $config)
    {
    }

    public static function url(?string $file): ?string
    {
        return $file === null || $file === '' ? null : '/media/avatars/' . rawurlencode($file);
    }

    public function maxBytes(): int
    {
        return min(self::MAX_BYTES, $this->images->maxBytes());
    }

    /** @throws ImageException */
    public function store(UploadedFileInterface $file): string
    {
        $dir = $this->dir();
        $tmpDir = $this->config->root . '/storage/uploads';
        foreach ([$dir, $tmpDir] as $d) {
            if (! is_dir($d) && ! @mkdir($d, 0755, true) && ! is_dir($d)) {
                throw new ImageException('storage_unavailable', 500);
            }
        }

        $tmp = $tmpDir . '/avatar-' . bin2hex(random_bytes(8)) . '.upload';
        try {
            $file->moveTo($tmp);
            $info = $this->images->inspect($tmp);
            $name = bin2hex(random_bytes(16)) . '.jpg';
            $dest = $dir . '/' . $name;
            if ($this->images->hasGd()) {
                $src = $this->images->load($tmp, $info['type']);
                $w = imagesx($src);
                $h = imagesy($src);
                $side = min($w, $h);
                $out = imagecreatetruecolor(self::SIZE, self::SIZE);
                imagecopyresampled($out, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
                $this->images->saveJpeg($out, $dest, 85);
            } else {
                $this->magick($tmp, $dest);
            }

            return $name;
        } finally {
            @unlink($tmp);
        }
    }

    private function magick(string $in, string $dest): void
    {
        $size = self::SIZE . 'x' . self::SIZE;
        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $cmd = [
            (string) $this->config->get('IMAGE_PROCESSOR', 'gm'), 'convert', $in . '[0]',
            '-auto-orient', '-background', 'white', '-flatten',
            '-thumbnail', $size . '^', '-gravity', 'center', '-extent', $size,
            '-quality', '85', 'JPEG:' . $tmp,
        ];
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($p)) {
            throw new ImageException('image_processor_unavailable', 500);
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($p) !== 0 || ! @rename($tmp, $dest)) {
            @unlink($tmp);
            throw new ImageException('image_processing_failed', 500);
        }
    }

    public function delete(?string $file): void
    {
        if ($file !== null && preg_match('/^[a-f0-9]{32}\.jpg$/', $file) === 1) {
            @unlink($this->dir() . '/' . $file);
        }
    }

    public function dir(): string
    {
        return rtrim($this->config->publicDir, '/') . '/media/avatars';
    }
}
