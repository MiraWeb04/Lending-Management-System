<?php
require_once 'includes/db_lending.php';

// Fix rows where expires_at is earlier than created_at by recomputing expires_at using DB time
$rows = executeQuery('SELECT id, created_at FROM password_resets WHERE expires_at < created_at')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    // set expires_at = created_at + 3600 seconds (1 hour)
    executeQuery('UPDATE password_resets SET expires_at = FROM_UNIXTIME(UNIX_TIMESTAMP(created_at) + 3600) WHERE id = ?', [(int)$r['id']]);
    echo "Fixed id=" . $r['id'] . PHP_EOL;
}
if (count($rows) === 0) echo "No rows needed fixing." . PHP_EOL;
