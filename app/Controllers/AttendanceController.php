<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;

final class AttendanceController
{
    use AttendanceMainTrait;
    use AttendanceWriteTrait;
    use AttendanceHelpersTrait;
}
