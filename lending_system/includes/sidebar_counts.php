<?php
/**
 * Action counts shown on the sidebar. Each key matches a nav href.
 */

function lendingSidebarScalar(string $sql, array $params = []): int
{
    if (!function_exists('executeQuery')) {
        return 0;
    }

    try {
        $stmt = executeQuery($sql, $params);
        if (!$stmt) {
            return 0;
        }

        return max(0, (int)$stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('Sidebar count failed: ' . $e->getMessage());
        return 0;
    }
}

function lendingSidebarCountHints(): array
{
    return [
        'notifications_lending.php' => 'unread',
        'loans_lending.php' => 'overdue',
        'loan_applications_lending.php' => 'awaiting review',
        'borrower_approval_lending.php' => 'awaiting approval',
        'loan_release_lending.php' => 'ready to release',
        'contact_inquiries_lending.php' => 'new',
        'borrower_loan_agreement_lending.php' => 'to sign',
        'borrower_loan_status_lending.php' => 'needing documents',
    ];
}

function lendingSidebarActionCounts(): array
{
    if (!function_exists('executeQuery')) {
        require_once __DIR__ . '/db_lending.php';
    }

    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    $userId = (int)($user['user_id'] ?? 0);
    $counts = [];

    if ($userId > 0 && function_exists('getUnreadNotificationCount')) {
        $counts['notifications_lending.php'] = (int)getUnreadNotificationCount($userId);
    }

    if (function_exists('isBorrower') && isBorrower()) {
        $counts['borrower_loan_agreement_lending.php'] = lendingSidebarScalar(
            "SELECT COUNT(*) FROM loan_applications la
             WHERE la.user_id = ?
               AND (
                    SELECT ag.agreement_status
                    FROM loan_agreements ag
                    WHERE ag.application_id = la.id
                    ORDER BY ag.id DESC
                    LIMIT 1
               ) = 'Generated'",
            [$userId]
        );
        $counts['borrower_loan_status_lending.php'] = lendingSidebarScalar(
            "SELECT COUNT(*) FROM loan_applications WHERE user_id = ? AND status = 'Documents Incomplete'",
            [$userId]
        );

        return $counts;
    }

    $overdueSql = "SELECT COUNT(*) FROM loans l INNER JOIN clients c ON c.client_id = l.client_id WHERE l.status = 'Overdue'";
    $overdueParams = [];
    if (function_exists('isCollector') && isCollector() && $userId > 0) {
        $overdueSql .= ' AND (c.collector_id = ? OR l.collector_id = ?)';
        $overdueParams = [$userId, $userId];
    }
    $counts['loans_lending.php'] = lendingSidebarScalar($overdueSql, $overdueParams);

    if (function_exists('isAdmin') && isAdmin()) {
        $counts['loan_applications_lending.php'] = lendingSidebarScalar(
            "SELECT COUNT(*) FROM loan_applications WHERE status IN ('Pending Review', 'Under Review', 'Documents Incomplete')"
        );
        $counts['borrower_approval_lending.php'] = lendingSidebarScalar(
            "SELECT COUNT(*) FROM borrower_applications WHERE status IS NULL OR status NOT IN ('Approved', 'Rejected')"
        );
        $counts['loan_release_lending.php'] = lendingSidebarScalar(
            "SELECT COUNT(*)
             FROM loan_applications la
             INNER JOIN users u ON u.user_id = la.user_id
             WHERE la.status IN ('Approved', 'Agreement Accepted', 'Ready for Release')
               AND u.status = 'Active'
               AND NOT EXISTS (SELECT 1 FROM loan_releases lr WHERE lr.application_id = la.id)"
        );

        require_once __DIR__ . '/contact_inquiry_helpers.php';
        if (function_exists('ensureContactInquiriesSchema')) {
            try {
                ensureContactInquiriesSchema();
            } catch (Throwable $e) {
                error_log('Sidebar inquiry schema skipped: ' . $e->getMessage());
            }
        }
        $counts['contact_inquiries_lending.php'] = lendingSidebarScalar(
            "SELECT COUNT(*) FROM contact_inquiries WHERE status = 'new'"
        );
    }

    return $counts;
}
