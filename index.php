<?php
$reqUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if ($reqUri === '/index.php') {
    header('Location: /', true, 301);
    exit();
}

require_once __DIR__ . '/frontend/index.php';
