<?php
// Mail configuration for SMTP (Mailpit default)
return [
    'smtp_host' => '127.0.0.1',
    'smtp_port' => 1025,
    'smtp_secure' => '', // '', 'ssl', or 'tls' if needed
    'smtp_user' => null, // set for authenticated SMTP
    'smtp_pass' => null,
    'smtp_timeout' => 5,
    'from_email' => 'no-reply@lending.local',
    'from_name' => 'Lending System',
    // For testing convenience keep showing reset link on screen when true.
    // Set to false in production.
    'show_reset_link' => false,
];
