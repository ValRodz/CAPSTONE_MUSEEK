<?php
// Manual PHPMailer includes (Composer-free). Ensure these files exist in vendor/phpmailer/src
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

/**
 * Returns a configured PHPMailer instance using environment variables if available,
 * falling back to sensible defaults. Update the defaults below to your SMTP provider.
 */
function museekMailer(): PHPMailer {
    $mail = new PHPMailer(true);

    // SMTP configuration
    $mail->isSMTP();
    $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
    $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
    $mail->SMTPAuth   = true;

    // TLS by default; change to PHPMailer::ENCRYPTION_SMTPS and port 465 for implicit SSL
    $secure = getenv('SMTP_SECURE') ?: 'tls';
    $mail->SMTPSecure = ($secure === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

    $mail->Username   = getenv('SMTP_USERNAME') ?: 'kyzzer.jallorina@gmail.com'; // TODO: set your SMTP username
    $mail->Password   = getenv('SMTP_PASSWORD') ?: 'zpho kjwv wbvc naod';    // TODO: set your SMTP app password

    // Sender identity
    $fromEmail = getenv('SMTP_FROM_EMAIL') ?: $mail->Username;
    $fromName  = getenv('SMTP_FROM_NAME') ?: 'Museek';
    $mail->setFrom($fromEmail, $fromName);

    // Optional debug (0=off, 2=verbose)
    $mail->SMTPDebug = (int)(getenv('SMTP_DEBUG') ?: 2);
    $mail->Debugoutput = 'error_log';

    // Ensure UTF-8 for proper currency symbols and accents
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    return $mail;
}

/**
 * Send a verification email containing a 6-digit OTP code.
 *
 * @param string $toEmail Recipient email
 * @param string $toName  Recipient display name (optional)
 * @param string $otp     6-digit code
 * @param int    $expiresAt Unix timestamp when the OTP expires
 *
 * @return bool True on success, false on failure
 */
function logMailError(string $context, string $message): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/mail.log';
    $stamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$stamp] $context: $message\n", FILE_APPEND);
}

function sendVerificationEmail(string $toEmail, string $toName, string $otp, int $expiresAt, string $context = 'login'): bool {
    $mail = museekMailer();

    try {
        $mail->clearAddresses();
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        $expiryMin = max(1, (int)ceil(($expiresAt - time()) / 60));
        $isRegistration = ($context === 'registration');
        $subject = $isRegistration ? 'Verify your Museek registration' : 'Your Museek verification code';
        $intro = $isRegistration ? 'Use this code to verify your registration:' : 'Use this code to verify your login:';
        $ignore = $isRegistration ? 'If you did not attempt to register, please ignore this email.' : 'If you did not attempt to sign in, please ignore this email.';

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = '<p>' . $intro . '</p>' .
                         '<h2 style="letter-spacing:3px">' . htmlspecialchars($otp) . '</h2>' .
                         '<p>This code expires in ' . $expiryMin . ' minute(s).</p>' .
                         '<p>' . $ignore . '</p>';
        $mail->AltBody = "Your Museek verification code is: $otp\nThis code expires in $expiryMin minute(s).";

        $sent = $mail->send();
        if (!$sent) {
            error_log('PHPMailer send() failed: ' . $mail->ErrorInfo);
            logMailError('sendVerificationEmail', $mail->ErrorInfo);
        }
        return $sent;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        error_log('PHPMailer ErrorInfo: ' . (isset($mail) ? $mail->ErrorInfo : 'n/a'));
        logMailError('sendVerificationEmail-exception', isset($mail) ? $mail->ErrorInfo : $e->getMessage());
        return false;
    }
}

function sendVerificationLinkEmail(string $toEmail, string $toName, string $verifyUrl, int $expiresAt, string $purpose = 'Verify your email'): bool {
    $mail = museekMailer();
    try {
        $mail->clearAddresses();
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $expiryMin = max(1, (int)ceil(($expiresAt - time()) / 60));
        $mail->isHTML(true);
        $mail->Subject = $purpose;
        $mail->Body    = '<p>Please confirm your email by clicking the button below:</p>' .
                         '<p><a href="' . htmlspecialchars($verifyUrl) . '" style="display:inline-block;padding:10px 16px;background:#111;color:#fff;text-decoration:none;border-radius:6px">Confirm Email</a></p>' .
                         '<p>This link expires in ' . $expiryMin . ' minute(s).</p>' .
                         '<p>If you did not attempt to register or sign in, you can ignore this message.</p>';
        $mail->AltBody = "Confirm your email: $verifyUrl\nThis link expires in $expiryMin minute(s).";
        
        $sent = $mail->send();
        if (!$sent) {
            error_log('PHPMailer send() failed (link): ' . $mail->ErrorInfo);
            logMailError('sendVerificationLinkEmail', $mail->ErrorInfo);
        }
        return $sent;
    } catch (Exception $e) {
        error_log('PHPMailer link email error: ' . $e->getMessage());
        error_log('PHPMailer ErrorInfo: ' . (isset($mail) ? $mail->ErrorInfo : 'n/a'));
        logMailError('sendVerificationLinkEmail-exception', isset($mail) ? $mail->ErrorInfo : $e->getMessage());
        return false;
    }
}

function sendTransactionalEmail(string $toEmail, string $toName, string $subject, string $htmlBody, ?string $altBody = null): bool {
    $mail = museekMailer();
    try {
        $mail->clearAddresses();
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody ?: strip_tags($htmlBody);

        $sent = $mail->send();
        if (!$sent) {
            error_log('PHPMailer send() failed (transactional): ' . $mail->ErrorInfo);
            logMailError('sendTransactionalEmail', $mail->ErrorInfo);
        }
        return $sent;
    } catch (Exception $e) {
        error_log('PHPMailer transactional email error: ' . $e->getMessage());
        error_log('PHPMailer ErrorInfo: ' . (isset($mail) ? $mail->ErrorInfo : 'n/a'));
        logMailError('sendTransactionalEmail-exception', isset($mail) ? $mail->ErrorInfo : $e->getMessage());
        return false;
    }
}