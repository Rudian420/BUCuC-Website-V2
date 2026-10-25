<?php
// Mock S3-compatible server endpoint for test suite (Backblaze B2 / Cloudflare R2 / AWS S3)

$runtimeDir = __DIR__ . '/../runtime';
$sizeFile = $runtimeDir . '/s3_mock_size.txt';
$mismatchFlag = $runtimeDir . '/s3_mock_mismatch.flag';
$hangFlag = $runtimeDir . '/s3_mock_hang.flag';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (file_exists($hangFlag)) {
    sleep(4);
    exit(0);
}

if ($method === 'PUT') {
    $body = file_get_contents('php://input');
    file_put_contents($sizeFile, (string) strlen($body));
    header('ETag: "mock-etag"');
    http_response_code(200);
    exit(0);
}

if ($method === 'HEAD') {
    $size = file_exists($sizeFile) ? (int) file_get_contents($sizeFile) : 0;
    if (file_exists($mismatchFlag)) {
        $size = 1234; // simulate mismatch
    }
    header('ETag: "mock-etag"');
    header('Content-Length: ' . $size);
    header('Content-Type: application/octet-stream');
    http_response_code(200);
    exit(0);
}

http_response_code(405);
echo "Method Not Allowed\n";
