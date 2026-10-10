<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$path = $argv[1] ?? '';
$z = new ZipArchive();
$z->open($path);
$x = $z->getFromName('word/document.xml');
$z->close();
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $x, $m);
foreach ($m[1] as $t) {
    if (preg_match('/Functionality|Usability|Reliability|Security|Performance|Very High|computed|4\.4|Unit testing/i', $t)) {
        echo $t . "\n";
    }
}
