<?php
if (!isset($conn)) {
    return;
}

try {
    $sql = "
    UPDATE loans l
    LEFT JOIN (
        SELECT loan_id, COALESCE(SUM(amount_paid),0) AS paid
        FROM payments
        GROUP BY loan_id
    ) p ON l.loan_id = p.loan_id
    SET l.status = CASE
        WHEN COALESCE(p.paid,0) >= l.total_payable THEN 'Paid'
        WHEN l.due_date < CURDATE() AND COALESCE(p.paid,0) < l.total_payable THEN 'Overdue'
        ELSE l.status
    END
    WHERE l.status IN ('Active','Overdue')
    ";

    $conn->exec($sql);

} catch (PDOException $e) {
    error_log("Status update error: " . $e->getMessage());
}
