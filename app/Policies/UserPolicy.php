<?php
namespace OpenLMS\Policies;
final class UserPolicy { public static function canSuspend(array $actor,array $targetRoles): bool { return (bool)array_intersect(['super_admin','administrator'],$actor['roles'] ?? []) && !in_array('super_admin',$targetRoles,true); } }
