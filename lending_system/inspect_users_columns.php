<?php
require 'includes/db_lending.php';
try {
    $stmt = $conn->prepare('SHOW COLUMNS FROM users');
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo $c['Field'] . "\t" . $c['Type'] . "\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
