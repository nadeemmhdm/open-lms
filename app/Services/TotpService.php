<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Config;
use OpenLMS\Core\Database;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(16, $bytes)));
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function generateCode(string $secret, ?int $time = null, int $digits = 6): string
    {
        $time ??= time();
        $counter = intdiv($time, 30);
        $key = self::base32Decode($secret);
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        $mod = 10 ** $digits;
        return str_pad((string)($binary % $mod), $digits, '0', STR_PAD_LEFT);
    }

    public static function verifyCode(string $secret, string $code, int $window = 1, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6) return null;
        $step = intdiv(time(), 30);
        for ($delta = -$window; $delta <= $window; $delta++) {
            $candidateStep = $step + $delta;
            if ($lastUsedStep !== null && $candidateStep <= $lastUsedStep) continue;
            $candidate = self::generateCode($secret, $candidateStep * 30, 6);
            if (hash_equals($candidate, $code)) return $candidateStep;
        }
        return null;
    }

    public static function enableForUser(int $userId, string $secret): array
    {
        $codes = self::newRecoveryCodes();
        $hashes = array_map(fn($code) => password_hash($code, PASSWORD_DEFAULT), $codes);
        $encrypted = self::encrypt($secret);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM mfa_credentials WHERE user_id=?');
        $stmt->execute([$userId]);
        $now = gmdate('Y-m-d H:i:s');
        if ($stmt->fetchColumn()) {
            $pdo->prepare('UPDATE mfa_credentials SET encrypted_secret=?,recovery_codes_json=?,last_used_step=NULL,enabled_at=?,updated_at=? WHERE user_id=?')
                ->execute([$encrypted, json_encode($hashes), $now, $now, $userId]);
        } else {
            $pdo->prepare('INSERT INTO mfa_credentials (user_id,encrypted_secret,recovery_codes_json,last_used_step,enabled_at,updated_at) VALUES (?,?,?,NULL,?,?)')
                ->execute([$userId, $encrypted, json_encode($hashes), $now, $now]);
        }
        return $codes;
    }

    public static function isEnabled(int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM mfa_credentials WHERE user_id=? AND enabled_at IS NOT NULL');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function verifyForUser(int $userId, string $input): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM mfa_credentials WHERE user_id=? AND enabled_at IS NOT NULL LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        $secret = self::decrypt((string)$row['encrypted_secret']);
        $step = self::verifyCode($secret, $input, 1, $row['last_used_step'] !== null ? (int)$row['last_used_step'] : null);
        if ($step !== null) {
            $pdo->prepare('UPDATE mfa_credentials SET last_used_step=?,updated_at=? WHERE user_id=?')->execute([$step, gmdate('Y-m-d H:i:s'), $userId]);
            return true;
        }
        $hashes = json_decode((string)($row['recovery_codes_json'] ?? '[]'), true);
        if (!is_array($hashes)) $hashes = [];
        $normalized = strtoupper(trim($input));
        foreach ($hashes as $i => $hash) {
            if (is_string($hash) && password_verify($normalized, $hash)) {
                unset($hashes[$i]);
                $pdo->prepare('UPDATE mfa_credentials SET recovery_codes_json=?,updated_at=? WHERE user_id=?')
                    ->execute([json_encode(array_values($hashes)), gmdate('Y-m-d H:i:s'), $userId]);
                return true;
            }
        }
        return false;
    }

    public static function disableForUser(int $userId): void
    {
        Database::connection()->prepare('DELETE FROM mfa_credentials WHERE user_id=?')->execute([$userId]);
    }

    private static function newRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $hex = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
        }
        return $codes;
    }

    private static function encrypt(string $plaintext): string
    {
        if (!function_exists('openssl_encrypt')) throw new \RuntimeException('OpenSSL is required for MFA secret encryption.');
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) throw new \RuntimeException('Unable to encrypt MFA secret.');
        return base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) throw new \RuntimeException('Invalid encrypted MFA secret.');
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new \RuntimeException('Unable to decrypt MFA secret.');
        return $plain;
    }

    private static function encryptionKey(): string
    {
        $key = (string)Config::get('app.key', '');
        if (preg_match('/^[a-f0-9]{64}$/i', $key)) return hex2bin($key) ?: hash('sha256', $key, true);
        return hash('sha256', $key, true);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    private static function base32Decode(string $input): string
    {
        $input = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $input) ?? '');
        $bits = '';
        foreach (str_split($input) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) throw new \InvalidArgumentException('Invalid Base32 secret.');
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }
        return $out;
    }
}
