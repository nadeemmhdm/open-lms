<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['message'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user_msg = trim($_POST['message']);
$lesson_id = (int)($_POST['lesson_id'] ?? 0);

// Fetch Lesson Context to make the AI smarter
$lesson_context = "";
if ($lesson_id > 0) {
    $stmt = $pdo->prepare("SELECT title, content FROM lessons WHERE id = ?");
    $stmt->execute([$lesson_id]);
    $lesson = $stmt->fetch();
    if ($lesson) {
        $lesson_context = "Context: This student is currently studying the lesson titled \"{$lesson['title']}\". 
        Lesson Content Summary: " . substr(strip_tags($lesson['content']), 0, 1000);
    }
}

$prompt = "You are a helpful, professional, and friendly AI Tutor. 
$lesson_context
The student is asking: \"$user_msg\"
Please provide a concise, encouraging, and clear answer. If the question is about the lesson, use the context provided. If it's general, be a great mentor.";

// Call Ollama API
$data = [
    'model' => OLLAMA_MODEL,
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
    'stream' => false
];

$ch = curl_init(OLLAMA_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . OLLAMA_API_KEY,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($http_code === 200 && isset($result['message']['content'])) {
    echo json_encode(['success' => true, 'reply' => $result['message']['content']]);
} else {
    echo json_encode(['success' => false, 'message' => 'AI Tutor is currently busy. Please try again.']);
}
