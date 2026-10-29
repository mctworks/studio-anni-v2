<?php

declare(strict_types=1);

// usage: php bin/create_admin.php [--email= --name= --role= --password-stdin --allow-second-owner]
use App\Auth\AuditLog;
use App\Auth\PasswordService;
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

function askHidden(string $question): string
{
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (! stream_isatty(STDIN) || ! function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
        fail('Cannot hide typing here. Run in a terminal where stty works, or pipe the password with --password-stdin.');
    }
    fwrite(STDOUT, $question);
    shell_exec('stty -echo');
    register_shutdown_function(static function (): void {
        shell_exec('stty echo');
    });
    $value = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    fwrite(STDOUT, PHP_EOL);

    return $value;
}

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) !== 1) {
        fail("Unrecognised argument: {$arg}");
    }
    $opts[$m[1]] = $m[2] ?? true;
}

$stdinPassword = isset($opts['password-stdin']) ? rtrim((string) fgets(STDIN), "\r\n") : null;

$boot = static fn () => App\Kernel::create($root, $root . '/public')->getContainer();

$container = $boot();
$passwords = $container->get(PasswordService::class);
$totp = $container->get(TotpService::class);

$email = Users::normalizeEmail(is_string($opts['email'] ?? null) ? $opts['email'] : ask('Email: '));
if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fail('That is not a valid email address.');
}
$users = $boot()->get(Users::class);
if ($users->findByEmail($email) !== null) {
    fail('An account with that email already exists.');
}
$hasOwner = $users->countByRole('owner') > 0;

$name = is_string($opts['name'] ?? null) ? trim($opts['name']) : ask('Display name (optional): ');
$name = $name === '' ? null : mb_substr($name, 0, 80);

$role = is_string($opts['role'] ?? null) ? $opts['role'] : ($hasOwner ? 'admin' : 'owner');
if (! in_array($role, ['owner', 'admin'], true)) {
    fail('Role must be owner or admin.');
}
if ($role === 'owner' && $hasOwner && ! isset($opts['allow-second-owner'])) {
    fail('An owner already exists. Pass --allow-second-owner if you really want another.');
}

if ($stdinPassword !== null) {
    $password = $stdinPassword;
} else {
    $password = askHidden('Password: ');
    if ($password !== askHidden('Repeat password: ')) {
        fail('The passwords did not match.');
    }
}
$problems = $passwords->validate($password);
if ($problems !== []) {
    $text = ['too_short' => 'must be at least ' . PasswordService::MIN_LENGTH . ' characters', 'too_long' => 'is too long', 'breached' => 'appears in the breached-password list'];
    fail('The password ' . implode(' and ', array_map(static fn (string $p): string => $text[$p] ?? $p, $problems)) . '.');
}

$secret = $totp->newSecret($email);
say();
say('Add this account to your authenticator app (manual entry, time-based):');
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
    fail('Authenticator not confirmed. Nothing was created.');
}

$hash = $passwords->hash($password);

// the DB drops idle connections during the prompts above, so connect fresh here
$container = $boot();
$users = $container->get(Users::class);
$recovery = $container->get(RecoveryCodes::class);
$audit = $container->get(AuditLog::class);

try {
    $id = $users->createWithTotp($email, $name, $hash, $role, $secret['encrypted'], $step);
} catch (PDOException $e) {
    fail($e->getCode() === '23000' ? 'An account with that email already exists.' : 'Database error: ' . $e::class);
}

try {
    $codes = $recovery->issue($id);
} catch (Throwable $e) {
    $users->delete($id);
    fail('Could not issue recovery codes, so the account was removed. ' . $e::class);
}

$audit->record('user_created_cli', null, 'user', (string) $id, ['role' => $role]);

say();
say("Created {$role} account {$email} (id {$id}).");
say();
say('Recovery codes. Each works once, together with the password, if the authenticator is lost.');
say('They are shown only now. Store them somewhere safe and offline.');
say();
foreach (array_chunk($codes, 2) as $pair) {
    say('  ' . implode('    ', $pair));
}
say();
