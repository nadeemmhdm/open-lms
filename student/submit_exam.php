<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['attempt_id'])) {
    $attempt_id = $_POST['attempt_id'];
    $exam_id = $_POST['exam_id'];

    // 0. Ensure All Questions are Answered (Server-side safety)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?");
    $stmt->execute([$exam_id]);
    $total_required = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM student_answers WHERE student_exam_id = ? AND (selected_option IS NOT NULL OR (answer_text IS NOT NULL AND TRIM(answer_text) != ''))");
    $stmt->execute([$attempt_id]);
    $total_answered = $stmt->fetchColumn();

    if ($total_answered < $total_required) {
        die("You must answer all questions before submitting.");
    }

    // 1. Calculate Score
    $stmt = $pdo->prepare("SELECT q.id, q.correct_option, sa.selected_option 
                           FROM exam_questions q 
                           LEFT JOIN student_answers sa ON q.id = sa.question_id AND sa.student_exam_id = ?
                           WHERE q.exam_id = ?");
    $stmt->execute([$attempt_id, $exam_id]);
    $results = $stmt->fetchAll();

    $score = 0;
    foreach ($results as $res) {
        if ($res['correct_option'] === $res['selected_option']) {
            $score++;
        }
    }

    // 2. Finalize Attempt
    $total_q = count($results);
    // Percentage score or raw score? Let's store raw score.

    $stmt = $pdo->prepare("UPDATE student_exams SET status = 'submitted', submit_time = NOW(), score = ? WHERE id = ?");
    $stmt->execute([$score, $attempt_id]);

    // Cleanup session if needed (though not using session for attempt tracking)

    header("Location: exams.php?success=submitted");
    exit();
}
?>