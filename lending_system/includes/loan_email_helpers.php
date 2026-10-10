<?php

require_once __DIR__ . '/env_lending.php';
require_once __DIR__ . '/email_service.php';

function buildLendingPortalUrl(string $page): string
{
    $configured = rtrim((string)lendingEnv('APP_URL', ''), '/');
    if ($configured !== '') {
        return $configured . '/' . ltrim($page, '/');
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = lendingRequestIsHttps() ? 'https' : 'http';
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim($dir, '/') . '/' . ltrim($page, '/');
    }

    return '';
}

function resolveBorrowerEmailFromApplication(array $application): string
{
    $email = trim((string)($application['email_address'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    $userId = (int)($application['user_id'] ?? 0);
    if ($userId > 0) {
        $row = executeQuery('SELECT email FROM users WHERE user_id = ? LIMIT 1', [$userId])->fetch(PDO::FETCH_ASSOC);
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    }

    return '';
}

function resolveBorrowerDisplayName(array $application, string $fallback = 'Borrower'): string
{
    $name = trim((string)($application['borrower_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $first = trim((string)($application['first_name'] ?? ''));
    $last = trim((string)($application['last_name'] ?? ''));
    $combined = trim($first . ' ' . $last);
    return $combined !== '' ? $combined : $fallback;
}

function sendLoanLifecycleEmail(
    string $recipient,
    string $notificationType,
    string $referenceId,
    string $subject,
    string $htmlBody
): array {
    if ($recipient === '') {
        return emailServiceResult(false, 'Borrower email address is missing.');
    }

    return sendEmail($recipient, $subject, $htmlBody, [], $notificationType, $referenceId);
}

function sendPasswordOtpEmail(array $user, string $otp): array
{
    $recipient = trim((string)($user['email'] ?? ''));
    if ($recipient === '') {
        return emailServiceResult(false, 'No email address is on file for this account.');
    }

    $name = htmlspecialchars(trim((string)($user['full_name'] ?? 'Borrower')));
    $code = htmlspecialchars($otp);
    $body = '<p>Hello ' . $name . ',</p>'
        . '<p>Use this one-time code to reset your password. It expires in 10 minutes.</p>'
        . '<p style="margin:18px 0;font-size:32px;letter-spacing:8px;font-weight:700;color:#1565c0;">' . $code . '</p>'
        . '<p>Enter this code on the forgot-password page, then choose a new password.</p>'
        . '<p>If you did not request this, you can ignore this email.</p>';

    return sendLoanLifecycleEmail(
        $recipient,
        'password_otp',
        'user-' . (int)($user['user_id'] ?? 0) . '-' . time(),
        'Your password reset code',
        $body
    );
}

function sendBorrowerAccountApprovedEmail(array $borrower): array
{
    $recipient = trim((string)($borrower['email'] ?? ''));
    $borrowerId = (int)($borrower['id'] ?? 0);
    $name = htmlspecialchars(trim(($borrower['first_name'] ?? '') . ' ' . ($borrower['last_name'] ?? '')));
    $loginUrl = buildLendingPortalUrl('borrower_login_lending.php');
    $body = '<p>Dear ' . $name . ',</p>'
        . '<p>Good news &mdash; your borrower account has been <strong>approved</strong>. You can now log in and apply for loans.</p>'
        . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($loginUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">Sign in to your account</a></p>'
        . '<p>If you did not request this, please contact our office.</p>';

    return sendLoanLifecycleEmail(
        $recipient,
        'borrower_account_approved',
        'borrower-app-' . $borrowerId,
        'Your borrower account has been approved',
        $body
    );
}

function sendBorrowerAccountRejectedEmail(array $borrower, string $reason): array
{
    $recipient = trim((string)($borrower['email'] ?? ''));
    $borrowerId = (int)($borrower['id'] ?? 0);
    $name = htmlspecialchars(trim(($borrower['first_name'] ?? '') . ' ' . ($borrower['last_name'] ?? '')));
    $body = '<p>Dear ' . $name . ',</p>'
        . '<p>We reviewed your borrower registration and could not approve it at this time.</p>'
        . '<p><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</p>'
        . '<p>Contact our office if you have questions or would like to submit updated information.</p>';

    return sendLoanLifecycleEmail(
        $recipient,
        'borrower_account_rejected',
        'borrower-app-' . $borrowerId,
        'Borrower account registration update',
        $body
    );
}

function sendLoanApplicationSubmittedEmail(array $application, int $applicationId): array
{
    $recipient = resolveBorrowerEmailFromApplication($application);
    $name = htmlspecialchars(resolveBorrowerDisplayName($application));
    $statusUrl = buildLendingPortalUrl('borrower_loan_status_lending.php');
    $body = '<p>Dear ' . $name . ',</p>'
        . '<p>We received your loan application and it is now <strong>pending review</strong>.</p>'
        . '<p>Reference: Application #' . $applicationId . '</p>'
        . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($statusUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">View application status</a></p>'
        . '<p>We will email you when there is an update.</p>';

    return sendLoanLifecycleEmail(
        $recipient,
        'loan_application_submitted',
        'application-' . $applicationId,
        'Loan application received',
        $body
    );
}

function sendLoanApplicationDecisionEmail(array $application, int $applicationId, string $action, string $detail = ''): array
{
    $recipient = resolveBorrowerEmailFromApplication($application);
    $name = htmlspecialchars(resolveBorrowerDisplayName($application));
    $statusUrl = buildLendingPortalUrl('borrower_loan_status_lending.php');
    $agreementUrl = buildLendingPortalUrl('borrower_loan_agreement_lending.php');

    switch ($action) {
        case 'approve':
            $subject = 'Your loan application was approved';
            $type = 'loan_application_approved';
            $body = '<p>Dear ' . $name . ',</p>'
                . '<p>Your loan application has been <strong>approved</strong>. A loan agreement is ready for your review and acceptance.</p>'
                . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($agreementUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">Review loan agreement</a></p>'
                . '<p>You can also check status anytime from your borrower portal.</p>';
            break;
        case 'reject':
            $subject = 'Your loan application was not approved';
            $type = 'loan_application_rejected';
            $body = '<p>Dear ' . $name . ',</p>'
                . '<p>After review, your loan application was <strong>not approved</strong>.</p>'
                . '<p><strong>Reason:</strong> ' . htmlspecialchars($detail) . '</p>'
                . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($statusUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">View application status</a></p>';
            break;
        case 'request_documents':
        default:
            $subject = 'Additional documents needed for your loan application';
            $type = 'loan_application_documents_requested';
            $body = '<p>Dear ' . $name . ',</p>'
                . '<p>We need additional documents before we can continue reviewing your loan application.</p>'
                . '<p>' . htmlspecialchars($detail !== '' ? $detail : 'Please sign in and upload the requested documents.') . '</p>'
                . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($statusUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">View application status</a></p>';
            break;
    }

    return sendLoanLifecycleEmail(
        $recipient,
        $type,
        'application-' . $applicationId,
        $subject,
        $body
    );
}

function sendLoanAgreementDecisionEmail(array $application, int $applicationId, string $decision, string $detail = ''): array
{
    $recipient = resolveBorrowerEmailFromApplication($application);
    $name = htmlspecialchars(resolveBorrowerDisplayName($application));
    $statusUrl = buildLendingPortalUrl('borrower_loan_status_lending.php');

    if ($decision === 'accept') {
        $subject = 'Loan agreement accepted';
        $type = 'loan_agreement_accepted';
        $body = '<p>Dear ' . $name . ',</p>'
            . '<p>This confirms that you accepted your loan agreement. Our team will proceed with release once all requirements are complete.</p>'
            . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($statusUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">View loan status</a></p>';
    } else {
        $subject = 'Loan agreement declined';
        $type = 'loan_agreement_declined';
        $body = '<p>Dear ' . $name . ',</p>'
            . '<p>We recorded your decision to decline the loan agreement.</p>'
            . '<p><strong>Reason:</strong> ' . htmlspecialchars($detail) . '</p>'
            . '<p>Contact our office if you would like to discuss next steps.</p>';
    }

    return sendLoanLifecycleEmail(
        $recipient,
        $type,
        'application-' . $applicationId,
        $subject,
        $body
    );
}

function sendLoanReleasedEmail(array $application, int $applicationId, string $loanNumber, string $collectorName, string $firstDueDate): array
{
    $recipient = resolveBorrowerEmailFromApplication($application);
    $name = htmlspecialchars(resolveBorrowerDisplayName($application));
    $statusUrl = buildLendingPortalUrl('borrower_my_loan_lending.php');
    $body = '<p>Dear ' . $name . ',</p>'
        . '<p>Congratulations! Your loan has been <strong>released</strong>.</p>'
        . '<ul>'
        . '<li><strong>Loan number:</strong> ' . htmlspecialchars($loanNumber) . '</li>'
        . '<li><strong>Assigned collector:</strong> ' . htmlspecialchars($collectorName) . '</li>'
        . '<li><strong>First payment due:</strong> ' . htmlspecialchars($firstDueDate) . '</li>'
        . '</ul>'
        . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($statusUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">View my loan</a></p>';

    return sendLoanLifecycleEmail(
        $recipient,
        'loan_released',
        'release-app-' . $applicationId,
        'Your loan has been released',
        $body
    );
}

function sendPaymentReceivedEmail(
    string $recipient,
    int $loanId,
    float $amountPaid,
    string $receiptNumber,
    float $totalPayable,
    string $borrowerName = '',
    string $loanNumber = ''
): array {
    $name = htmlspecialchars(trim($borrowerName) !== '' ? trim($borrowerName) : 'Borrower');
    $loanLabel = htmlspecialchars(trim($loanNumber) !== '' ? trim($loanNumber) : ('Loan #' . $loanId));
    $historyUrl = buildLendingPortalUrl('borrower_payment_history_lending.php');

    $body = '<p>Dear ' . $name . ',</p>'
        . '<p>We received your payment of <strong>₱' . number_format($amountPaid, 2) . '</strong> for <strong>' . $loanLabel . '</strong>.</p>'
        . '<p><strong>Receipt number:</strong> ' . htmlspecialchars($receiptNumber) . '</p>'
        . '<p><strong>Total loan payable:</strong> ₱' . number_format($totalPayable, 2) . '</p>'
        . '<p>Thank you for staying current on your loan. You can view your payment history anytime in the borrower portal.</p>'
        . '<p style="margin:18px 0;"><a href="' . htmlspecialchars($historyUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;">View payment history</a></p>';

    return sendLoanLifecycleEmail(
        $recipient,
        'payment_received',
        'payment-' . $loanId . '-' . preg_replace('/[^a-zA-Z0-9-]/', '', $receiptNumber),
        'Payment received — ' . $receiptNumber,
        $body
    );
}

function sendLoanFullyPaidEmail(
    string $recipient,
    int $loanId,
    float $finalPaymentAmount,
    string $receiptNumber,
    float $totalPayable,
    string $borrowerName = '',
    string $loanNumber = ''
): array {
    $name = htmlspecialchars(trim($borrowerName) !== '' ? trim($borrowerName) : 'Borrower');
    $loanLabel = htmlspecialchars(trim($loanNumber) !== '' ? trim($loanNumber) : ('Loan #' . $loanId));
    $myLoanUrl = buildLendingPortalUrl('borrower_my_loan_lending.php');
    $historyUrl = buildLendingPortalUrl('borrower_payment_history_lending.php');

    $body = '<p>Dear ' . $name . ',</p>'
        . '<p><strong>Congratulations!</strong> Your loan account is now <strong>fully paid</strong>.</p>'
        . '<p>Your final payment of <strong>₱' . number_format($finalPaymentAmount, 2) . '</strong> has been recorded, and your outstanding balance for <strong>' . $loanLabel . '</strong> is <strong>₱0.00</strong>.</p>'
        . '<ul>'
        . '<li><strong>Receipt number:</strong> ' . htmlspecialchars($receiptNumber) . '</li>'
        . '<li><strong>Total amount paid on this loan:</strong> ₱' . number_format($totalPayable, 2) . '</li>'
        . '<li><strong>Account status:</strong> Fully paid / closed</li>'
        . '</ul>'
        . '<p>Thank you for completing your payments on time. We appreciate your trust in RJ &amp; RR Finance Services.</p>'
        . '<p style="margin:18px 0;">'
        . '<a href="' . htmlspecialchars($myLoanUrl) . '" style="display:inline-block;padding:10px 16px;background:#1565c0;color:#fff;border-radius:6px;text-decoration:none;margin-right:8px;">View my loan</a>'
        . '<a href="' . htmlspecialchars($historyUrl) . '" style="display:inline-block;padding:10px 16px;background:#2e7d32;color:#fff;border-radius:6px;text-decoration:none;">Payment history</a>'
        . '</p>'
        . '<p>If you need a payment summary or certificate of full payment, please contact our office.</p>';

    return sendLoanLifecycleEmail(
        $recipient,
        'loan_fully_paid',
        'loan-paid-' . $loanId . '-' . preg_replace('/[^a-zA-Z0-9-]/', '', $receiptNumber),
        'Congratulations — your loan is fully paid (' . (trim($loanNumber) !== '' ? trim($loanNumber) : ('Loan #' . $loanId)) . ')',
        $body
    );
}

function getRecentEmailNotifications(int $limit = 25): array
{
    ensureEmailNotificationsSchema();
    $limit = max(1, min(100, $limit));
    $stmt = executeQuery(
        'SELECT id, recipient, notification_type, reference_id, subject, status, error_message, sent_at, created_at
         FROM email_notifications
         ORDER BY id DESC
         LIMIT ' . $limit
    );
    if (!$stmt) {
        return [];
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
