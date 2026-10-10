<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$path = $argv[1] ?? '';
$z = new ZipArchive();
$z->open($path);
$x = $z->getFromName('word/document.xml');
$z->close();
$start = strpos($x, '<w:t>Table 4.6</w:t>');
$end = strpos($x, '<w:t>Table 4.7</w:t>', $start);
$block = substr($x, $start, $end - $start);
preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $block, $m);
foreach ($m[1] as $t) {
    echo $t . "\n";
}
