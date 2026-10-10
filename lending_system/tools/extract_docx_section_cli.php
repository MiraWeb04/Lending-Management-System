<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$path = $argv[1] ?? '';
$needle = $argv[2] ?? 'User Acceptance';
$len = (int)($argv[3] ?? 4000);

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php extract_docx_section_cli.php <file.docx> [needle] [length]\n");
    exit(1);
}

$z = new ZipArchive();
if ($z->open($path) !== true) {
    exit(1);
}
$xml = $z->getFromName('word/document.xml');
$z->close();

$text = strip_tags($xml);
$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = preg_replace('/\s+/u', ' ', $text);

$pos = mb_stripos($text, $needle);
if ($pos === false) {
    echo "Needle not found: {$needle}\n";
    exit(0);
}

echo mb_substr($text, max(0, $pos - 150), $len) . "\n";
