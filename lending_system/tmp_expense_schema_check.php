<?php
$pdo = new PDO('mysql:host=localhost;dbname=lending_management;charset=utf8', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $pdo->query('SHOW COLUMNS FROM expenses');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . ' ' . $col['Type'] . ' ' . ($col['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . PHP_EOL;
}
