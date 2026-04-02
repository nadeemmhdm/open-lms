<?php
$path_to_root = '../';
require_once $path_to_root . 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'sub_admin')) {
    echo json_encode(['error' => 'Permission denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // Fallback to standard POST for 403 bypass consistency
    $input = $_POST;
}

$question = $input['question'] ?? '';
$student_answer = $input['answer'] ?? '';
$max_marks = (float)($input['max_marks'] ?? 1);

if (empty($question) || empty($student_answer)) {
    echo json_encode(['error' => 'Question prompt or student answer is missing.']);
    exit;
}

// Prepare AI Prompt
$system_prompt = "You are an AI Grader for an academic LMS. Evaluate the student's descriptive answer against the question quality. Be fair but rigorous.";
$user_prompt = "Question: \"$question\"
Student Answer: \"$student_answer\"
Maximum Possible Marks: $max_marks

Evaluate this answer. Return your response in EXACTLY this JSON format:
{ \"score\": [float value between 0 and $max_marks], \"feedback\": \"[one short sentence justifying the grade]\" }";

// OpenRouter Request
$ch = curl_init(OPENROUTER_URL);
$data = [
    "model" => AI_MODEL,
    "messages" => [
        ["role" => "system", "content" => $system_prompt],
        ["role" => "user", "content" => $user_prompt]
    ],
    "temperature" => 0.5
];

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . OPENROUTER_API_KEY,
    'Content-Type: application/json',
    'HTTP-Referer: http://lms.local',
    'X-OpenRouter-Title: Open LMS AI Grader'
]);

$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'AI Connection Error: ' . $err]);
} else {
    $result = json_decode($response, true);
    $raw_content = $result['choices'][0]['message']['content'] ?? '';
    
    // Clean JSON (strip markdown)
    $clean_json = trim($raw_content);
    if (str_contains($clean_json, '```')) {
        $clean_json = preg_replace('/^```(?:json)?|```$/m', '', $clean_json);
    }
    
    $graded = json_decode(trim($clean_json), true);
    if (isset($graded['score'])) {
        echo json_encode($graded);
    } else {
        echo json_encode(['error' => 'AI Grader returned malformed response.', 'raw' => $raw_content]);
    }
}
?>
