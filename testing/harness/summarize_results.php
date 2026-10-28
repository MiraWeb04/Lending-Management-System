<?php
$payload = json_decode((string)file_get_contents(dirname(__DIR__) . '/evidence/verification_results.json'), true);
echo json_encode($payload['counts'], JSON_PRETTY_PRINT), PHP_EOL;
foreach ($payload['results'] as $row) {
    if (!in_array($row['status'], ['FAIL', 'BLOCKED'], true) && $row['id'] !== 'PERF-01' && $row['id'] !== 'FIN-19') {
        continue;
    }
    echo '--- ', $row['id'], ' ', $row['status'], PHP_EOL;
    echo 'expected=', $row['expected'], PHP_EOL;
    echo 'actual=', $row['actual'], PHP_EOL;
    echo 'difference=', $row['difference'], PHP_EOL;
}
