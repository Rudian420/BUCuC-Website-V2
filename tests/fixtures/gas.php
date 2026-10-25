<?php
// Fake Google Apps Script: validates shared secret, records each call, and returns result.
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: [];

file_put_contents(__DIR__ . '/../runtime/gas_calls.jsonl', $raw . PHP_EOL, FILE_APPEND);

$expectedSecret = 'test-shared-webhook-secret-xyz';
$providedSecret = $data['secret'] ?? '';

if ($providedSecret !== $expectedSecret) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Invalid or missing secret']);
    exit();
}

if (($data['studentId'] ?? '') === '99991111' || !empty($data['simulate_failure'])) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Simulated Google Apps Script execution failure']);
    exit();
}

header('Content-Type: application/json');
echo json_encode([
    'success'       => true,
    'action'        => $data['action'] ?? '',
    'studentId'     => $data['studentId'] ?? '',
    'deleted'       => true,
    'sheetsChecked' => 3
]);
