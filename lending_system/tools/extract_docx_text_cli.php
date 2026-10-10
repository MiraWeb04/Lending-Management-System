<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php extract_docx_text_cli.php <path.docx>\n");
    exit(1);
}

$z = new ZipArchive();
if ($z->open($path) !== true) {
    fwrite(STDERR, "Cannot open zip/docx\n");
    exit(1);
}

$xml = $z->getFromName('word/document.xml');
$z->close();
if ($xml === false) {
    fwrite(STDERR, "No document.xml\n");
    exit(1);
}

$text = strip_tags($xml);
$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$text = preg_replace('/\s+/u', ' ', $text);

foreach (['Table 4.1', 'Table 4.2', 'Table 4.3', 'Table 4.4', 'Table 4.5', 'Table 4.6'] as $label) {
    $pos = mb_strpos($text, $label);
    if ($pos === false) {
        continue;
    }
    echo "\n=== {$label} ===\n";
    echo mb_substr($text, max(0, $pos - 120), 1200) . "\n";
}

if (mb_strpos($text, 'Table 4.4') === false) {
    echo "\n(Table 4.4 not found in this file. All 'Table 4.x' hits:)\n";
    if (preg_match_all('/Table 4\.\d+/u', $text, $m)) {
        echo implode(', ', array_unique($m[0])) . "\n";
    }
}
