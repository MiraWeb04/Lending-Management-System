<?php
/**
 * Reset a user password (CLI only). Example:
 *   php tools/reset_user_password_cli.php admin "MyNewSecurePass123"
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/auth_lending.php';

$username = trim($argv[1] ?? '');
$newPassword = (string)($argv[2] ?? '');

if ($username === '' || $newPassword === '') {
    fwrite(STDERR, "Usage: php tools/reset_user_password_cli.php <username> \"<new_password>\"\n");
    exit(1);
}

$passwordError = lendingValidatePassword($newPassword);
if ($passwordError !== '') {
    fwrite(STDERR, $passwordError . PHP_EOL);
    exit(1);
}

$stmt = executeQuery('SELECT user_id, username, role FROM users WHERE username = ? LIMIT 1', [$username]);
$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

if (!$row) {
    fwrite(STDERR, "User not found: {$username}\n");
    exit(1);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
executeQuery('UPDATE users SET password = ? WHERE user_id = ?', [$hash, (int)$row['user_id']]);

echo 'Password updated for ' . $row['username'] . ' (' . ($row['role'] ?? '') . ').' . PHP_EOL;
