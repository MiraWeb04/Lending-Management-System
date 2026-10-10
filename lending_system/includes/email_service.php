<?php

require_once __DIR__ . '/email_templates.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function ensureEmailNotificationsSchema(): void
{
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS email_notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipient VARCHAR(254) NOT NULL,
        notification_type VARCHAR(80) NOT NULL,
        reference_id VARCHAR(150) DEFAULT NULL,
        subject VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        error_message TEXT DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY email_notification_event (notification_type, reference_id),
        INDEX email_notification_recipient (recipient),
        INDEX email_notification_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function emailServiceResult(bool $success, string $message, ?int $logId = null): array
{
    return ['success' => $success, 'message' => $message, 'log_id' => $logId];
}

function getMailConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/mail_config.php';
    }

    return $config;
}

function sendEmail(
    string $recipient,
    string $subject,
    string $body,
    array $attachments = [],
    ?string $notificationType = null,
    ?string $referenceId = null
): array {
    global $conn;

    $recipient = trim($recipient);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return emailServiceResult(false, 'The recipient email address is invalid.');
    }

    ensureEmailNotificationsSchema();

    if ($notificationType !== null && $referenceId !== null) {
        $existing = executeQuery(
            'SELECT id, status FROM email_notifications WHERE notification_type = ? AND reference_id = ? LIMIT 1',
            [$notificationType, $referenceId]
        )->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['status'] === 'sent') {
            return emailServiceResult(true, 'This email event was already sent.', (int)$existing['id']);
        }
    }

    $config = getMailConfig();
    $smtpUser = trim((string)($config['smtp_user'] ?? ''));
    $smtpPass = trim((string)($config['smtp_pass'] ?? ''));
    if ($smtpUser === '' || $smtpPass === '') {
        $configError = 'SMTP credentials are not configured. Add SMTP_USERNAME and SMTP_PASSWORD to your .env file.';
        error_log($configError);
        ensureEmailNotificationsSchema();
        executeQuery(
            'INSERT INTO email_notifications (recipient, notification_type, reference_id, subject, status, error_message) VALUES (?, ?, ?, ?, ?, ?)',
            [$recipient, $notificationType ?? 'unclassified', $referenceId, $subject, 'failed', mb_substr($configError, 0, 2000)]
        );
        return emailServiceResult(false, 'Email could not be sent because SMTP is not configured.', (int)$conn->lastInsertId());
    }

    $mail = new PHPMailer(true);
    $logId = null;

    try {
        foreach ($attachments as $attachment) {
            if (is_string($attachment)) {
                if (!is_file($attachment) || !is_readable($attachment)) {
                    throw new RuntimeException('An email attachment could not be read.');
                }
                $mail->addAttachment($attachment);
                continue;
            }

            if (is_array($attachment) && !empty($attachment['path'])) {
                $path = (string)$attachment['path'];
                if (!is_file($path) || !is_readable($path)) {
                    throw new RuntimeException('An email attachment could not be read.');
                }
                $mail->addAttachment($path, (string)($attachment['name'] ?? ''));
            }
        }

        $mail->isSMTP();
        $mail->Host = (string)($config['smtp_host'] ?? 'smtp.gmail.com');
        $mail->Port = (int)($config['smtp_port'] ?? 587);
        $mail->SMTPAuth = (bool)($config['smtp_auth'] ?? true);
        $mail->Username = (string)($config['smtp_user'] ?? '');
        $mail->Password = (string)($config['smtp_pass'] ?? '');
        $mail->Timeout = (int)($config['smtp_timeout'] ?? 20);
        $mail->SMTPSecure = ((string)($config['smtp_secure'] ?? 'tls') === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string)($config['from_email'] ?? $mail->Username), (string)($config['from_name'] ?? 'Lending System'));
        $mail->addAddress($recipient);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = renderEmailTemplate($subject, $body);
        $mail->AltBody = trim(strip_tags($body));

        if ($notificationType !== null && $referenceId !== null) {
            $existing = executeQuery(
                'SELECT id FROM email_notifications WHERE notification_type = ? AND reference_id = ? LIMIT 1',
                [$notificationType, $referenceId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $logId = (int)$existing['id'];
                executeQuery('UPDATE email_notifications SET recipient = ?, subject = ?, status = ?, error_message = NULL WHERE id = ?', [$recipient, $subject, 'pending', $logId]);
            } else {
                executeQuery('INSERT INTO email_notifications (recipient, notification_type, reference_id, subject, status) VALUES (?, ?, ?, ?, ?)', [$recipient, $notificationType, $referenceId, $subject, 'pending']);
                $logId = (int)$conn->lastInsertId();
            }
        } else {
            executeQuery('INSERT INTO email_notifications (recipient, notification_type, reference_id, subject, status) VALUES (?, ?, ?, ?, ?)', [$recipient, $notificationType ?? 'unclassified', $referenceId, $subject, 'pending']);
            $logId = (int)$conn->lastInsertId();
        }

        $mail->send();

        if ($logId) {
            executeQuery('UPDATE email_notifications SET status = ?, sent_at = NOW(), error_message = NULL WHERE id = ?', ['sent', $logId]);
        }
        return emailServiceResult(true, 'Email sent successfully.', $logId);
    } catch (Throwable $exception) {
        $safeError = $exception instanceof Exception
            ? 'SMTP email delivery failed: ' . $exception->getMessage()
            : 'Email delivery failed: ' . $exception->getMessage();
        error_log($safeError);

        if ($logId) {
            executeQuery('UPDATE email_notifications SET status = ?, error_message = ? WHERE id = ?', ['failed', mb_substr($safeError, 0, 2000), $logId]);
        } else {
            executeQuery('INSERT INTO email_notifications (recipient, notification_type, reference_id, subject, status, error_message) VALUES (?, ?, ?, ?, ?, ?)', [$recipient, $notificationType ?? 'unclassified', $referenceId, $subject, 'failed', mb_substr($safeError, 0, 2000)]);
        }

        return emailServiceResult(false, 'Email could not be sent. Check the email log and server error log.', $logId);
    }
}
