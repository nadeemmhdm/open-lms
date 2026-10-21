<?php
namespace OpenLMS\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }
    public static function validate(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }
    public static function rotate(): void { $_SESSION['_csrf'] = bin2hex(random_bytes(32)); }
}
