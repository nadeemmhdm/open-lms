<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use OpenLMS\Services\AssessmentEngine;

final class ExamController
{
    use ExamManageTrait;
    use ExamAttemptTrait;
    use ExamReviewTrait;
}
