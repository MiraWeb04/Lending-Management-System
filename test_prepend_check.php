<?php

echo 'PHP version: ' . PHP_VERSION . PHP_EOL;
echo 'Prepend loaded: ' . (defined('FRAMS_TEST_PREPEND_LOADED') ? 'YES' : 'NO') . PHP_EOL;

if (function_exists('lendingEnv')) {
    echo 'DB_NAME: ' . lendingEnv('DB_NAME', 'NOT_SET') . PHP_EOL;
} else {
    echo "lendingEnv function: NOT FOUND" . PHP_EOL;
}