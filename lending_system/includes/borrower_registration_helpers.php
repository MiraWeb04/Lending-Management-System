<?php

function ensureBorrowerApplicationsTable(): void
{
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS borrower_applications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        first_name VARCHAR(100) NOT NULL,
        middle_name VARCHAR(100) DEFAULT NULL,
        last_name VARCHAR(100) NOT NULL,
        date_of_birth DATE DEFAULT NULL,
        gender VARCHAR(30) DEFAULT NULL,
        civil_status VARCHAR(30) DEFAULT NULL,
        nationality VARCHAR(100) DEFAULT NULL,
        mobile_number VARCHAR(30) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        complete_address TEXT DEFAULT NULL,
        username VARCHAR(100) DEFAULT NULL,
        government_id_type VARCHAR(80) DEFAULT NULL,
        government_id_number VARCHAR(100) DEFAULT NULL,
        consent_accepted TINYINT(1) DEFAULT 0,
        status VARCHAR(50) DEFAULT 'Pending Approval',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $requiredColumns = [
        'verification_status' => "ALTER TABLE borrower_applications ADD COLUMN verification_status VARCHAR(50) DEFAULT 'Pending'",
        'rejection_reason' => "ALTER TABLE borrower_applications ADD COLUMN rejection_reason TEXT DEFAULT NULL",
        'approved_by' => "ALTER TABLE borrower_applications ADD COLUMN approved_by VARCHAR(150) DEFAULT NULL",
        'approved_at' => "ALTER TABLE borrower_applications ADD COLUMN approved_at DATETIME DEFAULT NULL",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        $columnCheck = $conn->query("SHOW COLUMNS FROM borrower_applications WHERE Field = " . $conn->quote($columnName));
        if ($columnCheck && $columnCheck->rowCount() === 0) {
            $conn->exec($alterSql);
        }
    }
}

function ensureClientUserIdColumn(): void
{
    global $conn;

    $columnStmt = $conn->query('SHOW COLUMNS FROM clients');
    $columns = [];
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    if (!isset($columns['user_id'])) {
        $conn->exec('ALTER TABLE clients ADD COLUMN user_id INT NULL DEFAULT NULL AFTER client_id');
    }
}

function ensureUsersBorrowerRoleEnum(): void
{
    global $conn;

    try {
        $conn->exec("ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Collector','Borrower') NOT NULL DEFAULT 'Borrower'");
    } catch (Throwable $e) {
        // Column may already allow Borrower; do not fail client creation.
        error_log('ensureUsersBorrowerRoleEnum: ' . $e->getMessage());
    }
}

/**
 * Admin-created client: same data as public registration, auto-approved borrower account + client record.
 *
 * @param array<string, mixed> $input
 * @return array{success: bool, client_id?: int, user_id?: int, error?: string}
 */
function createClientFromBorrowerRegistration(array $input, string $approvedBy = 'Admin'): array
{
    global $conn;

    $firstName = trim((string)($input['first_name'] ?? ''));
    $middleName = trim((string)($input['middle_name'] ?? ''));
    $lastName = trim((string)($input['last_name'] ?? ''));
    $dob = trim((string)($input['dob'] ?? ''));
    $gender = trim((string)($input['gender'] ?? ''));
    $civilStatus = trim((string)($input['civil_status'] ?? ''));
    $nationality = trim((string)($input['nationality'] ?? ''));
    $mobileNumber = trim((string)($input['mobile_number'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');
    $governmentIdType = trim((string)($input['government_id_type'] ?? ''));
    $governmentIdNumber = trim((string)($input['government_id_number'] ?? ''));
    $consent = !empty($input['consent']);

    if ($firstName === '' || $lastName === '' || $dob === '' || $gender === '' || $civilStatus === '' || $nationality === ''
        || $mobileNumber === '' || $email === '' || $address === '' || $username === '' || $password === ''
        || $confirmPassword === '' || $governmentIdType === '' || $governmentIdNumber === '' || !$consent) {
        return ['success' => false, 'error' => 'Please complete all required fields and confirm consent before saving the client.'];
    }

    if ($password !== $confirmPassword) {
        return ['success' => false, 'error' => 'The password and confirmation password do not match.'];
    }

    $existingUser = executeQuery('SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email])->fetch(PDO::FETCH_ASSOC);
    if ($existingUser) {
        return ['success' => false, 'error' => 'That username or email address is already registered.'];
    }

    ensureBorrowerApplicationsTable();
    ensureClientUserIdColumn();
    ensureUsersBorrowerRoleEnum();

    $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    try {
        $conn->beginTransaction();

        $userInsert = executeQuery(
            'INSERT INTO users (username, password, full_name, role, status, date_created, email, contact_number, employee_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
            [$username, $hashedPassword, $fullName, 'Borrower', 'Active', $email, $mobileNumber, null]
        );
        if ($userInsert === false) {
            throw new RuntimeException('Failed to create user account.');
        }
        $userId = (int)$conn->lastInsertId();
        if ($userId <= 0) {
            throw new RuntimeException('Failed to allocate user id.');
        }

        $borrowerStmt = $conn->prepare(
            'INSERT INTO borrower_applications (user_id, first_name, middle_name, last_name, date_of_birth, gender, civil_status, nationality, mobile_number, email, complete_address, username, government_id_type, government_id_number, consent_accepted, status, verification_status, approved_by, approved_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $borrowerStmt->execute([
            $userId,
            $firstName,
            $middleName !== '' ? $middleName : null,
            $lastName,
            $dob,
            $gender,
            $civilStatus,
            $nationality,
            $mobileNumber,
            $email,
            $address,
            $username,
            $governmentIdType,
            $governmentIdNumber,
            1,
            'Approved',
            'Verified',
            $approvedBy,
        ]);

        $clientStmt = $conn->prepare(
            'INSERT INTO clients (user_id, first_name, last_name, address, contact, email, date_registered) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $clientStmt->execute([
            $userId,
            $firstName,
            $lastName,
            $address,
            $mobileNumber,
            $email,
            date('Y-m-d'),
        ]);
        $clientId = (int)$conn->lastInsertId();
        if ($clientId <= 0) {
            throw new RuntimeException('Failed to create client record.');
        }

        if ($conn->inTransaction()) {
            $conn->commit();
        }

        return ['success' => true, 'client_id' => $clientId, 'user_id' => $userId];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('createClientFromBorrowerRegistration: ' . $e->getMessage());

        return ['success' => false, 'error' => 'Failed to add client. Please try again.'];
    }
}
