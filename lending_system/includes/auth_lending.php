<?php
/**
 * Authentication functions for Lending Management System
 */

require_once __DIR__ . '/env_lending.php';
lendingConfigureRuntime();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include your database connection
require_once __DIR__ . '/db_lending.php';
require_once __DIR__ . '/csrf_lending.php';

function passwordMatches($inputPassword, $storedPassword) {
    if ($storedPassword === null || $storedPassword === '' || !is_string($storedPassword)) {
        return false;
    }

    if (password_verify($inputPassword, $storedPassword)) {
        return true;
    }

    return false;
}

function upgradePasswordHashIfNeeded(int $userId, string $plainPassword, string $storedPassword): void
{
    if ($userId <= 0 || $plainPassword === '') {
        return;
    }
    if (!str_starts_with($storedPassword, '$2y$') && !str_starts_with($storedPassword, '$2a$') && !str_starts_with($storedPassword, '$argon2')) {
        executeQuery('UPDATE users SET password = ? WHERE user_id = ?', [password_hash($plainPassword, PASSWORD_DEFAULT), $userId]);
        return;
    }
    if (!password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
        return;
    }
    $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    executeQuery('UPDATE users SET password = ? WHERE user_id = ?', [$newHash, $userId]);
}

function isActiveAccount($user) {
    return is_array($user) && strcasecmp((string)($user['status'] ?? ''), 'Active') === 0;
}

function isCollector() {
    return isLoggedIn() && strcasecmp((string)($_SESSION['role'] ?? ''), 'Collector') === 0;
}

