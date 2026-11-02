<?php

declare(strict_types=1);

namespace App\Images;

use App\Config;

final class ImageService
{
    public const MAX_SIDE = 950;
    public const VARIANTS = ['dark', 'light'];

    public function __construct(private readonly Config $config)
    {
    }

    public function originalsDir(): string
    {
        return $this->config->root . '/storage/originals';
    }

    public function publicDir(): string
    {
        return rtrim($this->config->publicDir, '/') . '/media/pieces';
    }

    public function logoPath(string $variant): ?string
    {
        if (! in_array($variant, self::VARIANTS, true)) {
            return null;
        }
        $path = $this->config->root . '/storage/watermarks/' . $variant . '.png';

        return is_file($path) ? $path : null;
    }

    public function availableLogos(): array
    {
        return array_values(array_filter(self::VARIANTS, fn (string $v): bool => $this->logoPath($v) !== null));
    }

    public function maxBytes(): int
    {
        return (int) $this->config->get('IMAGE_MAX_BYTES', '31457280');
    }

    public function limits(): array
    {
        return ['upload_bytes' => self::iniBytes((string) ini_get('upload_max_filesize')), 'post_bytes' => self::iniBytes((string) ini_get('post_max_size')), 'app_bytes' => $this->maxBytes()];
    }

