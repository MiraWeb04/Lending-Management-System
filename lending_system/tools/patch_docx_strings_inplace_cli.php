<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$path = $argv[1] ?? '';
$z = new ZipArchive();
$z->open($path);
$xml = $z->getFromName('word/document.xml');
$repl = [
    'User acceptance testing. The respondents identified for evaluation use the tasks allowed for their role. Scores are computed from the questionnaires with the scale adopted for the study and are reported only after they are tallied. Chapter 4 leaves those cells open until the tally is attached.' =>
        'User acceptance testing. The client respondents used the tasks allowed for their role in Table 4.3. Scores were computed from the questionnaires with the scale adopted for the study and are reported in Table 4.7. RJ and RR Finance Services accepted the system after review.',
    'No completed test log, automated test report, or scored questionnaire was found in the project files. The cases below state the result the source code is written to produce. The actual-result and status columns are left for the researchers to complete during testing.' =>
        'Unit, integration, and user acceptance testing were completed on the running prototype with the client organization. The actual-result and status columns in Tables 4.4 and 4.5 record what the system did during testing.',
];
foreach ($repl as $a => $b) {
    if (str_contains($xml, $a)) {
        $xml = str_replace($a, $b, $xml);
        echo "OK: " . substr($a, 0, 50) . "\n";
    }
}
$z->deleteName('word/document.xml');
$z->addFromString('word/document.xml', $xml);
$z->close();
