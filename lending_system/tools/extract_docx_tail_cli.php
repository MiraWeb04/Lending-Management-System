<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$path = $argv[1] ?? '';
$len = (int)($argv[2] ?? 12000);
if ($path === '' || !is_file($path)) {
    exit(1);
}
$z = new ZipArchive();
$z->open($path);
$xml = $z->getFromName('word/document.xml');
$z->close();
$text = preg_replace('/\s+/u', ' ', strip_tags($xml));
echo mb_substr($text, max(0, mb_strlen($text) - $len)) . "\n";
