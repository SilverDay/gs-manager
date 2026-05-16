<?php

declare(strict_types=1);

namespace GsppManager\Service;

use RuntimeException;

/**
 * Minimal SMTP client using PHP streams.
 * Supports STARTTLS (port 587) and implicit TLS (port 465).
 * No external library required.
 */
class MailService
{
    /**
     * Send an email via the tenant's configured SMTP server.
     *
     * @param array $config  Keys: smtp_host, smtp_port, smtp_user, smtp_pass,
     *                       smtp_from, smtp_from_name, smtp_encryption (starttls|ssl|none)
     * @throws RuntimeException on connection or protocol failure
     */
    public static function send(array $config, string $to, string $subject, string $body): void
    {
        $host       = $config['smtp_host'] ?? '';
        $port       = (int) ($config['smtp_port'] ?? 587);
        $user       = $config['smtp_user'] ?? '';
        $pass       = $config['smtp_pass'] ?? '';
        $from       = $config['smtp_from'] ?? '';
        $fromName   = $config['smtp_from_name'] ?? $from;
        $encryption = strtolower($config['smtp_encryption'] ?? 'starttls');

        if ($host === '' || $from === '') {
            throw new RuntimeException('SMTP nicht konfiguriert.');
        }

        // Implicit TLS (port 465): connect via ssl://
        $connectHost = ($encryption === 'ssl') ? "ssl://{$host}" : $host;
        $timeout     = 10;

        $socket = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::expect($socket, '220');
            self::cmd($socket, "EHLO {$_SERVER['SERVER_NAME']}");
            $ehloResp = self::read($socket);

            // STARTTLS upgrade
            if ($encryption === 'starttls') {
                if (!str_contains($ehloResp, 'STARTTLS')) {
                    throw new RuntimeException('Server unterstützt kein STARTTLS.');
                }
                self::cmd($socket, 'STARTTLS');
                self::expect($socket, '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS-Upgrade fehlgeschlagen.');
                }
                // Re-send EHLO after TLS
                self::cmd($socket, "EHLO {$_SERVER['SERVER_NAME']}");
                self::read($socket);
            }

            // AUTH LOGIN
            if ($user !== '' && $pass !== '') {
                self::cmd($socket, 'AUTH LOGIN');
                self::expect($socket, '334');
                self::cmd($socket, base64_encode($user));
                self::expect($socket, '334');
                self::cmd($socket, base64_encode($pass));
                self::expect($socket, '235');
            }

            $fromEncoded = self::encodeHeader($fromName);
            $toEncoded   = $to; // recipients are plain email

            self::cmd($socket, "MAIL FROM:<{$from}>");
            self::expect($socket, '250');
            self::cmd($socket, "RCPT TO:<{$to}>");
            self::expect($socket, '250');
            self::cmd($socket, 'DATA');
            self::expect($socket, '354');

            $messageId = '<' . bin2hex(random_bytes(12)) . '@gsm>';
            $date      = date('r');
            $headers   = implode("\r\n", [
                "Date: {$date}",
                "From: {$fromEncoded} <{$from}>",
                "To: <{$to}>",
                "Subject: " . self::encodeHeader($subject),
                "Message-ID: {$messageId}",
                "MIME-Version: 1.0",
                "Content-Type: text/plain; charset=UTF-8",
                "Content-Transfer-Encoding: 8bit",
            ]);

            // Dot-stuff (RFC 5321 §4.5.2)
            $body = preg_replace('/^\./', '..', $body);
            $body = str_replace("\r\n.", "\r\n..", $body);

            fwrite($socket, $headers . "\r\n\r\n" . $body . "\r\n.\r\n");
            self::expect($socket, '250');
            self::cmd($socket, 'QUIT');
        } finally {
            fclose($socket);
        }
    }

    // ─── Private helpers ─────────────────────────────────────────

    private static function cmd($socket, string $command): void
    {
        fwrite($socket, $command . "\r\n");
    }

    /** Read all continuation lines until a final response (no dash after code). */
    private static function read($socket): string
    {
        $response = '';
        while (!feof($socket)) {
            $line      = fgets($socket, 1024);
            $response .= $line;
            // "250-..." is continuation; "250 ..." is final
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    private static function expect($socket, string $expectedCode): void
    {
        $response = self::read($socket);
        $code     = substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("SMTP: erwartet {$expectedCode}, erhalten: " . trim($response));
        }
    }

    /** RFC 2047 encoded-word for non-ASCII header values. */
    private static function encodeHeader(string $value): string
    {
        if (mb_detect_encoding($value, 'ASCII', true)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
