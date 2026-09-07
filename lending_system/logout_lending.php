<?php
/**
 * Logout Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

// Determine the logout redirect target before destroying the session
$redirectPage = 'login_lending.php';
if (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Borrower') === 0) {
    $redirectPage = 'borrower_login_lending.php';
}

// Log out the user
logout();

// Redirect to login page
header('Location: ' . $redirectPage);
exit;