function userIsBorrowerAccount(?array $user = null): bool
{
    if (!is_array($user)) {
        if (!isLoggedIn()) {
            return false;
        }
        $user = [
            'user_id' => $_SESSION['user_id'] ?? null,
            'role' => $_SESSION['role'] ?? '',
        ];
    }

    if (strcasecmp((string)($user['role'] ?? ''), 'Borrower') === 0) {
        return true;
    }

    $userId = (int)($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $application = executeQuery('SELECT user_id FROM borrower_applications WHERE user_id = ? LIMIT 1', [$userId])->fetch(PDO::FETCH_ASSOC);
    return (bool)$application;
}

function isBorrower() {
    return isLoggedIn() && userIsBorrowerAccount();
}

function getUserByUsernameOrEmail($identifier) {
    $stmt = executeQuery('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$identifier, $identifier]);
    return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
}

function getDashboardRedirectUrl($user = null) {
    if (is_array($user) && strcasecmp((string)($user['role'] ?? ''), 'Collector') === 0) {
        return 'collector_dashboard_lending.php';
    }

    if (is_array($user) && userIsBorrowerAccount($user)) {
        return 'borrower_dashboard_lending.php';
    }

    $role = is_array($user) ? ($user['role'] ?? '') : ($_SESSION['role'] ?? '');
    if (strcasecmp((string)$role, 'Collector') === 0) {
        return 'collector_dashboard_lending.php';
    }

    if (userIsBorrowerAccount(is_array($user) ? $user : null)) {
        return 'borrower_dashboard_lending.php';
    }

    return 'dashboard_lending.php';
}

function getLogoutRedirectUrl(): string
{
    if (!isLoggedIn()) {
        return 'index.php';
    }

    if (isBorrower()) {
        return 'borrower_login_lending.php';
    }

    if (isCollector()) {
        return 'login_lending.php';
    }

    return 'login_lending.php';
}

function recordUserLastLogin($userId) {
    if (empty($userId)) {
        return false;
    }

    return executeQuery('UPDATE users SET last_login = NOW() WHERE user_id = ?', [(int)$userId]);
}

/**
 * Password reset token helpers
 */
function ensurePasswordResetTable(): void
{
    global $conn;

    $conn->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(128) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        attempts INT NOT NULL DEFAULT 0,
        INDEX (token),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $conn->exec('ALTER TABLE password_resets ADD COLUMN attempts INT NOT NULL DEFAULT 0');
    } catch (Exception $e) {
    }
}

function createPasswordResetToken(int $userId, int $ttlSeconds = 3600) {
    if (empty($userId)) return false;

    ensurePasswordResetTable();

    $token = bin2hex(random_bytes(16));
    // Use database time to compute expires_at to avoid server/DB timezone mismatches
    executeQuery('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, FROM_UNIXTIME(UNIX_TIMESTAMP() + ?))', [$userId, $token, $ttlSeconds]);
    return $token;
}

function createPasswordResetOtp(int $userId, int $ttlSeconds = 600): string
{
    if ($userId <= 0) {
        return '';
    }

    ensurePasswordResetTable();
    executeQuery('DELETE FROM password_resets WHERE user_id = ?', [$userId]);

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    executeQuery(
        'INSERT INTO password_resets (user_id, token, expires_at, attempts) VALUES (?, ?, FROM_UNIXTIME(UNIX_TIMESTAMP() + ?), 0)',
        [$userId, hash('sha256', $otp), $ttlSeconds]
    );

    return $otp;
}

function getActivePasswordResetRow(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    ensurePasswordResetTable();
    $stmt = executeQuery(
        'SELECT id, token, attempts, expires_at FROM password_resets WHERE user_id = ? AND expires_at >= NOW() ORDER BY id DESC LIMIT 1',
        [$userId]
    );
    if (!$stmt) {
        return null;
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function verifyPasswordResetOtp(int $userId, string $otp): string
{
    $otp = preg_replace('/\D+/', '', $otp) ?? '';
    if (strlen($otp) !== 6) {
        return 'Enter the 6-digit code from your email.';
    }

    $row = getActivePasswordResetRow($userId);
    if (!$row) {
        return 'That code is incorrect or has expired. Request a new code.';
    }

    if ((int)($row['attempts'] ?? 0) >= 5) {
        executeQuery('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        return 'Too many incorrect codes. Request a new code.';
    }

    if (!hash_equals((string)$row['token'], hash('sha256', $otp))) {
        executeQuery('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?', [(int)$row['id']]);
        return 'That code is incorrect or has expired. Request a new code.';
    }

    return '';
}

function resetUserPasswordAfterOtp(int $userId, string $newPassword)
{
    if ($userId <= 0 || trim($newPassword) === '' || !getActivePasswordResetRow($userId)) {
        return false;
    }

    $userStmt = executeQuery('SELECT * FROM users WHERE user_id = ? LIMIT 1', [$userId]);
    $user = $userStmt ? $userStmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$user) {
        return false;
    }

    executeQuery('UPDATE users SET password = ? WHERE user_id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    syncBorrowerClientLink($userId);
    executeQuery('DELETE FROM password_resets WHERE user_id = ?', [$userId]);

    return $user;
}

function getUserByResetToken(string $token) {
    if (trim($token) === '') return false;
    $stmt = executeQuery('SELECT u.* FROM password_resets pr JOIN users u ON pr.user_id = u.user_id WHERE pr.token = ? AND pr.expires_at >= NOW() LIMIT 1', [$token]);
    return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
}

function resetUserPasswordByToken(string $token, string $newPassword) {
    if (trim($token) === '' || trim($newPassword) === '') return false;
    $user = getUserByResetToken($token);
    if (!$user) return false;

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    executeQuery('UPDATE users SET password = ? WHERE user_id = ?', [$hashed, $user['user_id']]);
    syncBorrowerClientLink((int)$user['user_id']);
    // delete all resets for user
    executeQuery('DELETE FROM password_resets WHERE user_id = ?', [$user['user_id']]);
    return $user;
}

function syncBorrowerClientLink(int $userId) {
    if ($userId <= 0) {
        return false;
    }

    $client = executeQuery(
        'SELECT c.client_id
         FROM clients c
         INNER JOIN loans l ON l.client_id = c.client_id
         INNER JOIN loan_releases r ON r.id = l.release_id
         LEFT JOIN loan_applications la ON la.id = r.application_id
         WHERE la.user_id = ? OR r.borrower_user_id = ?
         ORDER BY l.loan_id DESC
         LIMIT 1',
        [$userId, $userId]
    )->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        $client = executeQuery(
            'SELECT c.client_id
             FROM clients c
             INNER JOIN borrower_applications ba ON (ba.email <> "" AND ba.email = c.email) OR (ba.mobile_number <> "" AND ba.mobile_number = c.contact)
             WHERE ba.user_id = ?
             ORDER BY c.client_id DESC
             LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    if (!$client) {
        return false;
    }

    return (bool)executeQuery(
        'UPDATE clients SET user_id = ? WHERE client_id = ? AND (user_id IS NULL OR user_id = ?)',
        [$userId, (int)$client['client_id'], $userId]
    );
}

function denyCollectorAccess($message = 'Unauthorized Access') {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Unauthorized Access</title><link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet"></head><body><div class="container py-5"><div class="alert alert-danger"><h4 class="alert-heading">Unauthorized Access</h4><p>' . htmlspecialchars($message) . '</p><a href="collector_dashboard_lending.php" class="btn btn-primary">Return to Dashboard</a></div></div></body></html>';
    exit;
}

function ensureCollectorCanAccessClient($clientId, $collectorUserId) {
    if (!isCollector()) {
        return true;
    }

    $clientQuery = "SELECT client_id FROM clients WHERE client_id = ? AND collector_id = ? LIMIT 1";
    $clientResult = executeQuery($clientQuery, [$clientId, $collectorUserId]);
    return (bool)$clientResult->fetch();
}

function ensureCollectorCanAccessLoan($loanId, $collectorUserId) {
    if (!isCollector()) {
        return true;
    }

    $loanQuery = "SELECT l.loan_id FROM loans l JOIN clients c ON l.client_id = c.client_id WHERE l.loan_id = ? AND (c.collector_id = ? OR l.collector_id = ?) LIMIT 1";
    $loanResult = executeQuery($loanQuery, [$loanId, $collectorUserId, $collectorUserId]);
    return (bool)$loanResult->fetch();
}

function ensureCollectorCanAccessPayment($paymentId, $collectorUserId) {
    if (!isCollector()) {
        return true;
    }

    $paymentQuery = "SELECT p.payment_id FROM payments p JOIN loans l ON p.loan_id = l.loan_id JOIN clients c ON l.client_id = c.client_id WHERE p.payment_id = ? AND (c.collector_id = ? OR l.collector_id = ?) LIMIT 1";
    $paymentResult = executeQuery($paymentQuery, [$paymentId, $collectorUserId, $collectorUserId]);
    return (bool)$paymentResult->fetch();
}

/**
 * Login
 */
function startUserSession($user) {
    if (!is_array($user)) {
        return false;
    }

    $role = trim((string)($user['role'] ?? ''));
    if ($role === '' && !empty($user['user_id'])) {
        $application = executeQuery('SELECT user_id FROM borrower_applications WHERE user_id = ? LIMIT 1', [$user['user_id']])->fetch(PDO::FETCH_ASSOC);
        if ($application) {
            $role = 'Borrower';
        }
    }

    if ($role === '') {
        $role = $user['role'] ?? '';
    }

    if (strcasecmp($role, 'Borrower') === 0) {
        syncBorrowerClientLink((int)($user['user_id'] ?? 0));
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['role'] = $role;
    $_SESSION['logged_in'] = true;
    $_SESSION['user'] = [
        'user_id' => $user['user_id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'email' => $user['email'] ?? '',
        'role' => $role
    ];
    return $user;
}

function login($username, $password) {
    $user = getUserByUsernameOrEmail($username);
    if (!$user || !isActiveAccount($user)) {
        return false;
    }

    $role = strtoupper(trim((string)($user['role'] ?? '')));
    if (in_array($role, ['ADMIN', 'COLLECTOR'], true) && passwordMatches($password, (string)$user['password'])) {
        upgradePasswordHashIfNeeded((int)$user['user_id'], $password, (string)$user['password']);
        recordUserLastLogin($user['user_id']);
        return startUserSession($user);
    }

    return false;
}

function borrowerAccountCanLogin($user) {
    if (!is_array($user)) {
        return false;
    }

    $status = trim((string)($user['status'] ?? ''));
    $normalizedStatus = strtolower($status);
    if ($normalizedStatus === 'active' || $normalizedStatus === 'approved' || $normalizedStatus === 'verified' || $normalizedStatus === 'pending approval' || $normalizedStatus === 'pending') {
        $allowed = ['active', 'approved', 'verified'];
        if (in_array($normalizedStatus, $allowed, true)) {
            return true;
        }
    }

    if (!empty($user['user_id'])) {
        $application = executeQuery('SELECT status, verification_status FROM borrower_applications WHERE user_id = ? LIMIT 1', [$user['user_id']])->fetch(PDO::FETCH_ASSOC);
        if ($application) {
            $appStatus = trim((string)($application['status'] ?? ''));
            $verificationStatus = trim((string)($application['verification_status'] ?? ''));
            $appNormalized = strtolower($appStatus);
            $verificationNormalized = strtolower($verificationStatus);
            if (in_array($appNormalized, ['approved', 'verified', 'active'], true) || in_array($verificationNormalized, ['verified', 'approved', 'active'], true)) {
                return true;
            }
        }
    }

    return false;
}

function authenticateBorrowerLogin($identifier, $password) {
    $user = getUserByUsernameOrEmail($identifier);

    if (!$user || !passwordMatches($password, (string)$user['password'])) {
        return false;
    }

    upgradePasswordHashIfNeeded((int)$user['user_id'], $password, (string)$user['password']);

    $role = trim((string)($user['role'] ?? ''));
    if (strcasecmp($role, 'Borrower') !== 0) {
        $application = executeQuery('SELECT user_id FROM borrower_applications WHERE user_id = ? LIMIT 1', [$user['user_id']])->fetch(PDO::FETCH_ASSOC);
        if (!$application) {
            return false;
        }
    }

    recordUserLastLogin($user['user_id']);
    return $user;
}

/**
 * Logout
 */
function logout() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

/**
 * Check login status
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Check admin role
 */
function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'Admin';
}

/**
 * Require login to access a page
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login_lending.php');
        exit();
    }
}

/**
 * Staff-only routes (admin + collector). Borrowers are redirected to their portal.
 */
function requireStaff() {
    requireLogin();
    if (isBorrower()) {
        header('Location: borrower_dashboard_lending.php');
        exit();
    }
}

function requireStaffOrAdminOnlyForSensitiveTools() {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

/**
 * Require admin role to access a page
 */
function requireAdmin() {
    requireStaff();
    if (!isAdmin()) {
        $redirect = isCollector() ? 'collector_dashboard_lending.php' : 'dashboard_lending.php';
        header('Location: ' . $redirect);
        exit();
    }
}

/**
 * Get current logged-in user
 */
csrfEnsureToken();

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }

    $sessionUser = $_SESSION['user'] ?? [];

    return [
        'user_id'   => $_SESSION['user_id'] ?? ($sessionUser['user_id'] ?? null),
        'username'  => $_SESSION['username'] ?? ($sessionUser['username'] ?? ''),
        'full_name' => $_SESSION['full_name'] ?? ($sessionUser['full_name'] ?? ''),
        'email'     => $_SESSION['email'] ?? ($sessionUser['email'] ?? ''),
        'role'      => $_SESSION['role'] ?? ($sessionUser['role'] ?? '')
    ];
}
?>
