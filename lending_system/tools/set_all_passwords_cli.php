<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/auth_lending.php';

$newPassword = (string)($argv[1] ?? '@Password123!');
$passwordError = lendingValidatePassword($newPassword);
if ($passwordError !== '') {
    fwrite(STDERR, $passwordError . PHP_EOL);
    exit(1);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$updated = executeQuery('UPDATE users SET password = ?', [$hash]);
if ($updated === false) {
    fwrite(STDERR, "Failed to update user passwords.\n");
    exit(1);
}

$count = (int)executeQuery('SELECT COUNT(*) FROM users')->fetchColumn();
echo 'Updated password for ' . $count . ' user account(s).' . PHP_EOL;
