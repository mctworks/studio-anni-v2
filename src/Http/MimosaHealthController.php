<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;
use App\Images\ImageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MimosaHealthController
{
    private const EXTENSIONS = ['pdo_mysql', 'curl', 'mbstring', 'json', 'openssl'];
    private const SERVICES = [
        'mail' => ['label' => 'Email (SMTP)', 'keys' => ['MAIL_HOST', 'MAIL_USER', 'MAIL_PASS']],
        'bluesky' => ['label' => 'Bluesky feed', 'keys' => ['BSKY_HANDLE', 'BSKY_APP_PASSWORD']],
        'usps' => ['label' => 'USPS shipping', 'keys' => ['USPS_ORIGIN_ZIP', 'USPS_CLIENT_ID', 'USPS_CLIENT_SECRET']],
    ];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Config $config,
        private readonly ImageService $images,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $checks = [$this->database(), $this->commissionTables(), $this->php(), $this->imageProcessor(), $this->storage()];
        foreach (self::SERVICES as $id => $service) {
            $checks[] = $this->service($id, $service['label'], $service['keys']);
        }

        $worst = 'ok';
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') {
                $worst = 'fail';
            } elseif ($c['status'] === 'warn' && $worst === 'ok') {
                $worst = 'warn';
            }
        }

        return Json::write($response, ['status' => $worst, 'checked_at' => gmdate('c'), 'checks' => $checks]);
    }

    private function database(): array
    {
        $start = hrtime(true);
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
        } catch (\Throwable) {
            return $this->check('database', 'Database', 'fail', 'Query failed');
        }
        $ms = (int) round((hrtime(true) - $start) / 1_000_000);

        return $this->check('database', 'Database', $ms > 500 ? 'warn' : 'ok', "Reachable, {$ms} ms");
    }

    private function commissionTables(): array
    {
        try {
            $this->pdo->query('SELECT 1 FROM commission_prices LIMIT 1')->fetchColumn();
            $this->pdo->query('SELECT 1 FROM commissions LIMIT 1')->fetchColumn();
        } catch (\Throwable) {
            return $this->check('migrations', 'Commission tables', 'fail', 'Missing. Run migrations/0011_commissions.sql');
        }

        return $this->check('migrations', 'Commission tables', 'ok', 'Present');
    }

    private function php(): array
    {
        $missing = array_values(array_filter(self::EXTENSIONS, static fn (string $e): bool => ! extension_loaded($e)));
        $detail = 'PHP ' . PHP_VERSION;

        return $missing === []
            ? $this->check('php', 'PHP', 'ok', $detail)
            : $this->check('php', 'PHP', 'fail', $detail . ', missing: ' . implode(', ', $missing));
    }

    private function imageProcessor(): array
    {
        return $this->images->hasGd()
            ? $this->check('images', 'Image processing', 'ok', 'GD with JPEG and PNG')
            : $this->check('images', 'Image processing', 'warn', 'GD unavailable, using GraphicsMagick (' . $this->config->get('IMAGE_PROCESSOR', 'gm') . ')');
    }

    private function storage(): array
    {
        $bad = [];
        foreach (['storage/cache', 'storage/logs', 'storage/uploads'] as $dir) {
            if (! is_dir($this->config->root . '/' . $dir) || ! is_writable($this->config->root . '/' . $dir)) {
                $bad[] = $dir;
            }
        }

        return $bad === []
            ? $this->check('storage', 'Storage folders', 'ok', 'Writable')
            : $this->check('storage', 'Storage folders', 'fail', 'Not writable: ' . implode(', ', $bad));
    }

    private function service(string $id, string $label, array $keys): array
    {
        $missing = array_values(array_filter($keys, fn (string $k): bool => $this->config->get($k) === null));
        if ($missing !== []) {
            return $this->check($id, $label, 'off', 'Not configured: ' . implode(', ', $missing));
        }
        if ($id === 'bluesky') {
            $file = $this->config->root . '/storage/cache/bluesky-feed.json';
            $mtime = is_file($file) ? filemtime($file) : false;
            if ($mtime === false) {
                return $this->check($id, $label, 'warn', 'Configured, no successful fetch cached yet');
            }
            $hours = (int) floor((time() - $mtime) / 3600);

            return $hours >= 24
                ? $this->check($id, $label, 'warn', "Configured, last successful fetch {$hours} h ago")
                : $this->check($id, $label, 'ok', 'Configured, last successful fetch ' . ($hours < 1 ? 'under 1 h' : "{$hours} h") . ' ago');
        }

        return $this->check($id, $label, 'ok', 'Credentials configured');
    }

    private function check(string $id, string $label, string $status, string $detail): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
