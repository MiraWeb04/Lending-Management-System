<?php
/**
 * CLI: Remove all borrowers, clients, and loan-related data for a clean portfolio refresh.
 * Preserves Admin/Collector users and expenses.
 *
 * Usage:
 *   php tools/refresh_borrowers_and_loans_cli.php --confirm
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line only.\n");
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "This permanently deletes ALL clients, borrower accounts, loans, payments, and applications.\n");
    fwrite(STDERR, "Re-run with: php tools/refresh_borrowers_and_loans_cli.php --confirm\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_lending.php';

function tableExists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function countRows(PDO $conn, string $table): int
{
    if (!tableExists($conn, $table)) {
        return 0;
    }
    return (int)$conn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
}

function deleteAll(PDO $conn, string $table): int
{
    if (!tableExists($conn, $table)) {
        return 0;
    }
    $conn->exec("DELETE FROM `{$table}`");
    return (int)$conn->query("SELECT ROW_COUNT()")->fetchColumn();
}

/** @var PDO $conn */
global $conn;

$borrowerUserIds = $conn->query("SELECT user_id FROM users WHERE role = 'Borrower'")
    ->fetchAll(PDO::FETCH_COLUMN);
$borrowerUserIds = array_map('intval', $borrowerUserIds);

echo "=== Lending system refresh (borrowers + loans) ===\n\n";
echo "Before:\n";
$previewTables = [
    'clients', 'users', 'loans', 'payments', 'loan_releases', 'loan_payment_schedules',
    'loan_applications', 'loan_agreements', 'borrower_applications', 'notifications',
];
foreach ($previewTables as $t) {
    if (tableExists($conn, $t)) {
        echo sprintf("  %-28s %d\n", $t . ':', countRows($conn, $t));
    }
}
echo sprintf("  %-28s %d\n", 'borrower users:', count($borrowerUserIds));

try {
    $conn->beginTransaction();
    $conn->exec('SET FOREIGN_KEY_CHECKS=0');

    $steps = [
        'payments',
        'loan_payment_schedules',
        'loans',
        'loan_releases',
        'loan_application_documents',
        'loan_application_reviews',
        'loan_agreements',
        'loan_applications',
        'borrower_applications',
        'loan_release_notifications',
        'email_notifications',
        'clients',
    ];

    $deleted = [];
    foreach ($steps as $table) {
        if (!tableExists($conn, $table)) {
            continue;
        }
        $before = countRows($conn, $table);
        deleteAll($conn, $table);
        $deleted[$table] = $before;
    }

    if (!empty($borrowerUserIds)) {
        $placeholders = implode(',', array_fill(0, count($borrowerUserIds), '?'));
        $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id IN ($placeholders)");
        $stmt->execute($borrowerUserIds);
        $deleted['notifications (borrowers)'] = $stmt->rowCount();

        if (tableExists($conn, 'password_resets')) {
            $stmt = $conn->prepare("DELETE FROM password_resets WHERE user_id IN ($placeholders)");
            $stmt->execute($borrowerUserIds);
            $deleted['password_resets (borrowers)'] = $stmt->rowCount();
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE user_id IN ($placeholders)");
        $stmt->execute($borrowerUserIds);
        $deleted['users (borrowers)'] = $stmt->rowCount();
    } else {
        $deleted['users (borrowers)'] = 0;
    }

    $conn->exec('SET FOREIGN_KEY_CHECKS=1');
    $conn->commit();

    echo "\nDeleted:\n";
    foreach ($deleted as $label => $count) {
        echo sprintf("  %-32s %d row(s)\n", $label . ':', $count);
    }

    echo "\nAfter:\n";
    foreach ($previewTables as $t) {
        if (tableExists($conn, $t)) {
            echo sprintf("  %-28s %d\n", $t . ':', countRows($conn, $t));
        }
    }
    $remainingBorrowers = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role = 'Borrower'")->fetchColumn();
    echo sprintf("  %-28s %d\n", 'borrower users:', $remainingBorrowers);

    echo "\nRefresh complete. Admin/Collector accounts and expenses were kept.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $conn->exec('SET FOREIGN_KEY_CHECKS=1');
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
