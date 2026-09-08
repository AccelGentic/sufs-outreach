<?php
/**
 * SMTP driver via PHPMailer. Only loaded when MAIL_DRIVER = 'smtp'.
 * Requires: composer require phpmailer/phpmailer (from the app root).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Same signature and return shape as send_via_mailgun() in mailgun.php,
 * so send.php can call whichever driver is configured without caring
 * which one it is.
 */
function send_via_smtp(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainTextBody,
    string $replyToEmail,
    string $replyToName,
    ?string $ccEmail = null
): array {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo($replyToEmail, $replyToName);

        if ($ccEmail) {
            $mail->addCC($ccEmail);
        }

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainTextBody;

        $mail->send();

        return ['success' => true, 'error' => null];
    } catch (PHPMailerException $e) {
        return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}
