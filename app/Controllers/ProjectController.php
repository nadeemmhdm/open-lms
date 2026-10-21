<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\UploadService;

final class ProjectController
{
    use ProjectListTrait;
    use ProjectViewTrait;
    use ProjectActionsTrait;
    use ProjectHelpersTrait;
}
