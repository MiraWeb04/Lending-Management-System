<?php
/**
 * Borrower Notifications page for RJ and RR Finance Services
 */

require_once 'includes/auth_lending.php';

if (!isLoggedIn()) {
    header('Location: borrower_login_lending.php');
    exit;
}

require_once 'notifications_lending.php';
exit;
