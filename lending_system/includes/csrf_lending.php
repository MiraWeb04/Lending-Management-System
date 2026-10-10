<?php
/**
 * Session CSRF tokens for form POSTs.
 */

function csrfEnsureToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrfToken(): string
{
    return csrfEnsureToken();
}

function csrfField(): string
{
    $token = htmlspecialchars(csrfEnsureToken(), ENT_QUOTES, 'UTF-8');

    return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
}

function csrfValidate(?string $token = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $submitted = $token ?? ($_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['_csrf_token'] ?? '';

    return is_string($submitted) && is_string($expected) && $expected !== '' && hash_equals($expected, $submitted);
}

function csrfRequireValid(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!csrfValidate()) {
        http_response_code(419);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Session expired</title><link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet"></head><body><div class="container py-5"><div class="alert alert-warning"><h4>Invalid or expired session</h4><p>Please refresh the page and try again.</p><a class="btn btn-primary" href="javascript:history.back()">Go back</a></div></div></body></html>';
        exit;
    }
}
