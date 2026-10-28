<?php
$pdo = new PDO('mysql:host=localhost;dbname=frams_capstone_test;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

function dumpRows(PDO $pdo, string $title, string $sql): void
{
    echo $title, "\n";
    foreach ($pdo->query($sql) as $row) {
        echo implode(' | ', $row), "\n";
    }
    echo "\n";
}

dumpRows($pdo, 'USERS', 'SELECT user_id, username, role, status, email, full_name FROM users ORDER BY user_id');
dumpRows($pdo, 'CLIENTS', 'SELECT client_id, first_name, last_name, user_id, collector_id FROM clients ORDER BY client_id');
dumpRows($pdo, 'LOANS', 'SELECT loan_id, client_id, collector_id, loan_number, status FROM loans ORDER BY loan_id');
dumpRows($pdo, 'PAYMENTS', 'SELECT payment_id, loan_id, receipt_number, amount_paid, collector_name FROM payments ORDER BY payment_id');
dumpRows($pdo, 'DOCS', 'SELECT d.id, d.application_id, la.user_id, d.document_name, d.document_path FROM loan_application_documents d JOIN loan_applications la ON la.id = d.application_id ORDER BY d.id LIMIT 30');
echo "COUNTS\n";
foreach (['users', 'payments', 'expenses', 'loan_releases', 'clients'] as $table) {
    echo $table, '=', $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(), "\n";
}
