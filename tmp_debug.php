<?php
require_once 'lending_system/includes/db_lending.php';
$sql = "SELECT la.*, u.full_name AS borrower_name, u.status AS borrower_status, u.user_id AS borrower_user_id, (SELECT agreement_status FROM loan_agreements WHERE application_id = la.id ORDER BY id DESC LIMIT 1) AS agreement_status_value FROM loan_applications la LEFT JOIN users u ON u.user_id = la.user_id WHERE 1=1 ORDER BY la.submitted_at DESC LIMIT 15 OFFSET 0";
$stmt = executeQuery($sql, []);
if ($stmt === false) {
    echo "executeQuery returned false\n";
    exit;
}
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
var_dump($result);
