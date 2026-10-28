<?php
$root = dirname(__DIR__);
$verification = json_decode((string)file_get_contents($root . '/evidence/verification_results.json'), true);
$http = json_decode((string)file_get_contents($root . '/evidence/http_checks.json'), true);
$out = fopen($root . '/reports/executed_test_results.csv', 'w');
fputcsv($out, ['test_id', 'category', 'feature', 'preconditions', 'inputs', 'expected', 'actual', 'difference', 'status', 'evidence', 'notes', 'executed_at']);
foreach ($verification['results'] as $row) {
    fputcsv($out, [
        $row['id'], $row['category'], $row['feature'], $row['preconditions'], $row['inputs'],
        $row['expected'], $row['actual'], $row['difference'], $row['status'], $row['evidence'],
        $row['notes'], $row['executed_at'],
    ]);
}
foreach ($http['results'] as $row) {
    fputcsv($out, [
        $row['id'], 'HTTP', $row['objective'], 'Isolated server 127.0.0.1:8099 and frams_capstone_test', '',
        $row['expected'], $row['actual'], '', $row['status'], $row['evidence'],
        $row['notes'], $row['executed_at'],
    ]);
}
fclose($out);
echo "csv rows written\n";
