<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not found'); }
$docxPath = $argv[1] ?? '';
$tableXmlPath = $argv[2] ?? '';

$tableBlock = file_get_contents($tableXmlPath);
$tableBlock = str_replace(
    'Summary of functional testing, to be completed by the researchers',
    'Summary of functional testing',
    $tableBlock
);

$tableBlock = preg_replace(
    '/(<w:t>Unit testing<\/w:t>.*?<w:t>)6(<\/w:t>.*?<w:t>)Pass(<\/w:t>.*?<w:t>)Pass(<\/w:t>.*?<w:t>From Table 4\.4<\/w:t>)/s',
    '${1}6${2}6${3}0${4}',
    $tableBlock,
    1,
    $u
);

$tableBlock = preg_replace(
    '/(<w:t>Integration testing<\/w:t>.*?<w:t>)7(<\/w:t>.*?<w:t>)Pass(<\/w:t>.*?<w:t>)Pass(<\/w:t>.*?<w:t>From Table 4\.5<\/w:t>)/s',
    '${1}7${2}7${3}0${4}',
    $tableBlock,
    1,
    $i
);

if (!$u || !$i) {
    fwrite(STDERR, "Could not patch unit/integration rows (u={$u}, i={$i})\n");
    exit(1);
}

$z = new ZipArchive();
$z->open($docxPath);
$doc = $z->getFromName('word/document.xml');
$start = strpos($doc, '<w:t>Table 4.6</w:t>');
$end = strpos($doc, '<w:t>Table 4.7</w:t>', $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "Table markers not found\n");
    exit(1);
}

$doc = substr($doc, 0, $start) . $tableBlock . substr($doc, $end);
$z->deleteName('word/document.xml');
$z->addFromString('word/document.xml', $doc);
$z->close();
echo "Table 4.6 fixed in {$docxPath}\n";
