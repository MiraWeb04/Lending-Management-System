<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$path = $argv[1] ?? '';
$needle = $argv[2] ?? 'To be computed';
$z = new ZipArchive();
$z->open($path);
$xml = $z->getFromName('word/document.xml');
$z->close();
$pos = strpos($xml, $needle);
if ($pos === false) {
    echo "Not in raw XML\n";
    exit(0);
}
echo substr($xml, max(0, $pos - 400), 1200);
