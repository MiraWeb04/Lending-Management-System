<?php
/**
 * Public contact inquiries — storage, admin replies, email to senders.
 */

require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/invoice_helpers.php';

function ensureContactInquiriesSchema(): void
{
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS contact_inquiries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_name VARCHAR(150) NOT NULL,
        sender_email VARCHAR(254) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX contact_inquiry_status (status),
        INDEX contact_inquiry_created (created_at),
        INDEX contact_inquiry_email (sender_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->exec("CREATE TABLE IF NOT EXISTS contact_inquiry_replies (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inquiry_id INT UNSIGNED NOT NULL,
        admin_user_id INT UNSIGNED NOT NULL,
        reply_body TEXT NOT NULL,
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        email_error TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX contact_reply_inquiry (inquiry_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function contactInquiryStatusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'new' => 'New',
        'read' => 'Open',
        'replied' => 'Replied',
        'closed' => 'Closed',
        default => ucfirst($status),
    };
}

function createContactInquiry(string $senderName, string $senderEmail, string $message, ?string $ipAddress = null): array
{
    ensureContactInquiriesSchema();

    $senderName = trim($senderName);
    $senderEmail = trim($senderEmail);
    $message = trim($message);

    if ($senderName === '' || mb_strlen($senderName) > 150) {
        return ['success' => false, 'message' => 'Please enter your name (max 150 characters).'];
    }
    if (!filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }
    if ($message === '' || mb_strlen($message) < 10) {
        return ['success' => false, 'message' => 'Please enter a message of at least 10 characters.'];
    }
    if (mb_strlen($message) > 5000) {
        return ['success' => false, 'message' => 'Your message is too long. Please shorten it and try again.'];
    }

    executeQuery(
        'INSERT INTO contact_inquiries (sender_name, sender_email, message, status, ip_address) VALUES (?, ?, ?, ?, ?)',
        [$senderName, $senderEmail, $message, 'new', $ipAddress]
    );

    $inquiryId = (int)$GLOBALS['conn']->lastInsertId();

    $preview = $message;
    if (mb_strlen($preview) > 200) {
        $preview = mb_substr($preview, 0, 197) . '...';
    }

    notifyAdmins(
        'New contact inquiry',
        'From ' . $senderName . ' (' . $senderEmail . '): ' . $preview
    );

    return ['success' => true, 'message' => 'Thank you! Your inquiry was sent. Our team will reply to your email soon.', 'id' => $inquiryId];
}

function countContactInquiriesByStatus(?string $status = null): int
{
    ensureContactInquiriesSchema();

    if ($status === null || $status === '') {
        $stmt = executeQuery('SELECT COUNT(*) FROM contact_inquiries');
    } else {
        $stmt = executeQuery('SELECT COUNT(*) FROM contact_inquiries WHERE status = ?', [$status]);
    }

    return (int)($stmt->fetchColumn() ?? 0);
}

function fetchContactInquiries(?string $statusFilter = null, int $limit = 100): array
{
    ensureContactInquiriesSchema();
    $limit = max(1, min(500, $limit));

    $sql = 'SELECT i.*, (SELECT COUNT(*) FROM contact_inquiry_replies r WHERE r.inquiry_id = i.id) AS reply_count
            FROM contact_inquiries i';
    $params = [];

    if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
        $sql .= ' WHERE i.status = ?';
        $params[] = $statusFilter;
    }

    $sql .= ' ORDER BY FIELD(i.status, \'new\', \'read\', \'replied\', \'closed\'), i.created_at DESC LIMIT ' . $limit;

    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function getContactInquiryById(int $inquiryId): ?array
{
    ensureContactInquiriesSchema();
    $stmt = executeQuery('SELECT * FROM contact_inquiries WHERE id = ? LIMIT 1', [$inquiryId]);
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    return $row ?: null;
}

function fetchContactInquiryReplies(int $inquiryId): array
{
    ensureContactInquiriesSchema();
    $stmt = executeQuery(
        'SELECT r.*, u.full_name AS admin_name FROM contact_inquiry_replies r
         LEFT JOIN users u ON u.user_id = r.admin_user_id
         WHERE r.inquiry_id = ? ORDER BY r.created_at ASC',
        [$inquiryId]
    );

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function markContactInquiryRead(int $inquiryId): void
{
    ensureContactInquiriesSchema();
    executeQuery(
        "UPDATE contact_inquiries SET status = 'read' WHERE id = ? AND status = 'new'",
        [$inquiryId]
    );
}

function replyToContactInquiry(int $inquiryId, int $adminUserId, string $replyBody): array
{
    ensureContactInquiriesSchema();

    $inquiry = getContactInquiryById($inquiryId);
    if (!$inquiry) {
        return ['success' => false, 'message' => 'Inquiry not found.'];
    }

    $replyBody = trim($replyBody);
    if ($replyBody === '' || mb_strlen($replyBody) < 5) {
        return ['success' => false, 'message' => 'Please enter a reply of at least 5 characters.'];
    }
    if (mb_strlen($replyBody) > 8000) {
        return ['success' => false, 'message' => 'Reply is too long.'];
    }

    executeQuery(
        'INSERT INTO contact_inquiry_replies (inquiry_id, admin_user_id, reply_body) VALUES (?, ?, ?)',
        [$inquiryId, $adminUserId, $replyBody]
    );

    $replyId = (int)$GLOBALS['conn']->lastInsertId();

    $senderName = htmlspecialchars((string)$inquiry['sender_name'], ENT_QUOTES, 'UTF-8');
    $originalMessage = nl2br(htmlspecialchars((string)$inquiry['message'], ENT_QUOTES, 'UTF-8'));
    $replyHtml = nl2br(htmlspecialchars($replyBody, ENT_QUOTES, 'UTF-8'));

    $brand = invoiceBrand();
    $brandName = htmlspecialchars($brand['legal_name'], ENT_QUOTES, 'UTF-8');
    $emailBody = '<p>Hello ' . $senderName . ',</p>'
        . '<p>Thank you for contacting <strong>' . $brandName . '</strong>. Here is our response to your inquiry:</p>'
        . '<div style="background:#f3f6fb;border-radius:8px;padding:16px;margin:16px 0;">' . $replyHtml . '</div>'
        . '<p style="color:#6b7280;font-size:14px;"><strong>Your original message:</strong></p>'
        . '<div style="background:#fafafa;border-left:4px solid #1565c0;padding:12px 16px;margin:8px 0 16px;color:#4b5563;">' . $originalMessage . '</div>'
        . '<p>If you have follow-up questions, reply to this email or use the contact form on our website.</p>'
        . '<p style="margin-top:24px;">— ' . $brandName . '<br>' . htmlspecialchars($brand['address'], ENT_QUOTES, 'UTF-8') . '<br>' . htmlspecialchars($brand['phone'], ENT_QUOTES, 'UTF-8') . '</p>';

    $subject = 'Re: Your inquiry — ' . $brand['legal_name'];
    $emailResult = sendEmail(
        (string)$inquiry['sender_email'],
        $subject,
        $emailBody,
        [],
        'contact_inquiry_reply',
        'contact_inquiry_reply_' . $replyId
    );

    $emailSent = !empty($emailResult['success']);
    $emailError = $emailSent ? null : (string)($emailResult['message'] ?? 'Email failed');

    executeQuery(
        'UPDATE contact_inquiry_replies SET email_sent = ?, email_error = ? WHERE id = ?',
        [$emailSent ? 1 : 0, $emailError, $replyId]
    );

    executeQuery(
        "UPDATE contact_inquiries SET status = 'replied' WHERE id = ?",
        [$inquiryId]
    );

    if (!$emailSent) {
        return [
            'success' => true,
            'message' => 'Reply saved, but the email could not be sent: ' . $emailError . ' Check SMTP settings and try resending from the inquiry page.',
            'reply_id' => $replyId,
            'email_sent' => false,
        ];
    }

    return [
        'success' => true,
        'message' => 'Reply sent to ' . $inquiry['sender_email'] . '.',
        'reply_id' => $replyId,
        'email_sent' => true,
    ];
}

function updateContactInquiryStatus(int $inquiryId, string $status): bool
{
    ensureContactInquiriesSchema();
    $allowed = ['new', 'read', 'replied', 'closed'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    executeQuery('UPDATE contact_inquiries SET status = ? WHERE id = ?', [$status, $inquiryId]);
    return true;
}

/**
 * Permanently remove an inquiry and all admin replies.
 */
function deleteContactInquiry(int $inquiryId): bool
{
    ensureContactInquiriesSchema();

    if ($inquiryId <= 0 || !getContactInquiryById($inquiryId)) {
        return false;
    }

    executeQuery('DELETE FROM contact_inquiry_replies WHERE inquiry_id = ?', [$inquiryId]);
    $stmt = executeQuery('DELETE FROM contact_inquiries WHERE id = ?', [$inquiryId]);

    return $stmt !== false && $stmt->rowCount() > 0;
}

function resendContactInquiryReplyEmail(int $replyId): array
{
    ensureContactInquiriesSchema();

    $stmt = executeQuery(
        'SELECT r.*, i.sender_email, i.sender_name, i.message FROM contact_inquiry_replies r
         JOIN contact_inquiries i ON i.id = r.inquiry_id WHERE r.id = ? LIMIT 1',
        [$replyId]
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    if (!$row) {
        return ['success' => false, 'message' => 'Reply not found.'];
    }

    $senderName = htmlspecialchars((string)$row['sender_name'], ENT_QUOTES, 'UTF-8');
    $originalMessage = nl2br(htmlspecialchars((string)$row['message'], ENT_QUOTES, 'UTF-8'));
    $replyHtml = nl2br(htmlspecialchars((string)$row['reply_body'], ENT_QUOTES, 'UTF-8'));

    $emailBody = '<p>Hello ' . $senderName . ',</p>'
        . '<p>Thank you for contacting <strong>RJ &amp; RR Finance Services</strong>. Here is our response to your inquiry:</p>'
        . '<div style="background:#f3f6fb;border-radius:8px;padding:16px;margin:16px 0;">' . $replyHtml . '</div>'
        . '<p style="color:#6b7280;font-size:14px;"><strong>Your original message:</strong></p>'
        . '<div style="background:#fafafa;border-left:4px solid #1565c0;padding:12px 16px;margin:8px 0 16px;color:#4b5563;">' . $originalMessage . '</div>';

    $subject = 'Re: Your inquiry — RJ & RR Finance Services';
    $emailResult = sendEmail(
        (string)$row['sender_email'],
        $subject,
        $emailBody,
        [],
        'contact_inquiry_reply_resend',
        'contact_inquiry_reply_resend_' . $replyId . '_' . time()
    );

    $emailSent = !empty($emailResult['success']);
    executeQuery(
        'UPDATE contact_inquiry_replies SET email_sent = ?, email_error = ? WHERE id = ?',
        [$emailSent ? 1 : 0, $emailSent ? null : (string)($emailResult['message'] ?? ''), $replyId]
    );

    return $emailResult;
}
