<?php
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$hash = $pdo->query("SELECT password FROM users WHERE username = 'frams_test_borrower'")->fetchColumn();
file_put_contents(__DIR__ . '/pt12_hash.txt', $hash);
echo "saved\n";
echo @file_get_contents(sys_get_temp_dir() . '/pt12_ttl.txt'), "\n";
