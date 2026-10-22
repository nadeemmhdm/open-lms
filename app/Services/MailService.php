<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;

final class MailService
{
    public static function enabled(): bool
    {
        return SettingsService::bool('smtp_enabled', false)
            && trim((string)SettingsService::get('smtp_host', '')) !== ''
            && filter_var((string)SettingsService::get('smtp_from_email', ''), FILTER_VALIDATE_EMAIL);
    }

    public static function loginAlertsEnabled(): bool
    {
        return self::enabled() && SettingsService::bool('mail_login_alerts_enabled', false);
    }

    public static function announcementMailEnabled(): bool
    {
        return self::enabled() && SettingsService::bool('mail_announcements_enabled', false);
    }

    public static function test(string $recipient): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Enter a valid test recipient email.');
        self::send($recipient, 'Open LMS SMTP test', "SMTP is connected successfully.\n\nThis message was sent from the Open LMS mail settings page.");
    }

    public static function sendLoginAlert(array $user, array $login): void
    {
        if (!self::loginAlertsEnabled()) return;
        $email = (string)($user['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        $name = (string)($user['name'] ?? 'User');
        $time = function_exists('format_app_datetime') ? format_app_datetime((string)($login['login_at'] ?? gmdate('Y-m-d H:i:s')), 'Y-m-d H:i:s T') : (string)($login['login_at'] ?? '');
        $body = "Hello {$name},\n\nA sign-in to your Open LMS account was recorded.\n\n"
            . 'Time: ' . $time . "\n"
            . 'IP address: ' . (string)($login['ip_address'] ?? 'Unknown') . "\n"
            . 'Device: ' . (string)($login['device_label'] ?? 'Unknown device') . "\n\n"
            . "If this was not you, contact your LMS administrator and change your password.\n";
        try { self::send($email, 'New sign-in to Open LMS', $body); }
        catch (\Throwable $e) { error_log('[OpenLMS mail login alert] ' . $e->getMessage()); }
    }

    /** @return array{sent:int,failed:int} */
    public static function sendMany(array $recipients, string $subject, string $body): array
    {
        $emails = [];
        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? (string)($recipient['email'] ?? '') : (string)$recipient;
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[strtolower($email)] = $email;
        }
        if (!$emails) return ['sent'=>0,'failed'=>0];
        if (!self::enabled()) throw new \RuntimeException('SMTP is not enabled or configured.');
        $socket = self::connect();
        $sent = $failed = 0;
        try {
            foreach ($emails as $email) {
                try { self::sendOnConnection($socket, $email, $subject, $body); $sent++; }
                catch (\Throwable $e) { $failed++; error_log('[OpenLMS SMTP recipient ' . $email . '] ' . $e->getMessage()); try { self::command($socket, "RSET\r\n", [250]); } catch (\Throwable) {} }
            }
            try { self::command($socket, "QUIT\r\n", [221]); } catch (\Throwable) {}
        } finally { if (is_resource($socket)) fclose($socket); }
        return ['sent'=>$sent,'failed'=>$failed];
    }

    public static function send(string $to, string $subject, string $body): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Recipient email is invalid.');
        if (!self::enabled()) throw new \RuntimeException('SMTP is not enabled or configured.');
        $socket = self::connect();
        try {
            self::sendOnConnection($socket, $to, $subject, $body);
            try { self::command($socket, "QUIT\r\n", [221]); } catch (\Throwable) {}
        } finally { if (is_resource($socket)) fclose($socket); }
    }

    private static function connect()
    {
        $host = trim((string)SettingsService::get('smtp_host', ''));
        $port = max(1, min(65535, SettingsService::int('smtp_port', 587)));
        $encryption = strtolower((string)SettingsService::get('smtp_encryption', 'tls'));
        if (!in_array($encryption, ['tls','ssl','none'], true)) $encryption = 'tls';
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) throw new \RuntimeException('SMTP host is invalid.');

        $context = stream_context_create(['ssl'=>[
            'verify_peer'=>true,
            'verify_peer_name'=>true,
            'peer_name'=>$host,
            'SNI_enabled'=>true,
        ]]);
        $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client($target, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) throw new \RuntimeException('Unable to connect to SMTP server: ' . ($errstr ?: 'connection failed'));
        stream_set_timeout($socket, 12);
        self::expect($socket, [220]);
        $ehlo = self::ehloName();
        self::command($socket, "EHLO {$ehlo}\r\n", [250]);
        if ($encryption === 'tls') {
            self::command($socket, "STARTTLS\r\n", [220]);
            $ok = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) throw new \RuntimeException('SMTP STARTTLS negotiation failed.');
            self::command($socket, "EHLO {$ehlo}\r\n", [250]);
        }

        $username = trim((string)SettingsService::get('smtp_username', ''));
        if ($username !== '') {
            $password = SecretService::decrypt((string)SettingsService::get('smtp_password_secret', ''));
            if ($password === '') throw new \RuntimeException('SMTP password/app password is not configured.');
            self::command($socket, "AUTH LOGIN\r\n", [334]);
            self::command($socket, base64_encode($username) . "\r\n", [334]);
            self::command($socket, base64_encode($password) . "\r\n", [235]);
        }
        return $socket;
    }

    private static function sendOnConnection($socket, string $to, string $subject, string $body): void
    {
        $fromEmail = trim((string)SettingsService::get('smtp_from_email', ''));
        $fromName = trim((string)SettingsService::get('smtp_from_name', Config::get('app.name', 'Open LMS')));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('SMTP From email is invalid.');
        $subject = preg_replace('/[\r\n]+/', ' ', trim($subject)) ?? 'Open LMS';
        $fromName = preg_replace('/[\r\n]+/', ' ', $fromName) ?? 'Open LMS';
        $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8') : $subject;
        $encodedFrom = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($fromName, 'UTF-8') : $fromName;

        self::command($socket, 'MAIL FROM:<' . $fromEmail . ">\r\n", [250]);
        self::command($socket, 'RCPT TO:<' . $to . ">\r\n", [250,251]);
        self::command($socket, "DATA\r\n", [354]);

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            'From: ' . $encodedFrom . ' <' . $fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Open LMS',
        ];
        $normalized = preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
        $normalized = preg_replace('/^\./m', '..', $normalized) ?? $normalized;
        fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . $normalized . "\r\n.\r\n");
        self::expect($socket, [250]);
    }

    private static function command($socket, string $command, array $expected): string
    {
        if (fwrite($socket, $command) === false) throw new \RuntimeException('SMTP connection write failed.');
        return self::expect($socket, $expected);
    }

    private static function expect($socket, array $expected): string
    {
        $response = '';
        while (($line = fgets($socket, 2048)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        if ($response === '') throw new \RuntimeException('SMTP server did not respond.');
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            $safe = trim(preg_replace('/[\r\n]+/', ' ', $response) ?? $response);
            throw new \RuntimeException('SMTP rejected the request (' . $code . '): ' . mb_substr($safe, 0, 220));
        }
        return $response;
    }

    private static function ehloName(): string
    {
        $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
        return preg_match('/^[A-Za-z0-9.-]+$/', $host) ? $host : 'localhost';
    }
}
