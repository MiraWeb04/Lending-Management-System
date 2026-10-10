<?php
/**
 * Delete a single loan (and related release, schedules, payments, application) by reference.
 *
 * Usage:
 *   php tools/delete_loan_by_reference_cli.php RJRR-20260925-000001 --confirm
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
$reference = trim((string)($argv[1] ?? ''));
$confirm = in_array('--confirm', $argv, true);

if ($reference === '' || str_starts_with($reference, '--')) {
    fwrite(STDERR, "Usage: php tools/delete_loan_by_reference_cli.php <reference> --confirm\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_lending.php';

/** @var PDO $conn */
global $conn;

function findLoanContext(PDO $conn, string $reference): ?array
{
    $like = '%' . $reference . '%';

    $queries = [
        'loans.loan_number' => 'SELECT l.*, r.id AS release_row_id, r.application_id, r.loan_number AS release_loan_number FROM loans l LEFT JOIN loan_releases r ON r.id = l.release_id WHERE l.loan_number = ? LIMIT 1',
        'loan_releases.loan_number' => 'SELECT l.*, r.id AS release_row_id, r.application_id, r.loan_number AS release_loan_number FROM loan_releases r LEFT JOIN loans l ON l.release_id = r.id WHERE r.loan_number = ? LIMIT 1',
        'payments.receipt_number' => 'SELECT l.*, r.id AS release_row_id, r.application_id, r.loan_number AS release_loan_number, p.payment_id, p.receipt_number FROM payments p INNER JOIN loans l ON l.loan_id = p.loan_id LEFT JOIN loan_releases r ON r.id = l.release_id WHERE p.receipt_number = ? LIMIT 1',
        'clients.client_id' => 'SELECT l.*, r.id AS release_row_id, r.application_id, r.loan_number AS release_loan_number, c.client_id FROM clients c INNER JOIN loans l ON l.client_id = c.client_id LEFT JOIN loan_releases r ON r.id = l.release_id WHERE c.client_id = ? ORDER BY l.loan_id DESC LIMIT 1',
    ];

    foreach ($queries as $label => $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->execute([$reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['loan_id'])) {
            $row['_matched_via'] = $label;
            return $row;
        }
    }

    $stmt = $conn->prepare(
        'SELECT l.*, r.id AS release_row_id, r.application_id, r.loan_number AS release_loan_number
         FROM loans l
         LEFT JOIN loan_releases r ON r.id = l.release_id
         WHERE l.loan_number LIKE ? OR r.loan_number LIKE ?
         ORDER BY l.loan_id DESC
         LIMIT 1'
    );
    $stmt->execute([$like, $like]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['loan_id'])) {
        $row['_matched_via'] = 'partial loan_number';
        return $row;
    }

    return null;
}

$context = findLoanContext($conn, $reference);

if (!$context) {
    echo "No loan found for reference: {$reference}\n";
    echo "Searched: loans.loan_number, loan_releases.loan_number, payments.receipt_number, clients.client_id\n";
    exit(1);
}

$loanId = (int)$context['loan_id'];
$releaseId = (int)($context['release_row_id'] ?? $context['release_id'] ?? 0);
$applicationId = (int)($context['application_id'] ?? 0);
$clientId = (int)($context['client_id'] ?? 0);

echo "Matched via: {$context['_matched_via']}\n";
echo "loan_id={$loanId} client_id={$clientId} release_id={$releaseId} application_id={$applicationId}\n";
echo "loan_number=" . ($context['loan_number'] ?? '') . " release_loan_number=" . ($context['release_loan_number'] ?? '') . "\n";

$payStmt = $conn->prepare('SELECT COUNT(*) FROM payments WHERE loan_id = ?');
$payStmt->execute([$loanId]);
$paymentCount = (int)$payStmt->fetchColumn();
echo "payments to delete: {$paymentCount}\n";

if (!$confirm) {
    echo "\nRe-run with --confirm to permanently delete this loan and related records.\n";
    exit(0);
}

try {
    $conn->beginTransaction();

    $conn->prepare('DELETE FROM payments WHERE loan_id = ?')->execute([$loanId]);

    if ($releaseId > 0) {
        $conn->prepare('DELETE FROM loan_payment_schedules WHERE release_id = ?')->execute([$releaseId]);
    }

    $conn->prepare('DELETE FROM loans WHERE loan_id = ?')->execute([$loanId]);

    if ($releaseId > 0) {
        $conn->prepare('DELETE FROM loan_releases WHERE id = ?')->execute([$releaseId]);
    }

    if ($applicationId > 0) {
        $conn->prepare('DELETE FROM loan_application_documents WHERE application_id = ?')->execute([$applicationId]);
        $conn->prepare('DELETE FROM loan_application_reviews WHERE application_id = ?')->execute([$applicationId]);
        $conn->prepare('DELETE FROM loan_agreements WHERE application_id = ?')->execute([$applicationId]);
        $conn->prepare('DELETE FROM loan_applications WHERE id = ?')->execute([$applicationId]);
    }

    $remainingLoansStmt = $conn->prepare('SELECT COUNT(*) FROM loans WHERE client_id = ?');
    $remainingLoansStmt->execute([$clientId]);
    $remainingLoans = (int)$remainingLoansStmt->fetchColumn();

    if ($clientId > 0 && $remainingLoans === 0) {
        $clientRow = $conn->prepare('SELECT user_id FROM clients WHERE client_id = ? LIMIT 1');
        $clientRow->execute([$clientId]);
        $userId = (int)($clientRow->fetchColumn() ?: 0);

        $conn->prepare('DELETE FROM clients WHERE client_id = ?')->execute([$clientId]);

        if ($userId > 0) {
            $appCountStmt = $conn->prepare('SELECT COUNT(*) FROM loan_applications WHERE user_id = ?');
            $appCountStmt->execute([$userId]);
            if ((int)$appCountStmt->fetchColumn() === 0) {
                $conn->prepare('DELETE FROM borrower_applications WHERE user_id = ?')->execute([$userId]);
                $conn->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([$userId]);
                $conn->prepare('DELETE FROM loan_release_notifications WHERE user_id = ?')->execute([$userId]);
                $conn->prepare('DELETE FROM users WHERE user_id = ? AND role = ?')->execute([$userId, 'Borrower']);
            }
        }
        echo "Client record removed (no remaining loans).\n";
    }

    $conn->commit();
    echo "Deleted loan {$reference} and related data successfully.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
