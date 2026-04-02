<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    echo json_encode(['error' => 'Permission denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$exam_id = (int)($input['exam_id'] ?? 0);
$questions = $input['questions'] ?? [];

if (!$exam_id || empty($questions)) {
    echo json_encode(['error' => 'Invalid data provided for saving.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $sql = "INSERT INTO exam_questions (exam_id, question_text, question_type, marks, option_a, option_b, option_c, option_d, correct_option) VALUES (?,?,?,?,?,?,?,?,?)";
    $stmt = $pdo->prepare($sql);

    foreach ($questions as $q) {
        $text = $q['question'];
        $type = $q['type'] ?: 'mcq';
        $marks = 1; // Default
        
        $a = $b = $c = $d = $ans = null;
        if ($type === 'mcq') {
            $a = $q['a'] ?? '';
            $b = $q['b'] ?? '';
            $c = $q['c'] ?? '';
            $d = $q['d'] ?? '';
            $ans = $q['answer'] ?? 'a';
        }

        $stmt->execute([$exam_id, $text, $type, $marks, $a, $b, $c, $d, $ans]);
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
