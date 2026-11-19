<?php

declare(strict_types=1);

use App\Commissions\Workflow;
use App\Config;
use App\Database;
use App\Mail\SmtpMailer;
use App\Payments\PaymentService;
use App\Payments\StripeGateway;
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
$mailer = new SmtpMailer($config);
$payments = new PaymentService($pdo, new StripeGateway($config), new Workflow($pdo, $mailer, $config), $mailer, $config);

fwrite(STDOUT, 'Released ' . $payments->expireStale() . '.' . PHP_EOL);
