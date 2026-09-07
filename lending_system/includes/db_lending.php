<?php
// db_lending.php
// Database connection using PDO + helper functions

$host = "localhost";
$dbname = "lending_management";
$username = "root";   // change if you have a MySQL user
$password = "";       // change if your MySQL has a password

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
require_once __DIR__ . '/update_status_lending.php';
/**
 * Execute a prepared query
 */
function executeQuery($sql, $params = []) {
    global $conn;
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log('Database query failed: ' . $e->getMessage() . ' SQL: ' . $sql);
        return false;
    }
}

function ensureLoanAgreementsSchema() {
    global $conn;
    $conn->exec("CREATE TABLE IF NOT EXISTS loan_agreements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        application_id INT UNSIGNED NOT NULL,
        borrower_user_id INT DEFAULT NULL,
        agreement_status VARCHAR(50) DEFAULT 'Pending',
        agreement_text TEXT DEFAULT NULL,
        signed_by VARCHAR(150) DEFAULT NULL,
        signed_at DATETIME DEFAULT NULL,
        decline_reason TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $existingColumns = [];
    try {
        $columnStmt = $conn->query('SHOW COLUMNS FROM loan_agreements');
        foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $existingColumns[$column['Field']] = true;
        }
    } catch (PDOException $e) {
        return;
    }

    if (!isset($existingColumns['agreement_text'])) {
        $conn->exec('ALTER TABLE loan_agreements ADD COLUMN agreement_text TEXT DEFAULT NULL AFTER agreement_status');
    }
    if (!isset($existingColumns['signed_by'])) {
        $conn->exec('ALTER TABLE loan_agreements ADD COLUMN signed_by VARCHAR(150) DEFAULT NULL AFTER agreement_text');
    }
    if (!isset($existingColumns['signed_at'])) {
        $conn->exec('ALTER TABLE loan_agreements ADD COLUMN signed_at DATETIME DEFAULT NULL AFTER signed_by');
    }
    if (!isset($existingColumns['decline_reason'])) {
        $conn->exec('ALTER TABLE loan_agreements ADD COLUMN decline_reason TEXT DEFAULT NULL AFTER signed_at');
    }
}

/**
 * Get a single row from a table
 */
function getRow($table, $where, $params = []) {
    global $conn;
    $sql = "SELECT * FROM $table WHERE $where LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Insert a row into a table
 */
function insert($table, $data) {
    global $conn;
    $fields = implode(", ", array_keys($data));
    $placeholders = implode(", ", array_fill(0, count($data), "?"));
    $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_values($data));
    return $conn->lastInsertId();
}

/**
 * Update rows in a table
 */
function update($table, $data, $where, $params = []) {
    global $conn;
    $set = implode(", ", array_map(fn($k) => "$k = ?", array_keys($data)));
    $sql = "UPDATE $table SET $set WHERE $where";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge(array_values($data), $params));
    return $stmt->rowCount();
}

function ensurePaymentReceiptColumns() {
    global $conn;

    $columns = [];
    $columnStmt = $conn->query('SHOW COLUMNS FROM payments');
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    if (!isset($columns['receipt_number'])) {
        $conn->exec('ALTER TABLE payments ADD COLUMN receipt_number VARCHAR(50) NULL AFTER payment_id');
    }
    if (!isset($columns['collector_id'])) {
        $conn->exec('ALTER TABLE payments ADD COLUMN collector_id INT NULL AFTER receipt_number');
    }
    if (!isset($columns['client_id'])) {
        $conn->exec('ALTER TABLE payments ADD COLUMN client_id INT NULL AFTER collector_id');
    }
    if (!isset($columns['receipt_generated_at'])) {
        $conn->exec('ALTER TABLE payments ADD COLUMN receipt_generated_at DATETIME NULL AFTER client_id');
    }
    if (!isset($columns['payment_method'])) {
        $conn->exec('ALTER TABLE payments ADD COLUMN payment_method VARCHAR(50) NOT NULL DEFAULT "Cash" AFTER receipt_generated_at');
    }
    if (!isset($columns['reference_number'])) {
        $conn->exec('ALTER TABLE payments ADD COLUMN reference_number VARCHAR(255) NULL AFTER payment_method');
    }

    $indexStmt = $conn->query("SHOW INDEX FROM payments WHERE Key_name = 'receipt_number_unique'");
    if ($indexStmt->rowCount() === 0) {
        $conn->exec("ALTER TABLE payments ADD UNIQUE KEY receipt_number_unique (receipt_number)");
    }
}

