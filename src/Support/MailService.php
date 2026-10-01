<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Env;

/**
 * Sends transactional emails (currently just the verification email).
 *
 * If MAIL_HOST is not configured (the default for local/assessment use),
 * the email is written to storage/logs/mail.log instead of being sent, so
 * the verification token/link is still easy to find for manual testing.
 */
final class MailService
{
    public static function sendVerificationEmail(string $toEmail, string $toName, string $rawToken): void
    {
        $appUrl = Env::get('APP_URL', 'http://localhost:8000');
        $verifyLink = rtrim((string) $appUrl, '/') . '/api/auth/verify-email?token=' . $rawToken;

        $subject = 'Verify your email address';
        $body = "Hi {$toName},\n\n"
            . "Thanks for signing up. Please verify your email address using the link or token below.\n\n"
            . "Link: {$verifyLink}\n"
            . "Token: {$rawToken}\n\n"
            . "This token will expire soon. If you did not request this, you can ignore this email.\n";

        if (self::isSmtpConfigured()) {
            self::sendViaSmtp($toEmail, $subject, $body);
            return;
        }

        self::logToFile($toEmail, $subject, $body);
    }

    private static function isSmtpConfigured(): bool
    {
        return (bool) Env::get('MAIL_HOST');
    }

    private static function sendViaSmtp(string $toEmail, string $subject, string $body): void
    {
        // A full SMTP client is intentionally out of scope for this
        // assessment project. Wire in PHPMailer/Symfony Mailer here if
        // real delivery is required - the rest of the app only depends on
        // MailService::sendVerificationEmail(), so the call sites do not
        // need to change.
        self::logToFile($toEmail, $subject, $body, true);
    }

    private static function logToFile(string $toEmail, string $subject, string $body, bool $viaSmtpStub = false): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $prefix = $viaSmtpStub ? '[SMTP-STUB]' : '[LOGGED]';
        $entry = sprintf(
            "%s %s To: %s | Subject: %s\n%s\n%s\n\n",
            date('Y-m-d H:i:s'),
            $prefix,
            $toEmail,
            $subject,
            $body,
            str_repeat('-', 60)
        );

        file_put_contents($dir . '/mail.log', $entry, FILE_APPEND);
    }
}
