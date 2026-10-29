<?php

declare(strict_types=1);

namespace App\Http;

use App\Config;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BlueskyController
{
    private const SERVICE = 'https://bsky.social';
    private const FEED_LIMIT = 25;
    private const CACHE_TTL = 300;

    public function __construct(private readonly Config $config)
    {
    }

    public function feed(Request $request, Response $response): Response
    {
        $cacheFile = $this->config->root . '/storage/cache/bluesky-feed.json';
        $cached = $this->readCache($cacheFile);
        if ($cached !== null) {
            return $this->json($response, $cached);
        }

        try {
            $handle = $this->config->getRequired('BSKY_HANDLE');
            $appPassword = $this->config->getRequired('BSKY_APP_PASSWORD');
            $session = $this->requestJson(self::SERVICE . '/xrpc/com.atproto.server.createSession', [], [
                'identifier' => $handle,
                'password' => $appPassword,
            ]);

            if (! is_string($session['accessJwt'] ?? null)) {
                throw new \RuntimeException('Bluesky session response was invalid.');
            }

            $query = http_build_query([
                'actor' => $handle,
                'filter' => 'posts_and_author_threads',
                'limit' => self::FEED_LIMIT,
            ]);
            $feed = $this->requestJson(self::SERVICE . '/xrpc/app.bsky.feed.getAuthorFeed?' . $query, [
                'Authorization: Bearer ' . $session['accessJwt'],
            ]);

            if (! is_array($feed['feed'] ?? null)) {
                throw new \RuntimeException('Bluesky feed response was invalid.');
            }

            $payload = ['feed' => array_slice($feed['feed'], 0, self::FEED_LIMIT)];
            $this->writeCache($cacheFile, $payload);

            return $this->json($response, $payload);
        } catch (\Throwable $error) {
            error_log('Bluesky feed request failed: ' . $error->getMessage());

            return $this->json($response, ['error' => 'Bluesky feed is temporarily unavailable.'], 502);
        }
    }

    /** @param list<string> $headers @param array<string, string>|null $body @return array<string, mixed> */
    private function requestJson(string $url, array $headers = [], ?array $body = null): array
    {
        $headers[] = 'Accept: application/json';
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
        ];

        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize the Bluesky request.');
        }

        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (! is_string($raw) || $status < 200 || $status >= 300) {
            throw new \RuntimeException($curlError !== '' ? $curlError : "Bluesky returned HTTP {$status}.");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Bluesky returned invalid JSON.');
        }

        return $decoded;
    }

    /** @return array{feed: list<mixed>}|null */
    private function readCache(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }

        $cached = json_decode((string) @file_get_contents($file), true);
        if (! is_array($cached) || ! is_int($cached['expiresAt'] ?? null) || $cached['expiresAt'] < time()
            || ! is_array($cached['payload']['feed'] ?? null)) {
            return null;
        }

        return ['feed' => array_slice($cached['payload']['feed'], 0, self::FEED_LIMIT)];
    }

    /** @param array{feed: list<mixed>} $payload */
    private function writeCache(string $file, array $payload): void
    {
        $directory = dirname($file);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $contents = json_encode([
            'expiresAt' => time() + self::CACHE_TTL,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);

        if (@file_put_contents($temporary, $contents, LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $file);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=60');
    }
}
