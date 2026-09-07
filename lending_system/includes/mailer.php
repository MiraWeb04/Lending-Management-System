<?php
/**
 * Minimal SMTP mailer for development (works with Mailpit on localhost:1025)
 * Supports unauthenticated delivery to a local SMTP server.
 */
function sendMailSMTP(string $to, string $subject, string $body, ?string $fromEmail = null, ?string $fromName = null): bool
{
    $cfg = require __DIR__ . '/mail_config.php';
    $host = $cfg['smtp_host'] ?? '127.0.0.1';
    $port = $cfg['smtp_port'] ?? 1025;
    $secure = $cfg['smtp_secure'] ?? '';
    $user = $cfg['smtp_user'] ?? null;
    $pass = $cfg['smtp_pass'] ?? null;
    $timeout = $cfg['smtp_timeout'] ?? 5;

    $fromEmail = $fromEmail ?: ($cfg['from_email'] ?? 'no-reply@localhost');
    $fromName = $fromName ?: ($cfg['from_name'] ?? 'App');

    $transport = ($secure === 'ssl') ? 'ssl' : 'tcp';
    $remote = sprintf('%s://%s:%d', $transport, $host, $port);
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout);
    if (!$fp) {
        error_log("SMTP connect failed: $errstr ($errno)");
        return false;
    }

    stream_set_timeout($fp, $timeout);

    $read = function() use ($fp) {
        $data = '';
        while (($str = fgets($fp, 515)) !== false) {
            $data .= $str;
            if (strlen($str) >= 4 && substr($str, 3, 1) === ' ') break;
        }
        return $data;
    };

    $send = function($cmd) use ($fp) {
        fwrite($fp, $cmd . "\r\n");
    };

    $greet = $read();
    $send("EHLO localhost"); $greet = $read();

    // STARTTLS if requested
    if ($secure === 'tls') {
        $send("STARTTLS"); $resp = $read();
        // enable crypto
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('Failed to enable TLS on SMTP connection');
            fclose($fp);
            return false;
        }
        // EHLO again after TLS
        $send("EHLO localhost"); $greet = $read();
    }

    // AUTH LOGIN if credentials provided
    if (!empty($user)) {
        $send('AUTH LOGIN'); $resp = $read();
        $send(base64_encode($user)); $resp = $read();
        $send(base64_encode($pass)); $resp = $read();
    }

    $send("MAIL FROM: <{$fromEmail}>"); $expect = $read();
    $send("RCPT TO: <{$to}>"); $expect = $read();
    $send("DATA"); $expect = $read();

    $headers = [];
    $headers[] = 'From: ' . ($fromName ? "{$fromName} <{$fromEmail}>" : $fromEmail);
    $headers[] = 'To: ' . $to;
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    fwrite($fp, $msg);
    $expect = $read();

    $send("QUIT");
    fclose($fp);
    return true;
}
