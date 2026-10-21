<?php
namespace OpenLMS\Core;

final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        $config = require $file;
        if (!is_array($config)) {
            throw new \RuntimeException('Invalid configuration file.');
        }
        self::$data = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function all(): array
    {
        return self::$data;
    }
}
