<?php
$pdo = new PDO('mysql:host=localhost;dbname=lending_management;charset=utf8', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (['users','clients','loans','payments'] as $table) {
    echo "TABLE $table\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        echo $column['Field'] . "\n";
    }
    echo "---\n";
}
