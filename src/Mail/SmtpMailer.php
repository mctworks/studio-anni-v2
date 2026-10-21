<?php

declare(strict_types=1);

namespace App\Mail;

use App\Config;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer implements Mailer
{
    public function __construct(private readonly Config $config)
    {
    }

    public function send(string $to, string $subject, string $text, ?string $replyTo = null, ?string $html = null): bool
    {
        try {
            $mail = $this->build();
            $mail->addAddress($to);
            if ($replyTo !== null && $replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }
            $mail->Subject = $subject;
            if ($html !== null) {
                $mail->isHTML(true);
                $mail->Body = $html;
                $mail->AltBody = $text;
            } else {
                $mail->Body = $text;
            }
            $mail->send();

            return true;
        } catch (MailException $e) {
            error_log('Mail send failed: ' . $e->getMessage());

            return false;
        }
    }

    /** @throws MailException */
    private function build(): PHPMailer
    {
        $user = $this->config->getRequired('MAIL_USER');
        $encryption = $this->config->get('MAIL_ENCRYPTION', 'ssl') === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config->getRequired('MAIL_HOST');
        $mail->Port = (int) $this->config->get('MAIL_PORT', $encryption === PHPMailer::ENCRYPTION_SMTPS ? '465' : '587');
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $this->config->getRequired('MAIL_PASS');
        $mail->SMTPSecure = $encryption;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(false);
        $mail->setFrom($this->config->get('MAIL_FROM', $user), $this->config->get('MAIL_FROM_NAME', 'Studio Anni'));

        return $mail;
    }
}