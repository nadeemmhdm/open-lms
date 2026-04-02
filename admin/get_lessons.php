<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    echo json_encode([]);
    exit;
}

$course_id = $_GET['course_id'] ?? 0;
if (!$course_id) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT id, title FROM lessons WHERE course_id = ? ORDER BY title ASC");
$stmt->execute([$course_id]);
echo json_encode($stmt->fetchAll());
?>
