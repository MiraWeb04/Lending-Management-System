<?php
ob_start();
if (!defined('FRAMS_TEST_PREPEND_LOADED') || lendingEnv('DB_NAME') !== 'frams_capstone_test') {
    fwrite(STDERR, "Refusing to run outside the isolated test database.\n");
    exit(1);
}
require_once dirname(__DIR__, 2) . '/lending_system/includes/auth_lending.php';
if ((string)$conn->query('SELECT DATABASE()')->fetchColumn() !== 'frams_capstone_test') {
    fwrite(STDERR, "Wrong database.\n");
    exit(1);
}

$results = [];

$wrong = login('frams_test_admin', 'Wrong@Password1');
$results['IT-007'] = [
    'login_returned_false' => $wrong === false,
    'session_not_created' => !isLoggedIn(),
    'status' => ($wrong === false && !isLoggedIn()) ? 'PASS' : 'FAIL',
];

$sqli = login("' OR '1'='1", 'Test@Frams1');
$results['SEC-002'] = [
    'login_returned_false' => $sqli === false,
    'session_not_created' => !isLoggedIn(),
    'status' => ($sqli === false && !isLoggedIn()) ? 'PASS' : 'FAIL',
];

$admin = login('frams_test_admin', 'Test@Frams1');
$loggedIn = is_array($admin) && isLoggedIn();
logout();
$results['IT-013'] = [
    'login_succeeded' => $loggedIn,
    'logged_out' => !isLoggedIn(),
    'status' => ($loggedIn && !isLoggedIn()) ? 'PASS' : 'FAIL',
];

file_put_contents(__DIR__ . '/session_retest.json', json_encode($results, JSON_PRETTY_PRINT));
ob_end_clean();
echo 'IT-007 ' . $results['IT-007']['status'] . PHP_EOL;
echo 'SEC-002 ' . $results['SEC-002']['status'] . PHP_EOL;
echo 'IT-013 ' . $results['IT-013']['status'] . PHP_EOL;
