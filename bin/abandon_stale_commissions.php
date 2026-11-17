<?php

declare(strict_types=1);

use App\Commissions\Workflow;
use App\Config;
use App\Database;
use App\Mail\SmtpMailer;
use Dotenv\Dotenv;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();
$config = new Config($root, $root . '/public', $_ENV);
$pdo = Database::connect($config);
$workflow = new Workflow($pdo, new SmtpMailer($config), $config);

$cutoff = $pdo->prepare("SELECT id FROM commissions WHERE status = 'info_requested' AND updated_at < ? ORDER BY id");
$cutoff->execute([gmdate('Y-m-d H:i:s', time() - 14 * 86400)]);

$done = 0;
foreach ($cutoff->fetchAll() as $row) {
    $r = $workflow->transition((int) $row['id'], 'abandoned', 'We did not hear back within 14 days.', null);
    fwrite(STDOUT, "#{$row['id']}: " . ($r['ok'] ? 'abandoned' : $r['error']) . PHP_EOL);
    $done += $r['ok'] ? 1 : 0;
}
fwrite(STDOUT, "Abandoned {$done}." . PHP_EOL);
