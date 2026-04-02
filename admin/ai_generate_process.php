<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    echo json_encode(['error' => 'Permission denied.']);
    exit;
}

$lesson_id = $_POST['lesson_id'] ?? 0;
$count = (int)($_POST['count'] ?? 1);
$type = $_POST['type'] ?? 'mcq';

if (!$lesson_id) {
    echo json_encode(['error' => 'Please select a lesson.']);
    exit;
}

// Fetch Lesson Content
$stmt = $pdo->prepare("SELECT content, title FROM lessons WHERE id = ?");
$stmt->execute([$lesson_id]);
$lesson = $stmt->fetch();

if (!$lesson || empty($lesson['content'])) {
    echo json_encode(['error' => 'This lesson has no textual content to analyze. Please add content first.']);
    exit;
}

// Prepare Prompt
$system_prompt = "You are an expert academic examiner. You will be provided with a lesson's content. Create a high-quality exam blueprint based exclusively on the provided text.";
$user_prompt = "Generate $count questions of type [$type] based on this lesson content: '" . strip_tags($lesson['content']) . "'. 

### IMPORTANT JSON FORMAT ###
Return ONLY a JSON array of objects. NO PREAMBLE. NO EXPLANATION.

If MCQ:
{ \"type\": \"mcq\", \"question\": \"...\", \"a\": \"...\", \"b\": \"...\", \"c\": \"...\", \"d\": \"...\", \"answer\": \"a/b/c/d\" }

If Descriptive:
{ \"type\": \"descriptive\", \"question\": \"...\", \"key_topics\": \"...\" }";

// OpenRouter cURL
$ch = curl_init(OPENROUTER_URL);
$data = [
    "model" => AI_MODEL,
    "messages" => [
        ["role" => "system", "content" => $system_prompt],
        ["role" => "user", "content" => $user_prompt]
    ],
    "temperature" => 0.7
];

curl_setopt_all($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: http://lms.local',
        'X-OpenRouter-Title: Open LMS Exam Gen'
    ]
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(['error' => 'AI Service connection fail. Code: ' . $http_code]);
    exit;
}

$result = json_decode($response, true);
$raw_content = $result['choices'][0]['message']['content'] ?? '';

// Clean the response (sometimes AI adds markdown blocks)
$clean_json = trim($raw_content);
if (str_contains($clean_json, '```')) {
    $clean_json = preg_replace('/^```(?:json)?|```$/m', '', $clean_json);
}
$clean_json = trim($clean_json);

$questions = json_decode($clean_json, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['error' => 'AI returned malformed data. Let\'s try once more.', 'raw' => $raw_content]);
} else {
    echo json_encode(['questions' => $questions]);
}

// Helper function
function curl_setopt_all($ch, $options) {
    foreach ($options as $opt => $val) {
        curl_setopt($ch, $opt, $val);
    }
}
?>
