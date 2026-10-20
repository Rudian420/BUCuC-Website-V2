<?php
// Fake Apps Script: records each call and answers success.
file_put_contents(__DIR__ . '/../runtime/gas_calls.jsonl', file_get_contents('php://input') . PHP_EOL, FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['success' => true]);
