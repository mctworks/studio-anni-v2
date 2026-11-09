<?php

declare(strict_types=1);

// usage: php bin/enroll_totp.php --email=person@example.com
// Sets up the authenticator and recovery codes for an admin who was promoted in MIMOSA.
use App\Auth\AuditLog;
use App\Auth\RecoveryCodes;
use App\Auth\TotpService;
use App\Auth\Users;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

function fail(string $message): never
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function say(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function ask(string $question): string
{
    fwrite(STDOUT, $question);

    return trim((string) fgets(STDIN));
}

$email = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $email = Users::normalizeEmail(substr($arg, 8));
    } else {
        fail("Unrecognised argument: {$arg}");
    }
}
if ($email === '') {
    $email = Users::normalizeEmail(ask('Email: '));
}

$boot = static fn () => App\Kernel::create($root, $root . '/public')->getContainer();
$totp = $boot()->get(TotpService::class);
$user = $boot()->get(Users::class)->findByEmail($email);

if ($user === null || ! in_array($user['role'], ['admin', 'owner'], true) || $user['status'] !== 'active') {
    fail('No active admin or owner account with that email.');
}
if ($user['totp_secret_enc'] !== null) {
    fail('That account already has an authenticator. Remove admin access and grant it again to start over.');
}

$secret = $totp->newSecret($email);
say();
say('Add this account to the authenticator app (manual entry, time-based):');
say('  Secret: ' . $secret['secret']);
say('  Or open this URI in an app that accepts it: ' . $secret['uri']);
say();

$step = null;
for ($attempt = 1; $attempt <= 3 && $step === null; $attempt++) {
    $step = $totp->matchStep($secret['encrypted'], ask('Enter the 6-digit code the app shows: '), null);
    if ($step === null) {
        say('That code did not match.');
    }
}
if ($step === null) {
    fail('Authenticator not confirmed. Nothing was changed.');
}

// the DB drops idle connections during the prompts above, so connect fresh here
$container = $boot();
$pdo = $container->get(PDO::class);
$id = (int) $user['id'];
$now = gmdate('Y-m-d H:i:s');
$st = $pdo->prepare('UPDATE users SET totp_secret_enc = ?, totp_enrolled_at = ?, totp_last_step = ?, updated_at = ? WHERE id = ? AND totp_secret_enc IS NULL');
$st->execute([$secret['encrypted'], $now, $step, $now, $id]);
if ($st->rowCount() !== 1) {
    fail('The account changed while you were setting up. Nothing was saved.');
}

try {
    $codes = $container->get(RecoveryCodes::class)->issue($id);
} catch (Throwable $e) {
    $pdo->prepare('UPDATE users SET totp_secret_enc = NULL, totp_enrolled_at = NULL, totp_last_step = NULL WHERE id = ?')->execute([$id]);
    fail('Could not issue recovery codes, so the authenticator was not saved. ' . $e::class);
}

$container->get(AuditLog::class)->record('totp_enrolled_cli', null, 'user', (string) $id, []);

say();
say("Authenticator set up for {$email}.");
say();
say('Recovery codes. Each works once, together with the password, if the authenticator is lost.');
say('They are shown only now. Store them somewhere safe and offline.');
say();
foreach (array_chunk($codes, 2) as $pair) {
    say('  ' . implode('    ', $pair));
}
say();
