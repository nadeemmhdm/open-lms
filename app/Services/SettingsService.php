<?php
namespace OpenLMS\Services;

use OpenLMS\Core\Database;

final class SettingsService
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) return self::$cache[$key];
        try {
            $st = Database::connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
            $st->execute([$key]);
            $value = $st->fetchColumn();
            if ($value === false) return self::$cache[$key] = $default;
            return self::$cache[$key] = $value;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return in_array(strtolower((string)$value), ['1','true','yes','on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int)self::get($key, (string)$default);
    }

    public static function set(string $key, ?string $value, bool $public = false): void
    {
        $pdo = Database::connection();
        $now = gmdate('Y-m-d H:i:s');
        $st = $pdo->prepare('SELECT id FROM app_settings WHERE setting_key=? LIMIT 1');
        $st->execute([$key]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id) {
            $pdo->prepare('UPDATE app_settings SET setting_value=?,is_public=?,updated_at=? WHERE id=?')
                ->execute([$value,$public?1:0,$now,$id]);
        } else {
            $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value,is_public,updated_at) VALUES (?,?,?,?)')
                ->execute([$key,$value,$public?1:0,$now]);
        }
        self::$cache[$key] = $value;
    }

    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) self::set((string)$key, $value === null ? null : (string)$value, false);
    }

    public static function clear(): void
    {
        self::$cache = [];
    }
}
