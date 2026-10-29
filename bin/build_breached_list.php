<?php

declare(strict_types=1);

// usage: php bin/build_breached_list.php <plaintext-list> [output] [--min-length=12]
use App\Auth\PasswordService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$min = PasswordService::MIN_LENGTH;
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--min-length=(\d+)$/', $arg, $m) === 1) {
        $min = (int) $m[1];
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Unrecognised option: {$arg}\n");
        exit(1);
    } else {
        $args[] = $arg;
    }
}
if ($args === []) {
    fwrite(STDERR, "Usage: php bin/build_breached_list.php <plaintext-list.txt> [output] [--min-length=12]\n");
    exit(1);
}

$in = fopen($args[0], 'rb');
if ($in === false) {
    fwrite(STDERR, "Cannot read {$args[0]}\n");
    exit(1);
}
$out = $args[1] ?? $root . '/storage/data/breached-sha1.txt';

$hashes = [];
$read = 0;
while (($line = fgets($in)) !== false) {
    $read++;
    $line = rtrim($line, "\r\n");
    if (mb_strlen($line, 'UTF-8') < $min) {
        continue;
    }
    $hashes[] = strtoupper(sha1($line));
    if (count($hashes) > 5_000_000) {
        fwrite(STDERR, "More than 5 million qualifying lines: too large to sort in memory here. Filter the list first.\n");
        exit(1);
    }
}
fclose($in);

// byte order: BreachedPasswords binary-searches this file
sort($hashes, SORT_STRING);

$dir = dirname($out);
if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
$tmp = $out . '.tmp';
$fh = fopen($tmp, 'wb');
if ($fh === false) {
    fwrite(STDERR, "Cannot write {$tmp}\n");
    exit(1);
}
$written = 0;
$prev = '';
foreach ($hashes as $h) {
    if ($h !== $prev) {
        fwrite($fh, $h . "\n");
        $written++;
        $prev = $h;
    }
}
fclose($fh);
rename($tmp, $out);

fwrite(STDOUT, "Read {$read} lines, kept {$written} unique passwords of {$min}+ characters.\nWrote {$out}\n");