function ensureExpensesSchema() {
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS expenses (
        expense_id INT AUTO_INCREMENT PRIMARY KEY,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
        date DATE NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'Miscellaneous'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [];
    $columnStmt = $conn->query('SHOW COLUMNS FROM expenses');
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    if (!isset($columns['category'])) {
        $conn->exec('ALTER TABLE expenses ADD COLUMN category VARCHAR(100) DEFAULT "Miscellaneous" AFTER description');
    }
}

function generatePaymentReceiptNumber() {
    global $conn;

    $prefix = 'RJRR-' . date('Ymd') . '-';
    $checkQuery = "SELECT receipt_number FROM payments WHERE receipt_number LIKE ? ORDER BY payment_id DESC LIMIT 1";
    $checkResult = $conn->prepare($checkQuery);
    $checkResult->execute([$prefix . '%']);
    $lastReceipt = $checkResult->fetchColumn();

    if ($lastReceipt) {
        $lastNumber = (int)substr($lastReceipt, strrpos($lastReceipt, '-') + 1);
        return $prefix . str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);
    }

    return $prefix . '000001';
}

function updateLoanStatusForLoan($loanId) {
    global $conn;

    $loanStmt = executeQuery('SELECT total_payable, due_date FROM loans WHERE loan_id = ? LIMIT 1', [$loanId]);
    $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) {
        return;
    }

    $paidStmt = executeQuery('SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE loan_id = ?', [$loanId]);
    $totalPaid = (float)($paidStmt->fetch()['total_paid'] ?? 0);
    $totalPayable = (float)$loan['total_payable'];
    $dueDate = $loan['due_date'];

    $status = 'Active';
    if ($totalPaid >= $totalPayable && $totalPayable > 0) {
        $status = 'Paid';
    } elseif (strtotime($dueDate) < strtotime(date('Y-m-d')) && $totalPaid < $totalPayable) {
        $status = 'Overdue';
    }

    executeQuery('UPDATE loans SET status = ? WHERE loan_id = ?', [$status, $loanId]);
}

function recalculateLoanPaymentSchedule($loanId) {
    global $conn;

    $loanStmt = executeQuery('SELECT release_id FROM loans WHERE loan_id = ? LIMIT 1', [$loanId]);
    $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan || empty($loan['release_id'])) {
        return;
    }

    $releaseId = (int)$loan['release_id'];
    $paidStmt = executeQuery('SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payments WHERE loan_id = ?', [$loanId]);
    $remainingPaid = (float)($paidStmt->fetch()['total_paid'] ?? 0);

    $scheduleStmt = executeQuery('SELECT id, total_amount_due, due_date, status FROM loan_payment_schedules WHERE release_id = ? ORDER BY installment_number ASC', [$releaseId]);
    $scheduleRows = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
    $runningPaid = $remainingPaid;
    $today = date('Y-m-d');

    foreach ($scheduleRows as $schedule) {
        $expectedAmount = (float)$schedule['total_amount_due'];
        $newStatus = 'Upcoming';

        if ($runningPaid >= $expectedAmount && $expectedAmount > 0) {
            $newStatus = 'Paid';
            $runningPaid -= $expectedAmount;
        } elseif (strtotime($schedule['due_date']) < strtotime($today) && $runningPaid < $expectedAmount) {
            $newStatus = 'Overdue';
        }

        if ($newStatus !== $schedule['status']) {
            executeQuery('UPDATE loan_payment_schedules SET status = ? WHERE id = ?', [$newStatus, $schedule['id']]);
        }
    }
}

