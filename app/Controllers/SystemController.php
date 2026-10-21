<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\BackupService;

final class SystemController
{
    use SystemNotificationsTrait;
    use SystemReportsCertificatesTrait;
    use SystemTimetableTrait;
    use SystemAdminToolsTrait;
    use SystemParentTrait;
}
