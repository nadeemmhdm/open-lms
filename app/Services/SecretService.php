<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;

final class SecretService
{
    private const PREFIX = 'enc:v1:';

    public static function encrypt(string $plain): string
    {
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) throw new \RuntimeException('OpenSSL is required to protect stored secrets.');
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'open-lms-settings', 16);
        if ($cipher === false) throw new \RuntimeException('Unable to encrypt secret.');
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $encoded): string
    {
        $encoded = (string)$encoded;
        if ($encoded === '') return '';
        if (!str_starts_with($encoded, self::PREFIX)) return '';
        $raw = base64_decode(substr($encoded, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'open-lms-settings');
        return $plain === false ? '' : $plain;
    }

    public static function isConfigured(?string $encoded): bool
    {
        return str_starts_with((string)$encoded, self::PREFIX) && self::decrypt($encoded) !== '';
    }

    private static function key(): string
    {
        $appKey = (string)Config::get('app.key', '');
        if ($appKey === '') throw new \RuntimeException('Application key is unavailable.');
        return hash('sha256', $appKey . '|settings-secrets', true);
    }
}
