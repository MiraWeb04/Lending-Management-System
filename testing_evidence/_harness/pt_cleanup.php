<?php
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$hash = file_get_contents(__DIR__ . '/pt12_hash.txt');
$pdo->prepare("UPDATE users SET password = ? WHERE username = 'frams_test_borrower'")->execute([$hash]);
$pdo->exec("DELETE FROM password_resets WHERE user_id = (SELECT user_id FROM users WHERE username = 'frams_test_borrower')");
$pdo->exec("DELETE FROM users WHERE username = 'frams_pt10_upload'");
$ok = password_verify('Test@Frams1', $pdo->query("SELECT password FROM users WHERE username = 'frams_test_borrower'")->fetchColumn());
echo 'password_restored=' . ($ok ? 'yes' : 'no') . "\n";
echo 'users=' . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
echo 'resets=' . $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn() . "\n";
echo 'payments=' . $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() . "\n";
echo 'probe_user=' . $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'pt05_should_not_save'")->fetchColumn() . "\n";
