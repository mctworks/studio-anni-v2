<?php

declare(strict_types=1);

namespace App\Commissions;

use App\Config;
use App\Mail\Mailer;

final class Workflow
{
    public const TRANSITIONS = [
        'submitted' => ['info_requested', 'quoted', 'denied'],
        'info_requested' => ['quoted', 'denied', 'abandoned'],
        'customer_replied' => ['info_requested', 'quoted', 'denied'],
        'quoted' => ['deposit_paid', 'abandoned', 'stopped'],
        'deposit_paid' => ['in_progress', 'stopped', 'refunded'],
        'in_progress' => ['completed', 'stopped', 'refunded'],
        'completed' => ['awaiting_balance', 'shipped'],
        'awaiting_balance' => ['shipped', 'stopped'],
        'shipped' => ['refunded'],
        'stopped' => ['refunded'],
        'denied' => [],
        'abandoned' => [],
        'refunded' => [],
    ];
    public const NOTE_REQUIRED = ['info_requested'];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly Mailer $mailer,
        private readonly Config $config,
    ) {
    }

    public static function next(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public function open(int $commissionId, int $userId, int $totalCents, string $now): void
    {
        $this->pdo->prepare(
            "INSERT INTO orders (user_id, kind, commission_id, status, total_cents, paid_cents, created_at, updated_at) VALUES (?, 'commission', ?, 'submitted', ?, 0, ?, ?)"
        )->execute([$userId, $commissionId, $totalCents, $now, $now]);
        $this->pdo->prepare(
            "INSERT INTO commission_events (commission_id, actor_user_id, kind, from_status, to_status, note, emailed, created_at) VALUES (?, ?, 'status', NULL, 'submitted', NULL, 0, ?)"
        )->execute([$commissionId, $userId, $now]);
    }

    public function transition(int $id, string $to, ?string $note, ?int $actorId): array
    {
        $note = $note === null ? null : trim(str_replace("\r\n", "\n", $note));
        $note = $note === '' ? null : $note;
        if ($note !== null && mb_strlen($note) > 4000) {
            return ['ok' => false, 'error' => 'note_too_long'];
        }
        if (! isset(self::TRANSITIONS[$to])) {
            return ['ok' => false, 'error' => 'invalid_status'];
        }

        $st = $this->pdo->prepare('SELECT status FROM commissions WHERE id = ?');
        $st->execute([$id]);
        $from = $st->fetchColumn();
        if ($from === false) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        $from = (string) $from;
        if (! in_array($to, self::next($from), true)) {
            return ['ok' => false, 'error' => 'invalid_transition'];
        }
        if ($note === null && in_array($to, self::NOTE_REQUIRED, true)) {
            return ['ok' => false, 'error' => 'note_required'];
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $up = $this->pdo->prepare('UPDATE commissions SET status = ?, updated_at = ? WHERE id = ? AND status = ?');
            $up->execute([$to, $now, $id, $from]);
            if ($up->rowCount() !== 1) {
                $this->pdo->rollBack();

                return ['ok' => false, 'error' => 'conflict'];
            }
            $this->pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE commission_id = ?')->execute([$to, $now, $id]);
            $this->pdo->prepare(
                "INSERT INTO commission_events (commission_id, actor_user_id, kind, from_status, to_status, note, emailed, created_at) VALUES (?, ?, 'status', ?, ?, ?, 0, ?)"
            )->execute([$id, $actorId, $from, $to, $note, $now]);
            $eventId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Commission transition failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'save_failed'];
        }

        $emailed = $this->notifyCustomer($id, $to, $note);
        if ($emailed) {
            $this->pdo->prepare('UPDATE commission_events SET emailed = 1 WHERE id = ?')->execute([$eventId]);
        }

        return ['ok' => true, 'emailed' => $emailed];
    }

    public function reply(int $id, int $userId, string $message): ?string
    {
        $message = trim(str_replace("\r\n", "\n", $message));
        if (mb_strlen($message) < 2 || mb_strlen($message) > 4000) {
            return 'invalid_message';
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $up = $this->pdo->prepare("UPDATE commissions SET status = 'customer_replied', updated_at = ? WHERE id = ? AND user_id = ? AND status = 'info_requested'");
            $up->execute([$now, $id, $userId]);
            if ($up->rowCount() !== 1) {
                $this->pdo->rollBack();

                return 'not_awaiting_reply';
            }
            $this->pdo->prepare("UPDATE orders SET status = 'customer_replied', updated_at = ? WHERE commission_id = ?")->execute([$now, $id]);
            $this->pdo->prepare(
                "INSERT INTO commission_events (commission_id, actor_user_id, kind, from_status, to_status, note, emailed, created_at) VALUES (?, ?, 'message', 'info_requested', 'customer_replied', ?, 0, ?)"
            )->execute([$id, $userId, $message, $now]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Commission reply failed: ' . $e->getMessage());

            return 'save_failed';
        }

        $c = $this->load($id);
        if ($c !== null) {
            $this->mailer->send(
                $this->adminAddress(),
                "Reply on portrait request #{$id}",
                "{$c['name']} <{$c['email']}> replied:\n\n{$message}\n\n" . $this->base() . "/mimosa/commissions/{$id}\n",
                $c['email'],
            );
        }

        return null;
    }

    private function notifyCustomer(int $id, string $status, ?string $note): bool
    {
        $c = $this->load($id);
        if ($c === null) {
            return false;
        }
        $copy = self::copy($status, $c, $this->depositPercent());
        if ($copy === null) {
            return false;
        }
        $body = "Hi {$c['name']},\n\n{$copy['body']}\n";
        if ($note !== null) {
            $body .= "\nMessage from Anni:\n{$note}\n";
        }
        $body .= "\nView your commission: " . $this->base() . "/account/commissions/{$id}\n\n- Studio Anni\n";

        return $this->mailer->send($c['email'], $copy['subject'], $body);
    }

    public static function copy(string $status, array $c, int $depositPercent): ?array
    {
        $ref = "portrait request #{$c['id']}";
        $total = '$' . number_format($c['quoted_cents'] / 100, 2);
        $deposit = '$' . number_format(intdiv($c['quoted_cents'] * $depositPercent + 99, 100) / 100, 2);

        return match ($status) {
            'info_requested' => ['subject' => "Anni has a question about your {$ref}", 'body' => "Anni needs a little more information before she can approve your {$ref}. Please sign in and reply on your commission page."],
            'quoted' => ['subject' => "Your {$ref} is approved", 'body' => "Good news! Anni approved your {$ref}.\n\nPrice: {$total} plus shipping. A {$depositPercent}% deposit ({$deposit}) or payment in full begins the work. Payment is completed on your commission page once it is open for this request."],
            'deposit_paid' => ['subject' => "Payment received for your {$ref}", 'body' => "Thank you! Your payment for {$ref} was received and Anni will begin soon."],
            'in_progress' => ['subject' => "Anni has started your {$ref}", 'body' => "Anni has started painting your {$ref}."],
            'completed' => ['subject' => "Your {$ref} is finished", 'body' => "Anni has finished your {$ref}."],
            'awaiting_balance' => ['subject' => "Balance due for your {$ref}", 'body' => "Your {$ref} is finished and waiting on the remaining balance before it ships."],
            'shipped' => ['subject' => "Your {$ref} has shipped", 'body' => "Your {$ref} is on its way."],
            'denied' => ['subject' => "Update on your {$ref}", 'body' => "Unfortunately Anni is not able to take on your {$ref}."],
            'abandoned' => ['subject' => "Your {$ref} was closed", 'body' => "Your {$ref} was closed because we did not hear back. You are welcome to submit a new request any time."],
            'stopped' => ['subject' => "Your {$ref} was stopped", 'body' => "Work on your {$ref} has been stopped."],
            'refunded' => ['subject' => "Your {$ref} was refunded", 'body' => "Your {$ref} has been refunded."],
            default => null,
        };
    }

    private function load(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id, c.quoted_cents, u.email, COALESCE(NULLIF(u.display_name, \'\'), u.email) AS name
             FROM commissions c JOIN users u ON u.id = c.user_id WHERE c.id = ?'
        );
        $st->execute([$id]);
        $r = $st->fetch();

        return $r === false ? null : ['id' => (int) $r['id'], 'quoted_cents' => (int) $r['quoted_cents'], 'email' => (string) $r['email'], 'name' => (string) $r['name']];
    }

    private function depositPercent(): int
    {
        $st = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute(['commission_deposit_percent']);
        $v = (int) $st->fetchColumn();

        return $v >= 1 && $v <= 100 ? $v : 50;
    }

    private function adminAddress(): string
    {
        return $this->config->get('MAIL_FROM') ?? $this->config->getRequired('MAIL_USER');
    }

    private function base(): string
    {
        return rtrim((string) $this->config->get('APP_URL', ''), '/');
    }
}
