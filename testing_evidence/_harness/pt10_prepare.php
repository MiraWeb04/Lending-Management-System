<?php
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo $col['Field'], ' ', $col['Type'], ' null=', $col['Null'], ' default=', var_export($col['Default'], true), "\n";
}
$existing = $pdo->query("SELECT user_id FROM users WHERE username = 'frams_pt10_upload'")->fetchColumn();
if (!$existing) {
    $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            'frams_pt10_upload',
            password_hash('Test@Frams1', PASSWORD_DEFAULT),
            'FRAMS Upload Probe',
            'frams-pt10@example.invalid',
            'Borrower',
            'Active',
        ]);
    echo "created ", $pdo->lastInsertId(), "\n";
} else {
    echo "exists ", $existing, "\n";
}
$dir = 'C:/xampp/htdocs/lending_system/lending_system/uploads/loan_documents';
$before = is_dir($dir) ? count(scandir($dir)) : 0;
echo "upload_dir_entries=$before\n";
