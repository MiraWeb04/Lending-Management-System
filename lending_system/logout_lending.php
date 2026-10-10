<?php
/**
 * Logout Page for Lending Management System
 */

// Include authentication functions
require_once 'includes/auth_lending.php';

$redirectPage = getLogoutRedirectUrl();

// Log out the user
logout();

// Redirect to login page
header('Location: ' . $redirectPage);
exit;