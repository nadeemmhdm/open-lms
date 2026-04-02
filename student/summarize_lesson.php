<?php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['lesson_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$lesson_id = (int)$_POST['lesson_id'];

// Fetch Lesson Content
$stmt = $pdo->prepare("SELECT title, content FROM lessons WHERE id = ?");
$stmt->execute([$lesson_id]);
$lesson = $stmt->fetch();

if (!$lesson) {
    echo json_encode(['success' => false, 'message' => 'Lesson not found']);
    exit;
}

// Prepare Content for AI (Strip HTML tags)
$text_content = strip_tags($lesson['content']);
// Limit content length to avoid token issues (approx 4000 chars)
$text_content = substr($text_content, 0, 4000);

$prompt = "You are an expert educational assistant. Your goal is to provide a highly professional, structured, and beginner-friendly summary of the following lesson titled \"{$lesson['title']}\".

Please follow this exact structure:
1. A bold summary title.
2. A 'Core Concepts' section using a Markdown Table to explain difficult terms simply.
3. An 'Actionable Takeaways' section with a numbered list.
4. A 'Bottom Line' concluding paragraph.

Use professional yet easy-to-understand language that a complete beginner would grasp.

Lesson Content:
$text_content";

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

// Handle SSL for shared hosting environments if needed
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['success' => false, 'message' => 'API Error: ' . $error]);
    exit;
}

$result = json_decode($response, true);

if ($http_code === 200 && isset($result['message']['content'])) {
    $summary = $result['message']['content'];
    // Return raw markdown for frontend rendering
    echo json_encode(['success' => true, 'summary' => $summary]);
} else {
    $msg = $result['error'] ?? 'AI service unavailable. Please check your API key or connection.';
    echo json_encode(['success' => false, 'message' => $msg, 'raw' => $response]);
}
