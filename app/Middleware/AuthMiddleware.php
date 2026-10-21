<?php
namespace OpenLMS\Middleware;
use OpenLMS\Core\Auth;
final class AuthMiddleware { public static function handle(bool $adminOnly=false): void { $adminOnly ? Auth::requireAdmin() : Auth::requireAuth(); } }
