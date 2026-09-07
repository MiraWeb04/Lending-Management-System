<?php
require_once 'includes/db_lending.php';

try {
    $stmt = executeQuery('SELECT id, user_id, token, expires_at, created_at FROM password_resets ORDER BY id DESC LIMIT 20');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    echo "Error reading password_resets: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

if (count($rows) === 0) {
    echo "No password_resets rows found." . PHP_EOL;
    exit(0);
}

foreach ($rows as $r) {
    echo "id=" . $r['id'] . " user_id=" . $r['user_id'] . " token=" . $r['token'] . " expires_at=" . $r['expires_at'] . " created_at=" . $r['created_at'] . PHP_EOL;
}
