<?php

/**
 * @deprecated Use sendEmail() from email_service.php instead.
 */
function sendMailSMTP(string $to, string $subject, string $body, ?string $fromEmail = null, ?string $fromName = null): bool
{
    require_once __DIR__ . '/db_lending.php';
    require_once __DIR__ . '/email_service.php';

    unset($fromEmail, $fromName);

    $result = sendEmail($to, $subject, $body);
    return $result['success'];
}
