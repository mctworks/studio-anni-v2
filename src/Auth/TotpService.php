<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use OTPHP\TOTP;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Symmetric\Crypto;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\HiddenString\HiddenString;

final class TotpService
{
    private const WINDOW = 1;

    private ?EncryptionKey $key = null;

    public function __construct(private readonly Config $config, private readonly string $issuer = 'Studio Anni')
    {
    }

    public function newSecret(string $accountLabel): array
    {
        $totp = TOTP::generate(null, 20);
        $totp->setLabel($accountLabel);
        $totp->setIssuer($this->issuer);
        $secret = $totp->getSecret();

        return ['secret' => $secret, 'uri' => $totp->getProvisioningUri(), 'encrypted' => $this->encrypt($secret)];
    }

    public function matchStep(string $encryptedSecret, string $code, ?int $lastStep, ?int $now = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $totp = TOTP::createFromSecret($this->decrypt($encryptedSecret));
        $period = $totp->getPeriod();
        $current = intdiv($now ?? time(), $period);

        $match = null;
        for ($step = max(0, $current - self::WINDOW); $step <= $current + self::WINDOW; $step++) {
            if (hash_equals($totp->at($step * $period), $code)) {
                $match = $step;
            }
        }

        return ($match === null || ($lastStep !== null && $match <= $lastStep)) ? null : $match;
    }

    public function consume(\PDO $pdo, int $userId, int $step): bool
    {
        $st = $pdo->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
        $st->execute([$step, $userId, $step]);

        return $st->rowCount() === 1;
    }

    public function encrypt(string $secret): string
    {
        return Crypto::encrypt(new HiddenString($secret), $this->key());
    }

    public function decrypt(string $encrypted): string
    {
        return Crypto::decrypt($encrypted, $this->key())->getString();
    }

    private function key(): EncryptionKey
    {
        return $this->key ??= KeyFactory::importEncryptionKey(new HiddenString($this->config->getRequired('APP_KEY')));
    }
}