function ensureNotificationsSchema() {
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        title VARCHAR(150) NOT NULL,
        message TEXT DEFAULT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function addNotification($userId, $title, $message) {
    if (empty($userId)) {
        return;
    }

    executeQuery('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)', [(int)$userId, $title, $message]);
}

function notifyAdmins($title, $message) {
    $adminStmt = executeQuery('SELECT user_id FROM users WHERE role = ? AND status = ?', ['Admin', 'Active']);
    foreach ($adminStmt->fetchAll(PDO::FETCH_ASSOC) as $admin) {
        addNotification((int)$admin['user_id'], $title, $message);
    }
}

function addUniqueNotification($userId, $title, $message) {
    if (empty($userId)) {
        return;
    }

    $duplicateCheck = executeQuery('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND title = ? AND message = ? AND DATE(created_at) = CURDATE()', [(int)$userId, $title, $message]);
    if ((int)$duplicateCheck->fetchColumn() === 0) {
        addNotification($userId, $title, $message);
    }
}

function getUnreadNotificationCount($userId) {
    try {
        $stmt = executeQuery('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0', [(int)$userId]);
        if (!$stmt) {
            return 0;
        }
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Unable to fetch unread notification count: ' . $e->getMessage());
        return 0;
    }
}

function getUserNotifications($userId, $limit = 10) {
    $limit = (int)$limit;
    if ($limit <= 0) {
        $limit = 10;
    }
    $sql = 'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . $limit;
    try {
        $stmt = executeQuery($sql, [(int)$userId]);
        if (!$stmt) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Unable to fetch user notifications: ' . $e->getMessage());
        return [];
    }
}

function markNotificationRead($notificationId, $userId) {
    executeQuery('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [(int)$notificationId, (int)$userId]);
}

function markAllNotificationsRead($userId) {
    executeQuery('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [(int)$userId]);
}

function deleteNotification($notificationId, $userId) {
    executeQuery('DELETE FROM notifications WHERE id = ? AND user_id = ?', [(int)$notificationId, (int)$userId]);
}

function deleteReadNotifications($userId) {
    executeQuery('DELETE FROM notifications WHERE user_id = ? AND is_read = 1', [(int)$userId]);
}

ensureNotificationsSchema();

function recordPaymentTransaction($loanId, $paymentDate, $amountPaid, $collectorName, $collectorId = null, $clientId = null, $paymentMethod = 'Cash', $referenceNumber = null) {
    global $conn;

    ensurePaymentReceiptColumns();

    $conn->beginTransaction();
    try {
        $receiptNumber = null;
        $attempts = 0;
        while ($attempts < 5) {
            $receiptNumber = generatePaymentReceiptNumber();
            try {
                insert('payments', [
                    'loan_id' => $loanId,
                    'payment_date' => $paymentDate,
                    'amount_paid' => $amountPaid,
                    'collector_name' => $collectorName,
                    'collector_id' => $collectorId,
                    'client_id' => $clientId,
                    'payment_method' => $paymentMethod,
                    'reference_number' => $referenceNumber,
                    'receipt_number' => $receiptNumber,
                    'receipt_generated_at' => date('Y-m-d H:i:s')
                ]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000' && stripos($e->getMessage(), 'receipt_number') !== false) {
                    $attempts++;
                    continue;
                }
                throw $e;
            }
        }

        updateLoanStatusForLoan($loanId);
        recalculateLoanPaymentSchedule($loanId);

        $loanRow = executeQuery('SELECT l.loan_id, l.total_payable, l.status, c.user_id AS borrower_user_id, c.collector_id AS collector_user_id, CONCAT_WS(" ", c.first_name, c.last_name) AS borrower_name FROM loans l JOIN clients c ON l.client_id = c.client_id WHERE l.loan_id = ? LIMIT 1', [$loanId])->fetch(PDO::FETCH_ASSOC);
        if ($loanRow) {
            $borrowerUserId = (int)($loanRow['borrower_user_id'] ?? 0);
            $collectorRecipientId = $collectorId ? (int)$collectorId : (int)($loanRow['collector_user_id'] ?? 0);
            $loanLabel = 'Loan #' . ($loanRow['loan_id'] ?? $loanId);
            $formattedAmount = '₱' . number_format($amountPaid, 2);

            if ($borrowerUserId > 0) {
                addNotification($borrowerUserId, 'Payment Received', "A payment of {$formattedAmount} was recorded for {$loanLabel}. Thank you for your payment.");
            }

            if ($collectorRecipientId > 0) {
                addNotification($collectorRecipientId, 'Payment Recorded', "A payment of {$formattedAmount} was posted for {$loanLabel} and borrower " . ($loanRow['borrower_name'] ?: 'a borrower') . ".");
            }

            notifyAdmins('Payment Recorded', "A payment of {$formattedAmount} was recorded for {$loanLabel} by {$collectorName}.");

            if (strcasecmp((string)$loanRow['status'], 'Paid') === 0) {
                if ($borrowerUserId > 0) {
                    addNotification($borrowerUserId, 'Loan Fully Paid', "Congratulations! {$loanLabel} has been fully paid.");
                }
                if ($collectorRecipientId > 0) {
                    addNotification($collectorRecipientId, 'Loan Paid Off', "{$loanLabel} has been fully paid by borrower " . ($loanRow['borrower_name'] ?: 'a borrower') . ".");
                }
                notifyAdmins('Loan Fully Paid', "{$loanLabel} has been fully paid and marked as Paid.");
            }
        }

        $conn->commit();
        return $receiptNumber;
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}