    public static function iniBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        $n = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new ImageException('storage_unavailable', 500);
        }
    }

    public function requireGd(): void
    {
        if (! extension_loaded('gd') || (imagetypes() & (IMG_JPG | IMG_PNG)) !== (IMG_JPG | IMG_PNG)) {
            throw new ImageException('gd_unavailable', 500);
        }
    }

    public function hasGd(): bool
    {
        return extension_loaded('gd') && (imagetypes() & (IMG_JPG | IMG_PNG)) === (IMG_JPG | IMG_PNG);
    }

    public function inspect(string $path): array
    {
        if ($this->hasGd()) {
            $info = @getimagesize($path);
            $type = $info[2] ?? null;
            $width = $info[0] ?? 0;
            $height = $info[1] ?? 0;
        } else {
            $identified = $this->runGraphicsMagick(['identify', '-format', '%m %w %h', $path]);
            $parts = preg_split('/\s+/', trim($identified)) ?: [];
            $type = match (strtoupper($parts[0] ?? '')) {
                'JPEG', 'JPG' => IMAGETYPE_JPEG,
                'PNG' => IMAGETYPE_PNG,
                default => null,
            };
            $width = isset($parts[1]) ? (int) $parts[1] : 0;
            $height = isset($parts[2]) ? (int) $parts[2] : 0;
        }
        if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $width < 1 || $height < 1) {
            throw new ImageException('unsupported_type');
        }
        if ($width * $height > (int) $this->config->get('IMAGE_MAX_PIXELS', '40000000')) {
            throw new ImageException('dimensions_too_large');
        }

        return ['type' => $type, 'width' => $width, 'height' => $height];
    }

    public function watermarkJpeg(string $basePath, string $destination, array $params): void
    {
        $base = $this->inspect($basePath);
        if ($base['type'] !== IMAGETYPE_JPEG) {
            throw new ImageException('unsupported_type');
        }
        $logoPath = $this->logoPath((string) $params['logo']);
        if ($logoPath === null) {
            throw new ImageException('logo_missing');
        }
        $logo = $this->inspect($logoPath);
        $width = max(1, (int) round((float) $params['width'] * $base['width']));
        $height = max(1, (int) round($width * $logo['height'] / $logo['width']));
        $x = (int) round((float) $params['x'] * $base['width']);
        $y = (int) round((float) $params['y'] * $base['height']);
        $token = bin2hex(random_bytes(6));
        $markPath = $this->originalsDir() . '/wm-' . $token . '.png';
        $outputPath = $destination . '.' . $token . '.tmp.jpg';

        try {
            $this->ensureDir($this->originalsDir());
            $this->runGraphicsMagick(['convert', $logoPath, '-resize', $width . 'x' . $height . '!', $markPath]);
            $this->runGraphicsMagick([
                'composite',
                '-dissolve',
                (string) (int) round((float) $params['opacity'] * 100),
                '-geometry',
                '+' . $x . '+' . $y,
                $markPath,
                $basePath,
                $outputPath,
            ]);
            if (! rename($outputPath, $destination)) {
                throw new ImageException('write_failed', 500);
            }
        } finally {
            @unlink($markPath);
            @unlink($outputPath);
        }
    }

    public function load(string $path, int $type): \GdImage
    {
        $img = $type === IMAGETYPE_PNG ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($img === false) {
            throw new ImageException('unsupported_type');
        }
        if ($type === IMAGETYPE_PNG) {
            return $this->flatten($img);
        }

        return $this->orient($img, $path);
    }

    public function loadJpeg(string $path): \GdImage
    {
        $img = @imagecreatefromjpeg($path);
        if ($img === false) {
            throw new ImageException('unsupported_type');
        }

        return $img;
    }

    public function shrink(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long <= self::MAX_SIDE) {
            return $img;
        }
        $scale = self::MAX_SIDE / $long;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $out;
    }

    public function saveJpeg(\GdImage $img, string $dest, ?int $quality = null): void
    {
        $quality ??= max(60, min(95, (int) $this->config->get('IMAGE_JPEG_QUALITY', '85')));
        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (! imagejpeg($img, $tmp, $quality)) {
            @unlink($tmp);
            throw new ImageException('write_failed', 500);
        }
        if (! rename($tmp, $dest)) {
            @unlink($tmp);
            throw new ImageException('write_failed', 500);
        }
    }

    public function publish(string $source, string $dest): void
    {
        $tmp = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (! copy($source, $tmp) || ! rename($tmp, $dest)) {
            @unlink($tmp);
            throw new ImageException('write_failed', 500);
        }
    }

    public function normalizeWatermark(mixed $in): array
    {
        if (! is_array($in)) {
            throw new ImageException('invalid_watermark');
        }
        $num = static fn (mixed $v, float $min, float $max): ?float => (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= $min && $v <= $max ? round((float) $v, 4) : null;
        $out = [
            'logo' => $in['logo'] ?? null,
            'x' => $num($in['x'] ?? null, 0, 1),
            'y' => $num($in['y'] ?? null, 0, 1),
            'width' => $num($in['width'] ?? null, 0.02, 1),
            'opacity' => $num($in['opacity'] ?? null, 0.05, 1),
        ];
        if (! is_string($out['logo']) || ! in_array($out['logo'], self::VARIANTS, true) || in_array(null, $out, true)) {
            throw new ImageException('invalid_watermark');
        }
        if ($this->logoPath($out['logo']) === null) {
            throw new ImageException('logo_missing');
        }

        return $out;
    }

    public function burn(\GdImage $base, array $p): \GdImage
    {
        $logo = @imagecreatefrompng((string) $this->logoPath($p['logo']));
        if ($logo === false) {
            throw new ImageException('logo_unreadable', 500);
        }
        $bw = imagesx($base);
        $bh = imagesy($base);
        $lw = max(1, (int) round($p['width'] * $bw));
        $lh = max(1, (int) round($lw * imagesy($logo) / imagesx($logo)));

        $scaled = imagecreatetruecolor($lw, $lh);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $logo, 0, 0, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));

        $opacity = (float) $p['opacity'];
        if ($opacity < 1.0) {
            for ($y = 0; $y < $lh; $y++) {
                for ($x = 0; $x < $lw; $x++) {
                    $c = imagecolorat($scaled, $x, $y);
                    $alpha = 127 - (int) round((127 - (($c >> 24) & 0x7F)) * $opacity);
                    imagesetpixel($scaled, $x, $y, ($alpha << 24) | ($c & 0xFFFFFF));
                }
            }
        }

        imagealphablending($base, true);
        imagecopy($base, $scaled, (int) round($p['x'] * $bw), (int) round($p['y'] * $bh), 0, 0, $lw, $lh);

        return $base;
    }

    private function flatten(\GdImage $src): \GdImage
    {
        $out = imagecreatetruecolor(imagesx($src), imagesy($src));
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagealphablending($out, true);
        imagecopy($out, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));

        return $out;
    }

    private function orient(\GdImage $img, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);

        return $rotated === false ? $img : $rotated;
    }

    private function runGraphicsMagick(array $arguments): string
    {
        $binary = $this->config->get('IMAGE_PROCESSOR', 'gm');
        $process = @proc_open(
            array_merge([$binary], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            throw new ImageException('image_processor_unavailable', 500);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new ImageException(trim((string) $stderr) !== '' ? 'image_processing_failed' : 'image_processor_unavailable', 500);
        }

        return (string) $stdout;
    }
}
