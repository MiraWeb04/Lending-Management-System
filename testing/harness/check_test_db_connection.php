<?php

require __DIR__ . '/../../lending_system/includes/db_lending.php';

echo 'Connected DB: ' . $conn->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;