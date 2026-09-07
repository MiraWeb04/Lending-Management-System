<?php
require 'includes/db_lending.php';
try {
    $stmt = $conn->prepare('SELECT user_id, username, role, status FROM users LIMIT 10');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo implode('\t', [$r['user_id'], $r['username'], $r['role'], $r['status']]) . "\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
