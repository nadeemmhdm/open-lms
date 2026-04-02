<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['attempt_id'])) {
    $attempt_id = $_POST['attempt_id'];

    $stmt = $pdo->prepare("UPDATE student_exams SET violations_count = violations_count + 1 WHERE id = ?");
    $stmt->execute([$attempt_id]);

    $stmt = $pdo->prepare("SELECT violations_count FROM student_exams WHERE id = ?");
    $stmt->execute([$attempt_id]);
    $count = $stmt->fetchColumn();

    if ($count >= 3) {
        $pdo->prepare("UPDATE student_exams SET status = 'blocked', submit_time = NOW() WHERE id = ?")->execute([$attempt_id]);
        echo json_encode(['status' => 'blocked']);
    } else {
        echo json_encode(['status' => 'warning', 'count' => $count]);
    }
}
?>