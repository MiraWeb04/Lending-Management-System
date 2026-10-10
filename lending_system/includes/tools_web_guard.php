<?php
/**
 * Block casual web access to maintenance scripts in tools/.
 */

if (PHP_SAPI === 'cli') {
    return;
}

require_once __DIR__ . '/auth_lending.php';
requireStaffOrAdminOnlyForSensitiveTools();
