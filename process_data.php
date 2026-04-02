<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'You must be logged in to use the AI assistant.']);
    exit;
}

$prompt = $_POST['prompt'] ?? '';

if (empty($prompt)) {
    // Check if it was sent as JSON fallback
    $input = json_decode(file_get_contents('php://input'), true);
    $prompt = $input['prompt'] ?? '';
}

if (empty($prompt)) {
    echo json_encode(['error' => 'Please enter a message.']);
    exit;
}

$ch = curl_init(OPENROUTER_URL);

$data = [
    "model" => AI_MODEL,
    "messages" => [
        ["role" => "system", "content" => "You are a helpful AI assistant for the 'Open LMS' platform. You can help students with their lessons, schedules, and general questions about coding and academics. Keep your answers professional but friendly."],
        ["role" => "user", "content" => $prompt]
    ]
];

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Safety for shared hosts
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . OPENROUTER_API_KEY,
    'Content-Type: application/json',
    'HTTP-Referer: http://lms.local',
    'X-OpenRouter-Title: Open LMS AI'
]);

$response = curl_exec($ch);
$http_header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'API Connection Fail: ' . $err]);
} elseif ($http_code !== 200) {
    $res_data = json_decode($response, true);
    $msg = $res_data['error']['message'] ?? "API Error (Code: $http_code)";
    echo json_encode(['error' => $msg]);
} else {
    $result = json_decode($response, true);
    if (isset($result['choices'][0]['message']['content'])) {
        echo json_encode(['reply' => $result['choices'][0]['message']['content']]);
    } else {
        echo json_encode(['error' => 'AI returned an empty response. Please re-type your query.']);
    }
}
?>