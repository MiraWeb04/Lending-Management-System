<?php

require_once __DIR__ . '/auth_lending.php';
require_once __DIR__ . '/borrower_id_helpers.php';

function ensureBorrowerApplicationsColumn(string $columnName, string $alterSql): void
{
    global $conn;

    $columnCheck = $conn->query('SHOW COLUMNS FROM borrower_applications WHERE Field = ' . $conn->quote($columnName));
    if ($columnCheck && $columnCheck->rowCount() > 0) {
        return;
    }

    try {
        $conn->exec($alterSql);
    } catch (PDOException $e) {
        if (!preg_match('/\s+AFTER\s+/i', $alterSql)) {
            throw $e;
        }
        $fallback = preg_replace('/\s+AFTER\s+`?[A-Za-z0-9_]+`?/i', '', $alterSql, 1);
        $conn->exec((string)$fallback);
    }
}

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
        government_id_image_path VARCHAR(255) DEFAULT NULL,
        government_id_uploaded_at DATETIME DEFAULT NULL,
        consent_accepted TINYINT(1) DEFAULT 0,
        status VARCHAR(50) DEFAULT 'Pending Approval',
        verification_status VARCHAR(50) DEFAULT 'Pending',
        rejection_reason TEXT DEFAULT NULL,
        approved_by VARCHAR(150) DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $requiredColumns = [
        'verification_status' => "ALTER TABLE borrower_applications ADD COLUMN verification_status VARCHAR(50) DEFAULT 'Pending'",
        'rejection_reason' => "ALTER TABLE borrower_applications ADD COLUMN rejection_reason TEXT DEFAULT NULL",
        'approved_by' => "ALTER TABLE borrower_applications ADD COLUMN approved_by VARCHAR(150) DEFAULT NULL",
        'approved_at' => "ALTER TABLE borrower_applications ADD COLUMN approved_at DATETIME DEFAULT NULL",
        'government_id_image_path' => "ALTER TABLE borrower_applications ADD COLUMN government_id_image_path VARCHAR(255) DEFAULT NULL AFTER government_id_number",
        'government_id_uploaded_at' => "ALTER TABLE borrower_applications ADD COLUMN government_id_uploaded_at DATETIME DEFAULT NULL AFTER government_id_image_path",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        ensureBorrowerApplicationsColumn($columnName, $alterSql);
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

    $passwordError = lendingValidatePassword($password);
    if ($passwordError !== '') {
        return ['success' => false, 'error' => $passwordError];
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

function borrowerRegistrationLimitError(string $value, int $max, string $label): string
{
    if (strlen($value) > $max) {
        return 'Please shorten the ' . $label . ' and try again.';
    }

    return '';
}

function borrowerRegistrationPersonNameError(string $value, string $label, bool $required = true): string
{
    if ($value === '') {
        return $required ? 'Please enter the ' . $label . '.' : '';
    }
    if (!preg_match('/^\p{L}[\p{L}\s.\'\-]*$/u', $value)) {
        return ucfirst($label) . ' cannot contain numbers or special characters such as # $ % ^ &.';
    }

    return '';
}

function borrowerRegistrationMobileError(string $value): string
{
    if ($value === '' || preg_match('/[^\d+\-\s()]/', $value) || !preg_match('/^\+?[0-9][0-9\-\s()]*$/', $value)) {
        return 'Mobile number cannot contain letters or special characters such as # $ % ^ &.';
    }
    $digits = preg_replace('/\D/', '', $value) ?? '';
    $digitCount = strlen($digits);
    if ($digitCount < 10 || $digitCount > 15) {
        return 'Please enter a valid mobile number.';
    }

    return '';
}

function borrowerRegistrationEmailError(string $email): string
{
    if ($email === '' || preg_match('/[#$%^&*()=\[\]{}|\\\\:;"\'<>,\/?!`~]/', $email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Email address cannot contain special characters such as # $ % ^ &. Use a normal email address.';
    }

    return '';
}

function borrowerRegistrationAddressError(string $value): string
{
    if ($value === '' || !preg_match('/^[\p{L}0-9][\p{L}0-9\s,.\'\/\-]*$/u', $value) || !preg_match('/\p{L}/u', $value)) {
        return 'Complete address cannot contain special characters such as # $ % ^ &.';
    }

    return '';
}

function borrowerRegistrationSafeError(Throwable $e): string
{
    if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
        return 'That username or email address is already registered.';
    }

    $allowed = [
        'The ID image could not be saved. Please try again.',
        'The uploaded ID image is corrupted or could not be processed.',
        'Registration could not be completed. Please try again.',
    ];
    if ($e instanceof RuntimeException && !($e instanceof PDOException) && in_array($e->getMessage(), $allowed, true)) {
        return $e->getMessage();
    }

    return 'Registration could not be completed. Please try again.';
}

/**
 * Public borrower registration. The account stays pending until an administrator approves it.
 * A valid ID image is required. The file is removed if the database transaction does not commit.
 *
 * @param array<string, mixed> $input
 * @param array<string, mixed> $idFile
 * @return array{ok: bool, error?: string, user_id?: int, application_id?: int, email?: string}
 */
function registerPendingBorrower(array $input, array $idFile, bool $abortAfterSave = false): array
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
    $governmentIdNumber = trim(preg_replace('/\s+/', ' ', (string)($input['government_id_number'] ?? '')) ?? '');
    $consent = !empty($input['consent']);

    if ($firstName === '' || $lastName === '' || $dob === '' || $gender === '' || $civilStatus === '' || $nationality === ''
        || $mobileNumber === '' || $email === '' || $address === '' || $username === '' || $password === ''
        || $confirmPassword === '' || !$consent) {
        return ['ok' => false, 'error' => 'Please complete all required fields and agree to the terms before registering.'];
    }

    $dobValue = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$dobValue instanceof DateTime || $dobValue->format('Y-m-d') !== $dob) {
        return ['ok' => false, 'error' => 'Please enter a valid date of birth.'];
    }

    foreach ([
        borrowerRegistrationLimitError($firstName, 100, 'first name'),
        borrowerRegistrationLimitError($middleName, 100, 'middle name'),
        borrowerRegistrationLimitError($lastName, 100, 'last name'),
        borrowerRegistrationLimitError($gender, 30, 'gender'),
        borrowerRegistrationLimitError($civilStatus, 30, 'civil status'),
        borrowerRegistrationLimitError($nationality, 100, 'nationality'),
        borrowerRegistrationLimitError($mobileNumber, 30, 'mobile number'),
        borrowerRegistrationLimitError($email, 150, 'email address'),
        borrowerRegistrationLimitError($address, 2000, 'address'),
        borrowerRegistrationLimitError($username, 100, 'username'),
        borrowerRegistrationPersonNameError($firstName, 'first name'),
        borrowerRegistrationPersonNameError($middleName, 'middle name', false),
        borrowerRegistrationPersonNameError($lastName, 'last name'),
        borrowerRegistrationPersonNameError($nationality, 'nationality'),
        borrowerRegistrationMobileError($mobileNumber),
        borrowerRegistrationEmailError($email),
        borrowerRegistrationAddressError($address),
    ] as $limitError) {
        if ($limitError !== '') {
            return ['ok' => false, 'error' => $limitError];
        }
    }

    if ($password !== $confirmPassword) {
        return ['ok' => false, 'error' => 'The password and confirmation password do not match.'];
    }

    $passwordError = lendingValidatePassword($password);
    if ($passwordError !== '') {
        return ['ok' => false, 'error' => $passwordError];
    }

    $idTypeError = validateBorrowerIdType($governmentIdType);
    if ($idTypeError !== '') {
        return ['ok' => false, 'error' => $idTypeError];
    }
    $idNumberError = validateBorrowerIdNumber($governmentIdNumber);
    if ($idNumberError !== '') {
        return ['ok' => false, 'error' => $idNumberError];
    }

    $imageCheck = validateBorrowerIdUpload($idFile);
    if (!$imageCheck['ok']) {
        return ['ok' => false, 'error' => (string)$imageCheck['message']];
    }

    $existingUser = executeQuery('SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1', [$username, $email]);
    if ($existingUser && $existingUser->fetch(PDO::FETCH_ASSOC)) {
        return ['ok' => false, 'error' => 'That username or email address is already registered.'];
    }

    ensureBorrowerApplicationsTable();
    ensureUsersBorrowerRoleEnum();

    $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
    if (function_exists('mb_substr')) {
        $fullName = mb_substr($fullName, 0, 150);
    } else {
        $fullName = substr($fullName, 0, 150);
    }

    $writtenPath = null;
    try {
        $conn->beginTransaction();

        $insertUser = $conn->prepare('INSERT INTO users (username, password, full_name, role, status, date_created, email, contact_number, employee_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)');
        $insertUser->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $fullName,
            'Borrower',
            'Pending Approval',
            $email,
            $mobileNumber,
            null,
        ]);
        $userId = (int)$conn->lastInsertId();
        if ($userId <= 0) {
            throw new RuntimeException('Registration could not be completed. Please try again.');
        }

        $stored = persistBorrowerIdImage((string)$idFile['tmp_name'], (string)$imageCheck['mime']);
        if (!$stored['ok']) {
            throw new RuntimeException((string)$stored['message']);
        }
        $writtenPath = (string)$stored['path'];

        $applicationStmt = $conn->prepare(
            'INSERT INTO borrower_applications (
                user_id, first_name, middle_name, last_name, date_of_birth, gender, civil_status, nationality,
                mobile_number, email, complete_address, username, government_id_type, government_id_number,
                government_id_image_path, government_id_uploaded_at, consent_accepted, status, verification_status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())'
        );
        $applicationStmt->execute([
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
            $stored['filename'],
            1,
            'Pending Approval',
            'Pending',
        ]);
        $applicationId = (int)$conn->lastInsertId();
        if ($applicationId <= 0) {
            throw new RuntimeException('Registration could not be completed. Please try again.');
        }

        if ($abortAfterSave && PHP_SAPI === 'cli') {
            throw new RuntimeException('Registration could not be completed. Please try again.');
        }

        $conn->commit();
        $writtenPath = null;

        try {
            notifyAdmins('New Borrower Registration', "A new borrower account was registered by {$firstName} {$lastName}.");
        } catch (Throwable $notifyError) {
            error_log('registerPendingBorrower notify: ' . $notifyError->getMessage());
        }

        return [
            'ok' => true,
            'user_id' => $userId,
            'application_id' => $applicationId,
            'email' => $email,
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        if (is_string($writtenPath) && $writtenPath !== '') {
            borrowerIdDeleteOwnedFile($writtenPath);
            $writtenPath = null;
        }
        error_log('registerPendingBorrower: ' . $e->getMessage());

        return ['ok' => false, 'error' => borrowerRegistrationSafeError($e)];
    }
}
