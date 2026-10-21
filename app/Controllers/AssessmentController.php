<?php
namespace OpenLMS\Controllers;

use OpenLMS\Core\Auth;
use OpenLMS\Core\Csrf;
use OpenLMS\Core\Database;
use OpenLMS\Policies\CoursePolicy;
use PDO;

final class AssessmentController
{
    private const TYPES=['mcq','multiple_select','true_false','fill_blank','short_answer','descriptive','matching'];
    use AssessmentQuestionBankTrait;
    use AssessmentQuizTrait;
    use AssessmentHelpersTrait;
}
