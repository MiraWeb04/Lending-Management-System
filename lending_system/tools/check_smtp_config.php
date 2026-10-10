<?php
require_once dirname(__DIR__) . '/includes/tools_web_guard.php';
$c = require dirname(__DIR__) . '/includes/mail_config.php';
$u = trim((string)($c['smtp_user'] ?? ''));
$p = trim((string)($c['smtp_pass'] ?? ''));
$from = trim((string)($c['from_email'] ?? ''));
echo 'Host: ' . ($c['smtp_host'] ?? '') . PHP_EOL;
echo 'Port: ' . ($c['smtp_port'] ?? '') . PHP_EOL;
echo 'Encryption: ' . ($c['smtp_secure'] ?? '') . PHP_EOL;
echo 'User set: ' . ($u !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'Password set: ' . ($p !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'From email set: ' . ($from !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'Ready: ' . ((($c['smtp_host'] ?? '') === 'smtp.gmail.com' && $u !== '' && $p !== '') ? 'yes' : 'no') . PHP_EOL;
