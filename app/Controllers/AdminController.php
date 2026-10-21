<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Services\TotpService;

final class AdminController
{
    use AdminUsersTrait;
    use AdminRolesTrait;
    use AdminUtilityTrait;
}
