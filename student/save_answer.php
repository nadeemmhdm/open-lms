<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['attempt_id'])) {
    $attempt_id = $_POST['attempt_id'];
    $q_id = $_POST['question_id'];
    $opt = $_POST['option'] ?? null;
    $ans_text = $_POST['answer_text'] ?? null;

    // Security: Check if attempt is still ongoing
    $stmt = $pdo->prepare("SELECT status FROM student_exams WHERE id = ?");
    $stmt->execute([$attempt_id]);
    $status = $stmt->fetchColumn();

    if ($status == 'ongoing') {
        $stmt = $pdo->prepare("INSERT INTO student_answers (student_exam_id, question_id, selected_option, answer_text) 
                               VALUES (?, ?, ?, ?) 
                               ON DUPLICATE KEY UPDATE selected_option = ?, answer_text = ?");
        $stmt->execute([$attempt_id, $q_id, $opt, $ans_text, $opt, $ans_text]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Attempt already submitted or blocked']);
    }
}
?>