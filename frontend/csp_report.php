<?php
/**
 * Content-Security-Policy Violation Reporting Endpoint.
 *
 * Receives violation reports from browsers in Report-Only mode.
 * Enforces:
 *   - Strict body size limit (max 10 KB)
 *   - Per-IP rate limiting (max 100 reports/hour)
 *   - Personal data sanitization (URLs stripped of query strings, IDs/phones/emails redacted)
 *   - Log rotation (rotates when csp_violations.log exceeds 1 MB, keeping 3 archives)
 */

// Respond cleanly to OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit();
}

// 1. Limit body size: reject requests claiming Content-Length > 10 KB
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 10240) {
    http_response_code(413); // Payload Too Large
    exit();
}

// Read at most 10240 bytes
$raw = file_get_contents('php://input', false, null, 0, 10240);
if (empty($raw)) {
    http_response_code(204);
    exit();
}

// 2. Per-IP Rate Limiting (max 100 reports per hour per IP)
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$ipHash = hash('sha256', $clientIp . 'bucuc_csp_salt_2026');
$cacheDir = __DIR__ . '/../backend/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$rateFile = $cacheDir . '/csp_rate_' . $ipHash . '.json';
$now = time();
$rate = ['count' => 0, 'reset' => $now + 3600];

if (is_file($rateFile)) {
    $existing = json_decode(@file_get_contents($rateFile), true);
    if (is_array($existing) && isset($existing['reset']) && $now < $existing['reset']) {
        $rate = $existing;
    }
}

if ($rate['count'] >= 100) {
    // Rate limit reached for this IP
    http_response_code(429); // Too Many Requests
    exit();
}

$rate['count']++;
@file_put_contents($rateFile, json_encode($rate), LOCK_EX);

// 3. Parse JSON violation report
$data = json_decode($raw, true);
if (!$data || !is_array($data)) {
    http_response_code(204);
    exit();
}

/**
 * Sanitizes URLs by stripping query strings and hash fragments to prevent leaking tokens or personal data.
 */
function sanitize_csp_url(?string $url): string
{
    if (empty($url)) return 'none';
    if ($url === 'inline' || $url === 'eval' || $url === 'data' || $url === 'blob') return $url;
    $parts = parse_url($url);
    if (!$parts) return 'invalid-url';
    $sanitized = '';
    if (isset($parts['scheme'])) $sanitized .= $parts['scheme'] . '://';
    if (isset($parts['host'])) $sanitized .= $parts['host'];
    if (isset($parts['port'])) $sanitized .= ':' . $parts['port'];
    if (isset($parts['path'])) $sanitized .= $parts['path'];
    return $sanitized ?: $url;
}

/**
 * Redacts any personal details (IDs, emails, phones) from violation texts.
 */
function sanitize_csp_text(string $text): string
{
    // Redact 8-digit university IDs
    $text = preg_replace('/\b\d{8}\b/', '[ID]', $text);
    // Redact phone numbers
    $text = preg_replace('/(\+?88)?01[3-9]\d{8}/', '[PHONE]', $text);
    // Redact email addresses
    $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $text);
    return $text;
}

$reports = [];

// Format 1: Standard CSP Level 2 Report {"csp-report": {...}}
if (isset($data['csp-report']) && is_array($data['csp-report'])) {
    $r = $data['csp-report'];
    $reports[] = [
        'directive' => sanitize_csp_text($r['effective-directive'] ?? ($r['violated-directive'] ?? 'unknown')),
        'blocked'   => sanitize_csp_text(sanitize_csp_url($r['blocked-uri'] ?? '')),
        'document'  => sanitize_csp_text(sanitize_csp_url($r['document-uri'] ?? '')),
        'line'      => isset($r['line-number']) ? (int) $r['line-number'] : null,
    ];
}
// Format 2: Reporting API array format [{ "type": "csp-violation", "body": {...} }]
elseif (isset($data[0]) && is_array($data[0])) {
    foreach ($data as $item) {
        $body = $item['body'] ?? $item;
        $reports[] = [
            'directive' => sanitize_csp_text($body['effectiveDirective'] ?? ($body['violatedDirective'] ?? 'unknown')),
            'blocked'   => sanitize_csp_text(sanitize_csp_url($body['blockedURL'] ?? '')),
            'document'  => sanitize_csp_text(sanitize_csp_url($body['documentURL'] ?? '')),
            'line'      => isset($body['lineNumber']) ? (int) $body['lineNumber'] : null,
        ];
    }
}

if (!empty($reports)) {
    $logDir = __DIR__ . '/../backend/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/csp_violations.log';

    // 4. Log rotation: rotate when log file exceeds 1 MB (1048576 bytes), keeping up to 3 archives
    if (is_file($logFile) && @filesize($logFile) > 1048576) {
        if (is_file($logFile . '.2')) @rename($logFile . '.2', $logFile . '.3');
        if (is_file($logFile . '.1')) @rename($logFile . '.1', $logFile . '.2');
        @rename($logFile, $logFile . '.1');
    }

    $ts = date('Y-m-d H:i:s');
    $lines = '';
    foreach ($reports as $rep) {
        $lineInfo = $rep['line'] !== null ? " | line: {$rep['line']}" : '';
        $lines .= "[$ts] directive: {$rep['directive']} | blocked: {$rep['blocked']} | document: {$rep['document']}{$lineInfo}" . PHP_EOL;
    }
    @file_put_contents($logFile, $lines, FILE_APPEND | LOCK_EX);
}

http_response_code(204);
exit();
